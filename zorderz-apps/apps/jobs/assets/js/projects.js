/**
 * Zorderz Jobs - the Projects hub.
 *
 * Mine first, then everything the person may see; each card with its install chip (the date the install-date
 * resolver prints, or "Unscheduled" / "Unknown" - never a raw date). Tapping a card opens the Project: where it
 * stands, its jobs, its people and its material orders, with only the buttons the server said this person may use
 * (it checks every action again). /?zprj=<id>[&at=orders] opens a Project straight away. The list refreshes when the
 * app comes back to the front, so an appointment moved in the Scheduler shows its new day on the chip.
 *
 * @package Zorderz\Jobs
 */
(function () {
	'use strict';

	function cfg() { return window.zjobProjects || {}; }

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function post(action, data) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', cfg().nonce || '');
		Object.keys(data || {}).forEach(function (k) { body.append(k, data[k] == null ? '' : data[k]); });
		return fetch(cfg().ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json().catch(function () { return { success: false, data: { message: 'The server did not answer. Try again.' } }; }); })
			.catch(function () { return { success: false, data: { message: 'No connection. Try again.' } }; });
	}

	function drawIcons() {
		if (window.lucide && typeof window.lucide.createIcons === 'function') {
			try { window.lucide.createIcons(); } catch (e) { /* non-fatal */ }
		}
	}

	var STATUS = { open: 'Open', active: 'In progress', waiting: 'Waiting', completed: 'Completed', cancelled: 'Cancelled' };

	function chip(install) {
		var st = (install && install.status) || 'unknown';
		var line = (install && install.line) || 'Unknown';
		var time = install && install.time ? ' ' + install.time : '';
		var more = install && install.spread ? ' +1 more' : '';
		return '<span class="zprj-chip zprj-chip-' + esc(st) + '"><i data-lucide="calendar"></i>' + esc(line + time + more) + '</span>';
	}

	/* ---- list ------------------------------------------------------------ */
	var state = { view: 'mine', rows: [], open: '' };

	function loadList(root, quiet) {
		var list = root.querySelector('.zprj-list');
		if (!quiet) { list.innerHTML = '<div class="zprj-empty">Loading&hellip;</div>'; }
		return post('zdz_jobs_projects_list', { view: state.view }).then(function (res) {
			if (!res || !res.success) {
				if (!quiet) { list.innerHTML = '<div class="zprj-empty">Could not load projects.</div>'; }
				return;
			}
			state.rows = (res.data && res.data.rows) || [];
			renderList(root);
		});
	}

	function renderList(root) {
		var list = root.querySelector('.zprj-list');
		var q = (root.querySelector('.zprj-search').value || '').toLowerCase().trim();
		var rows = state.rows.filter(function (r) {
			if (!q) { return true; }
			return [r.customer, r.business, r.address, r.code].join(' ').toLowerCase().indexOf(q) !== -1;
		});
		if (!rows.length) {
			list.innerHTML = '<div class="zprj-empty">' + (state.view === 'mine'
				? 'Nothing of yours here yet. Try "All I can see".'
				: 'No projects to show.') + '</div>';
			return;
		}
		list.innerHTML = rows.map(function (r) {
			var orders = r.orders && r.orders.count
				? '<span class="zprj-meta">Materials: ' + parseInt(r.orders.placed, 10) + ' of ' + parseInt(r.orders.count, 10) + ' ordered</span>' : '';
			return '<button type="button" class="zprj-card" data-id="' + esc(r.id) + '">' +
				'<span class="zprj-card-top"><strong>' + esc(r.customer || r.business || r.code) + '</strong>' +
				'<span class="zprj-status">' + esc(STATUS[r.status] || r.status) + '</span></span>' +
				(r.address ? '<span class="zprj-addr">' + esc(r.address) + '</span>' : '') +
				'<span class="zprj-card-foot">' + chip(r.install) + '<span class="zprj-code">' + esc(r.code) + '</span>' + orders +
				((r.parts || []).length ? '<span class="zprj-meta">+' + r.parts.length + ' more estimate' + (r.parts.length === 1 ? '' : 's') + ' at this home</span>' : '') + '</span>' +
				'</button>';
		}).join('');
		drawIcons();
	}

	/* ---- detail ---------------------------------------------------------- */
	function openProject(root, id, at) {
		state.open = id;
		var det = root.querySelector('.zprj-detail');
		root.querySelector('.zprj-list').hidden = true;
		root.querySelector('.zprj-bar').hidden = true;
		det.hidden = false;
		det.innerHTML = '<div class="zprj-empty">Loading&hellip;</div>';
		post('zdz_jobs_project_detail', { project_id: id }).then(function (res) {
			if (!res || !res.success) {
				det.innerHTML = backBtn() + '<div class="zprj-empty">' + esc((res && res.data && res.data.message) || 'That project is not available.') + '</div>';
				return;
			}
			renderDetail(root, res.data);
			if (at === 'orders') {
				var o = det.querySelector('.zprj-orders');
				if (o && o.scrollIntoView) { o.scrollIntoView(); }
			}
		});
	}

	function closeProject(root) {
		state.open = '';
		root.querySelector('.zprj-detail').hidden = true;
		root.querySelector('.zprj-list').hidden = false;
		root.querySelector('.zprj-bar').hidden = false;
		loadList(root, true);
	}

	function backBtn() {
		return '<button type="button" class="zprj-back" data-act="back"><i data-lucide="chevron-left"></i>Projects</button>';
	}

	function renderDetail(root, d) {
		var p = d.project || {};
		var det = root.querySelector('.zprj-detail');
		var ro = !!cfg().readOnly;
		var html = backBtn() +
			'<div class="zprj-head"><h3>' + esc(p.customer_name || p.customer_business || p.code) + '</h3>' +
			(p.customer_address ? '<div class="zprj-addr">' + esc(p.customer_address) + '</div>' : '') +
			'<div class="zprj-head-meta"><span class="zprj-status">' + esc(STATUS[p.status] || p.status) + '</span>' + chip(d.install) +
			'<span class="zprj-code">' + esc(p.code) + '</span></div></div>';

		if (d.gate) {
			html += '<div class="zprj-gate ' + (d.gate.ok_to_order ? 'is-ok' : 'is-wait') + '">' +
				esc(d.gate.ok_to_order ? d.gate.chip : d.gate.message) + '</div>';
		}

		html += '<section class="zprj-sec"><h4>Jobs</h4>' + ((d.jobs || []).length ? (d.jobs || []).map(function (j) {
			return '<div class="zprj-row"><span>' + esc(j.kind) + '</span><span class="zprj-meta">' + esc(j.assignee) +
				' &middot; ' + esc(j.status.replace('_', ' ')) + (j.scheduled ? '' : ' &middot; not scheduled') + '</span></div>';
		}).join('') : '<div class="zprj-meta">No jobs you can open yet.</div>') + '</section>';

		html += '<section class="zprj-sec"><h4>People</h4>' + ((d.people || []).length ? (d.people || []).map(function (x) {
			return '<div class="zprj-row"><span>' + esc(x.label) + '</span><span class="zprj-meta">' + esc(x.name) + '</span></div>';
		}).join('') : '<div class="zprj-meta">Nobody confirmed yet.</div>') + '</section>';

		if (d.home && (d.home.parts || []).length) {
			html += '<section class="zprj-sec"><h4>This home\'s other estimates</h4><div class="zprj-actions">' + d.home.parts.map(function (x) {
				return '<button type="button" class="zprj-btn zprj-btn-ghost" data-act="part" data-part="' + esc(x.id) + '">' + esc(x.code || 'Project') + ' \u00b7 ' + esc(STATUS[x.status] || x.status) + '</button>';
			}).join('') + '</div></section>';
		}
		if (d.home && d.home.billing) { html += billingHtml(d.home.billing); }
		html += ordersHtml(d.orders, ro, p.id);
		det.innerHTML = html;
		det._data = d;
		var sec = det.querySelector('.zprj-orders');
		if (sec) {
			sec._v = d.orders;
			sec._done = function (v) { det._data.orders = v; };
		}
		drawIcons();
	}

	/* ---- material orders (a Project's, or the shop's own stock on the desk) ------------------------------------
	   One panel per section: <section class="zprj-orders" data-project="...">. The server decides every button; this
	   only draws them and sends what the person did. An open order may be edited (each field as the form defines it)
	   and saved against the revision it was opened at, so two people editing at once never overwrite each other. */
	var edits = {};

	/* The Project's invoices as the billing system kept them: sent, viewed, reminded, paid; for money viewers only. */
	function billingHtml(list) {
		if (!list.length) { return '<section class="zprj-sec"><h4>Billing</h4><div class="zprj-meta">No invoices on file yet.</div></section>'; }
		var money = function (v) { return v == null ? '' : Number(v).toFixed(2); };
		return '<section class="zprj-sec"><h4>Billing</h4>' + list.map(function (h) {
			var s = h.summary || {};
			var bits = [];
			if (s.sent_count) { bits.push('sent ' + s.sent_count + 'x, last ' + String(s.last_sent).slice(0, 10)); }
			if (s.viewed_count) { bits.push('viewed ' + String(s.last_viewed).slice(0, 10)); }
			if (s.reminders) { bits.push(s.reminders + ' reminder(s)'); }
			if (s.last_payment) { bits.push('last paid ' + s.last_payment); }
			if (!h.log) { bits.push('history not read yet'); }
			return '<div class="zprj-row"><span>' + esc(h.number ? '#' + h.number : 'Invoice') + ' \u00b7 ' + esc(h.status || '') + '</span><span class="zprj-meta">' +
				esc([h.amount != null ? 'total ' + money(h.amount) : '', h.due != null ? 'due ' + money(h.due) : ''].filter(Boolean).join(' \u00b7 ')) + '</span></div>' +
				(bits.length ? '<div class="zprj-meta">' + esc(bits.join(' \u00b7 ')) + '</div>' : '');
		}).join('') + '</section>';
	}

	function stepsHtml(o) {
		var s = o.steps || {};
		var first = o.stock ? 'Approved' : 'Sold';
		return '<div class="zprj-steps">' +
			'<span class="' + (s.first ? 'is-done' : '') + '">' + first + '</span>' +
			'<span class="' + (s.checked ? 'is-done' : '') + '">Checked</span>' +
			'<span class="' + (s.ordered ? 'is-done' : '') + '">Ordered</span></div>';
	}

	function shown(f, v) {
		if (v === '' || v == null) { return ''; }
		if (f.type === 'choice') { for (var i = 0; i < (f.options || []).length; i++) { if (String(f.options[i][0]) === String(v)) { return f.options[i][1]; } } }
		return String(v);
	}

	function fieldInput(f, v, list, i) {
		var a = ' data-list="' + list + '" data-i="' + i + '" data-k="' + esc(f.k) + '"';
		if (f.type === 'choice') {
			return '<select class="zprj-in"' + a + ' aria-label="' + esc(f.label) + '"><option value=""></option>' + (f.options || []).map(function (o) {
				return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(v || '') ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
			}).join('') + '</select>';
		}
		return '<input class="zprj-in" type="' + (f.type === 'number' ? 'number' : 'text') + '"' + a + ' value="' + esc(v || '') + '" placeholder="' + esc(f.label) + '"' +
			(f.max ? ' maxlength="' + parseInt(f.max, 10) + '"' : '') + (f.hint ? ' title="' + esc(f.hint) + '"' : '') + ' aria-label="' + esc(f.label) + '">';
	}

	function editorHtml(o) {
		var e = edits[o.id];
		var block = function (list, fields, word) {
			if (!(fields || []).length) { return ''; }
			return '<div class="zprj-ed-list"><div class="zprj-ed-h">' + esc(word) + '</div>' + (e[list] || []).map(function (r, i) {
				return '<div class="zprj-ed-row">' + fields.map(function (f) { return fieldInput(f, r[f.k], list, i); }).join('') +
					'<button type="button" class="zprj-btn zprj-btn-ghost" data-act="ed-del" data-list="' + list + '" data-i="' + i + '" data-order="' + o.id + '" aria-label="Remove">&times;</button></div>';
			}).join('') + '<button type="button" class="zprj-btn zprj-btn-ghost" data-act="ed-add" data-list="' + list + '" data-order="' + o.id + '">Add a line</button></div>';
		};
		return '<div class="zprj-editor" data-order="' + o.id + '">' + block('units', o.fields, 'Lines') + block('parts', o.part_fields, 'Parts') +
			'<label class="zprj-ed-notes">Notes<textarea class="zprj-in" data-list="notes" rows="2" maxlength="600">' + esc(e.notes || '') + '</textarea></label>' +
			'<div class="zprj-actions"><button type="button" class="zprj-btn zprj-btn-primary" data-act="save" data-order="' + o.id + '" data-rev="' + o.rev + '">Save</button>' +
			'<button type="button" class="zprj-btn zprj-btn-ghost" data-act="ed-cancel" data-order="' + o.id + '">Cancel</button></div></div>';
	}

	function ordersHtml(v, ro, pid) {
		if (!v) { return ''; }
		var html = '<section class="zprj-sec zprj-orders" data-project="' + esc(pid) + '"><h4>' + (v.stock ? 'Shop stock orders' : 'Material orders') + '</h4>';
		if (v.po) { html += '<div class="zprj-meta">This job\'s PO: <strong>' + esc(v.po) + '</strong></div>'; }
		html += (v.orders || []).map(function (o) {
			var rows = (o.body && o.body.units ? o.body.units : []).map(function (u) {
				return '<li>' + esc((o.fields || []).map(function (f) { return shown(f, u[f.k]); }).filter(Boolean).join(' · ')) + '</li>';
			}).join('') + (o.body && o.body.parts ? o.body.parts : []).map(function (u) {
				return '<li>' + esc((o.part_fields || []).map(function (f) { return shown(f, u[f.k]); }).filter(Boolean).join(' · ')) + '</li>';
			}).join('');
			var btns = '';
			var appr = o.approval ? '<div class="zprj-why">' + esc(o.approval.state === 'approved' ? 'Approved.' : (o.approval.state === 'declined' ? 'Not approved.' : 'Waiting for an approver.')) +
				(o.approval.why ? ' Reason: ' + esc(o.approval.why) : '') + (o.approval.answer ? ' Answer: ' + esc(o.approval.answer) : '') + '</div>' : '';
			if (!ro) {
				if (o.status === 'open' && o.can_edit && !edits[o.id]) { btns += '<button type="button" class="zprj-btn" data-act="edit" data-order="' + o.id + '">Edit</button>'; }
				if (o.can_confirm && o.can_confirm.seller) { btns += '<button type="button" class="zprj-btn" data-act="confirm" data-side="seller" data-order="' + o.id + '" data-rev="' + o.rev + '">Confirm as sold</button>'; }
				if (o.can_confirm && o.can_confirm.operator) { btns += '<button type="button" class="zprj-btn" data-act="confirm" data-side="operator" data-order="' + o.id + '" data-rev="' + o.rev + '">Confirm checked</button>'; }
				if (o.approval && o.status === 'open' && v.approver) {
					btns += '<button type="button" class="zprj-btn" data-act="approve" data-yes="1" data-order="' + o.id + '" data-rev="' + o.rev + '">Approve</button>' +
						'<button type="button" class="zprj-btn zprj-btn-ghost" data-act="approve" data-yes="" data-order="' + o.id + '" data-rev="' + o.rev + '">Decline</button>';
				}
				if (o.can_order) {
					btns += '<select class="zprj-via" data-order="' + o.id + '">' + Object.keys(cfg().via || {}).map(function (k) {
						return '<option value="' + esc(k) + '">' + esc(cfg().via[k]) + '</option>';
					}).join('') + '</select><button type="button" class="zprj-btn zprj-btn-primary" data-act="ordered" data-order="' + o.id + '" data-rev="' + o.rev + '">Mark ordered</button>';
				}
				if (o.status === 'ordered' && o.can_edit) { btns += '<button type="button" class="zprj-btn zprj-btn-ghost" data-act="undo" data-order="' + o.id + '">Undo ordered</button>'; }
				if (o.status === 'open' && o.can_edit) { btns += '<button type="button" class="zprj-btn zprj-btn-ghost" data-act="discard" data-order="' + o.id + '">Discard</button>'; }
				btns += paperBtns(o);
			}
			var why = o.status === 'open' && !o.can_order && o.order_why ? '<div class="zprj-why">' + esc(o.order_why) + '</div>' : '';
			return '<div class="zprj-order"><div class="zprj-order-top"><strong>' + esc(o.form_label) + '</strong>' +
				(o.po ? '<span class="zprj-code">' + esc(o.po) + '</span>' : '') + '</div>' + stepsHtml(o) + appr +
				(edits[o.id] && !ro ? editorHtml(o) : (rows ? '<ul class="zprj-units">' + rows + '</ul>' : '') + (o.body && o.body.notes ? '<div class="zprj-meta">' + esc(o.body.notes) + '</div>' : '')) +
				(o.diff_words ? '<div class="zprj-why">The two confirmations differ: ' + esc(o.diff_words) + '</div>' : '') +
				why + (btns ? '<div class="zprj-actions">' + btns + '</div>' : '') + '</div>';
		}).join('');
		if (!ro && v.can_start && (v.forms || []).length) {
			html += '<div class="zprj-start"><select class="zprj-form">' + v.forms.map(function (f) {
				return '<option value="' + esc(f.key) + '">' + esc(f.label) + '</option>';
			}).join('') + '</select>' + (v.stock ? '<input type="text" class="zprj-reason" maxlength="300" placeholder="Why the shop needs it">'
				: '<select class="zprj-kind">' + Object.keys(v.kinds || {}).map(function (k) {
					return '<option value="' + esc(k) + '">' + esc(v.kinds[k]) + '</option>';
				}).join('') + '</select>') + '<button type="button" class="zprj-btn" data-act="start">' + (v.stock ? 'Ask for stock' : 'Start an order') + '</button></div>';
		}
		return html + '<div class="zprj-msg" aria-live="polite"></div></section>';
	}

	/* The supplier's own form: a filled PDF (drawn here) and the filled spreadsheet (a download). Only for someone who
	   may act on the order; the server checks the go sign and the PO again. */
	function paperBtns(o) {
		if (!o.paper || (!o.paper.pdf && !o.paper.xlsx) || !(o.can_edit || o.status === 'ordered') || !window.ZJOBOrderPaper) { return ''; }
		var held = o.status === 'open' && (o.agreement || o.po_refusal);
		var off = held ? ' disabled title="' + esc(o.agreement || o.po_refusal) + '"' : '';
		var out = '';
		if (o.paper.pdf) { out += '<button type="button" class="zprj-btn zprj-btn-ghost" data-act="pdf" data-order="' + o.id + '"' + off + '>Form (PDF)</button>'; }
		if (o.paper.xlsx) {
			out += held ? '<button type="button" class="zprj-btn zprj-btn-ghost"' + off + '>Excel file</button>'
				: '<a class="zprj-btn zprj-btn-ghost" href="' + esc(window.ZJOBOrderPaper.xlsxUrl(o.id)) + '">Excel file</a>';
		}
		return out;
	}

	function makePdf(sec, el) {
		var msg = sec.querySelector('.zprj-msg');
		el.disabled = true;
		if (msg) { msg.textContent = 'Filling the form…'; }
		window.ZJOBOrderPaper.pdf(el.getAttribute('data-order')).then(function (r) {
			el.disabled = false;
			if (msg) {
				msg.innerHTML = '<a href="' + esc(r.url) + '" download="' + esc(r.name) + '" target="_blank" rel="noopener">Open ' + esc(r.name) + '</a>' +
					((r.problems || []).length ? '<ul class="zprj-problems">' + r.problems.map(function (p) { return '<li>' + esc(p) + '</li>'; }).join('') + '</ul>' : '');
			}
		}).catch(function (e) {
			el.disabled = false;
			if (msg) { msg.textContent = (e && e.message) || 'The form could not be filled.'; }
		});
	}

	function orderById(sec, id) {
		return ((sec._v && sec._v.orders) || []).filter(function (o) { return String(o.id) === String(id); })[0] || null;
	}

	function redrawOrders(sec, v) {
		var wrap = document.createElement('div');
		wrap.innerHTML = ordersHtml(v, !!cfg().readOnly, sec.getAttribute('data-project'));
		var fresh = wrap.firstChild;
		fresh._v = v;
		fresh._done = sec._done;
		sec.parentNode.replaceChild(fresh, sec);
		if (typeof fresh._done === 'function') { fresh._done(v); }
		drawIcons();
		return fresh;
	}

	/* Read the editor's inputs back into the edit buffer. */
	function readEditor(sec, id) {
		var e = edits[id];
		if (!e) { return; }
		sec.querySelectorAll('.zprj-editor[data-order="' + id + '"] .zprj-in').forEach(function (el) {
			var list = el.getAttribute('data-list');
			if (list === 'notes') { e.notes = el.value; return; }
			var i = parseInt(el.getAttribute('data-i'), 10);
			if (e[list] && e[list][i]) { e[list][i][el.getAttribute('data-k')] = el.value; }
		});
	}

	function orderAct(sec, el) {
		var act = el.getAttribute('data-act');
		var id = el.getAttribute('data-order') || '';
		var o = orderById(sec, id);
		if (act === 'pdf') { makePdf(sec, el); return; }
		if (act === 'edit' && o) {
			edits[id] = JSON.parse(JSON.stringify({ units: (o.body && o.body.units) || [], parts: (o.body && o.body.parts) || [], notes: (o.body && o.body.notes) || '', kind: (o.body && o.body.kind) || 'new' }));
			redrawOrders(sec, sec._v);
			return;
		}
		if (act === 'ed-cancel') { delete edits[id]; redrawOrders(sec, sec._v); return; }
		if (act === 'ed-add' || act === 'ed-del') {
			readEditor(sec, id);
			var list = el.getAttribute('data-list');
			if (act === 'ed-add') { edits[id][list].push({ k: 'n' + Date.now().toString(36) }); } else { edits[id][list].splice(parseInt(el.getAttribute('data-i'), 10), 1); }
			redrawOrders(sec, sec._v);
			return;
		}
		var data = { op: act, project_id: sec.getAttribute('data-project'), order_id: id, rev: el.getAttribute('data-rev') || (o ? o.rev : '') };
		if (act === 'confirm') { data.side = el.getAttribute('data-side'); }
		if (act === 'approve') { data.yes = el.getAttribute('data-yes') || ''; }
		if (act === 'ordered') {
			var via = sec.querySelector('.zprj-via[data-order="' + id + '"]');
			data.via = via ? via.value : '';
		}
		if (act === 'save') {
			readEditor(sec, id);
			data.body = JSON.stringify(edits[id]);
		}
		if (act === 'start') {
			data.form = (sec.querySelector('.zprj-form') || {}).value || 'materials';
			var reason = sec.querySelector('.zprj-reason');
			data.body = JSON.stringify({ kind: (sec.querySelector('.zprj-kind') || {}).value || 'new', notes: reason ? reason.value : '' });
		}
		el.disabled = true;
		post('zjob_order_act', data).then(function (res) {
			if (res && res.success) {
				if (act === 'save') { delete edits[id]; }
				redrawOrders(sec, res.data);
			} else {
				el.disabled = false;
				var msg = sec.querySelector('.zprj-msg');
				if (msg) { msg.textContent = (res && res.data && res.data.message) || 'That did not work. Try again.'; }
			}
		});
	}

	/* ---- the materials desk ------------------------------------------------------------------------------------
	   Shop-stock orders (the same order panel, on the shop's own "project"), supplier invoices matched to a job by the
	   PO printed on them (anything else waits, with the reason, for a person who names the job and says how they
	   know), our details as printed on supplier forms, and (administrators) who orders and how PO numbers read. */
	function lines5(name, list) {
		var out = '';
		for (var i = 0; i < 5; i++) {
			out += '<input type="text" class="zprj-in" maxlength="60" data-co="' + name + '" data-i="' + i + '" value="' + esc((list || [])[i] || '') + '" aria-label="' + esc(name + ' line ' + (i + 1)) + '">';
		}
		return out;
	}

	function deskHtml(d) {
		var html = '';
		if (d.stock) {
			d.stock.approver = !!d.approver;
			html += ordersHtml(d.stock, !!cfg().readOnly, 'stock');
		}
		if (d.invoices) {
			html += '<section class="zprj-sec zprj-inv"><h4>Supplier invoices</h4>' + (d.invoices.length ? '<ul class="zprj-units">' + d.invoices.map(function (v) {
				var head = esc(v.supplier) + ' ' + esc(v.number) + ' · ' + esc(v.date) + (v.amount != null ? ' · ' + esc(Number(v.amount).toFixed(2)) : '') + ' · PO ' + esc(v.po || 'none');
				if (v.state === 'flagged') {
					return '<li class="is-flagged">' + head + '<div class="zprj-why">' + esc(v.why || 'Not matched.') + '</div>' +
						'<div class="zprj-actions"><input type="text" class="zprj-in" data-inv-code="' + v.id + '" maxlength="40" placeholder="Project code">' +
						'<input type="text" class="zprj-in" data-inv-note="' + v.id + '" maxlength="250" placeholder="How you know">' +
						'<button type="button" class="zprj-btn" data-desk="match" data-id="' + v.id + '">Match</button></div></li>';
				}
				return '<li>' + head + ' <span class="zprj-meta">' + (v.how === 'hand' ? 'matched by hand' : 'matched by its PO') + '</span></li>';
			}).join('') + '</ul>' : '<div class="zprj-meta">No supplier invoices recorded yet.</div>') +
				'<div class="zprj-actions zprj-inv-add"><input type="text" class="zprj-in" data-inv="supplier" maxlength="40" placeholder="Supplier">' +
				'<input type="text" class="zprj-in" data-inv="number" maxlength="40" placeholder="Invoice number">' +
				'<input type="date" class="zprj-in" data-inv="date" aria-label="Invoice date">' +
				'<input type="text" class="zprj-in" data-inv="po" maxlength="48" placeholder="PO printed on it">' +
				'<input type="number" step="0.01" class="zprj-in" data-inv="amount" placeholder="Amount">' +
				'<button type="button" class="zprj-btn" data-desk="add">Record</button></div></section>';
		}
		if (d.company) {
			html += '<section class="zprj-sec zprj-co"><h4>Our details on supplier forms</h4>' +
				'<div class="zprj-co-cols"><div><div class="zprj-ed-h">Ship to</div>' + lines5('ship', d.company.ship) + '</div>' +
				'<div><div class="zprj-ed-h">Bill to</div>' + lines5('bill', d.company.same ? d.company.ship : d.company.bill) + '</div></div>' +
				'<label><input type="checkbox" data-co-same' + (d.company.same ? ' checked' : '') + '> Bill to is the same as ship to</label>' +
				(d.company.set ? '' : '<div class="zprj-meta">Not saved yet: these come from the business profile until someone saves them.</div>') +
				'<div class="zprj-actions"><button type="button" class="zprj-btn" data-desk="company">Save our details</button></div></section>';
		}
		if (d.admin && d.operators) {
			var pick = function (key, chosen) {
				return d.operators.candidates.map(function (c) {
					return '<label class="zprj-pick"><input type="checkbox" data-pick="' + key + '" value="' + c.id + '"' + (chosen.indexOf(c.id) !== -1 ? ' checked' : '') + '> ' + esc(c.name) + '</label>';
				}).join('');
			};
			html += '<section class="zprj-sec zprj-ops"><h4>Who checks and orders materials</h4>' + pick('ids', d.operators.ids) +
				'<h4>May sign off their own sales</h4>' + pick('self_ok', d.operators.self_ok) +
				'<h4>Approves shop-stock requests</h4>' + pick('approvers', d.operators.approvers) +
				'<div class="zprj-meta">With nobody named, administrators do each of these.</div>' +
				'<div class="zprj-actions"><button type="button" class="zprj-btn" data-desk="operators">Save</button></div></section>';
		}
		if (d.admin && d.po) {
			html += '<section class="zprj-sec zprj-po"><h4>Purchase order numbers</h4><div class="zprj-actions">' +
				'<input type="text" class="zprj-in" data-po="prefix" maxlength="12" value="' + esc(d.po.prefix) + '" aria-label="Prefix">' +
				'<select class="zprj-in" data-po="sep" aria-label="Separator">' + ['-', '_', ''].map(function (s) { return '<option value="' + s + '"' + (s === d.po.sep ? ' selected' : '') + '>' + (s || 'none') + '</option>'; }).join('') + '</select>' +
				'<input type="text" class="zprj-in" data-po="stock_prefix" maxlength="16" value="' + esc(d.po.stock_prefix) + '" aria-label="Shop stock word">' +
				'<label><input type="checkbox" data-po="drop_zeros"' + (d.po.drop_zeros ? ' checked' : '') + '> Drop leading zeros</label>' +
				'<button type="button" class="zprj-btn" data-desk="po">Save</button></div><div class="zprj-meta">An invoice numbered 01234 gives ' + esc(d.po.example) + '.</div></section>';
		}
		return html + '<div class="zprj-msg zprj-desk-msg" aria-live="polite"></div>';
	}

	function renderDesk(root, d) {
		var box = root.querySelector('.zprj-desk');
		box.innerHTML = deskHtml(d);
		box._data = d;
		var sec = box.querySelector('.zprj-orders');
		if (sec) {
			sec._v = d.stock;
			sec._done = function (v) { box._data.stock = v; v.approver = !!box._data.approver; };
		}
		drawIcons();
	}

	function loadDesk(root) {
		var box = root.querySelector('.zprj-desk');
		box.innerHTML = '<div class="zprj-empty">Loading&hellip;</div>';
		return post('zjob_materials_desk', { op: 'view' }).then(function (res) {
			if (!res || !res.success) {
				box.innerHTML = '<div class="zprj-empty">' + esc((res && res.data && res.data.message) || 'The desk could not be loaded.') + '</div>';
				return;
			}
			renderDesk(root, res.data);
		});
	}

	function deskAct(root, el) {
		var box = root.querySelector('.zprj-desk');
		var what = el.getAttribute('data-desk');
		var say = function (m) { var x = box.querySelector('.zprj-desk-msg'); if (x) { x.textContent = m; } };
		var val = function (sel) { var x = box.querySelector(sel); return x ? x.value : ''; };
		var req;
		if (what === 'match') {
			var id = el.getAttribute('data-id');
			req = post('zjob_supinv', { op: 'match', id: id, project_id: val('[data-inv-code="' + id + '"]'), note: val('[data-inv-note="' + id + '"]') });
		} else if (what === 'add') {
			req = post('zjob_supinv', { op: 'add', supplier: val('[data-inv="supplier"]'), number: val('[data-inv="number"]'), date: val('[data-inv="date"]'), po: val('[data-inv="po"]'), amount: val('[data-inv="amount"]') });
		} else if (what === 'company') {
			var c = { ship: [], bill: [], same: !!(box.querySelector('[data-co-same]') || {}).checked };
			box.querySelectorAll('[data-co]').forEach(function (x) { c[x.getAttribute('data-co')][+x.getAttribute('data-i')] = x.value; });
			req = post('zjob_order_paper', { op: 'company', company: JSON.stringify(c) });
		} else if (what === 'operators') {
			var picked = { ids: [], self_ok: [], approvers: [] };
			box.querySelectorAll('[data-pick]:checked').forEach(function (x) { picked[x.getAttribute('data-pick')].push(parseInt(x.value, 10)); });
			req = post('zjob_materials_desk', { op: 'operators', ids: JSON.stringify(picked.ids), self_ok: JSON.stringify(picked.self_ok), approvers: JSON.stringify(picked.approvers) });
		} else if (what === 'po') {
			req = post('zjob_materials_desk', { op: 'po', prefix: val('[data-po="prefix"]'), sep: val('[data-po="sep"]'), stock_prefix: val('[data-po="stock_prefix"]'), drop_zeros: (box.querySelector('[data-po="drop_zeros"]') || {}).checked ? '1' : '' });
		} else {
			return;
		}
		el.disabled = true;
		req.then(function (res) {
			el.disabled = false;
			if (!res || !res.success) { say((res && res.data && res.data.message) || 'That did not work. Try again.'); return; }
			loadDesk(root).then(function () { var x = root.querySelector('.zprj-desk-msg'); if (x) { x.textContent = 'Saved.'; } });
		});
	}

	/* ---- boot ------------------------------------------------------------ */
	function init() {
		var root = document.getElementById('zjob-projects');
		if (!root || root.dataset.zprjInited) { return; }
		root.dataset.zprjInited = '1';

		root.addEventListener('click', function (e) {
			var tab = e.target.closest('.zprj-tab');
			if (tab) {
				root.querySelectorAll('.zprj-tab').forEach(function (t) { t.classList.toggle('is-active', t === tab); });
				state.view = tab.getAttribute('data-view') || 'mine';
				var desk = state.view === 'desk';
				root.querySelector('.zprj-desk').hidden = !desk;
				root.querySelector('.zprj-list').hidden = desk;
				root.querySelector('.zprj-search').hidden = desk;
				if (desk) { loadDesk(root); } else { loadList(root); }
				return;
			}
			var card = e.target.closest('.zprj-card');
			if (card) { openProject(root, card.getAttribute('data-id'), ''); return; }
			var dk = e.target.closest('[data-desk]');
			if (dk) { deskAct(root, dk); return; }
			var act = e.target.closest('[data-act]');
			if (!act) { return; }
			if (act.getAttribute('data-act') === 'back') { closeProject(root); return; }
			if (act.getAttribute('data-act') === 'part') { openProject(root, act.getAttribute('data-part'), ''); return; }
			var sec = act.closest('.zprj-orders');
			if (sec) { orderAct(sec, act); }
		});
		root.querySelector('.zprj-search').addEventListener('input', function () { renderList(root); });

		// Back to the front: refresh, so a moved appointment shows its new day on the chips.
		document.addEventListener('visibilitychange', function () {
			if (document.visibilityState !== 'visible' || Object.keys(edits).length) { return; } // never under someone's typing
			if (state.view === 'desk') { return; }
			if (state.open) { openProject(root, state.open, ''); } else { loadList(root, true); }
		});

		var want = window.zjobOpenProject || null;
		if (!want) {
			try {
				var qs = new URLSearchParams(window.location.search);
				if (qs.get('zprj')) { want = { id: qs.get('zprj'), at: qs.get('at') || '' }; }
			} catch (e) { /* older browser: the list still opens */ }
		}
		loadList(root).then(function () {
			if (want && want.id) { openProject(root, String(want.id), want.at || ''); window.zjobOpenProject = null; }
		});
	}

	document.addEventListener('zdz_widgets_rendered', init);
	document.addEventListener('DOMContentLoaded', init);
	init();
})();
