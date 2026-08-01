/**
 * Zorderz Jobs - Estimates bridge (UI).
 *
 * Injects a "Send as job(s)" control into the Estimates app WITHOUT editing that
 * plugin - the same isolation approach we use to add the Jobs nav item. On a
 * saved estimate (History row) it opens a picker: choose line items, an assignee,
 * and a per-line component, then POSTs to the Jobs `create_from_estimate`
 * endpoint (which owns job creation + the CRM mirror + address/phone resolve).
 *
 * Data flow (all structured, no DOM scraping of line data):
 *   history row  --data-id-->  tsec_get_estimate_detail (TSEC's own AJAX)
 *                --lines+customer+ns_lead+ns_contact-->  zjob_create_from_estimate
 *
 * Requires window.zjob (localized by the Jobs app) and window.tsecWidget (TSEC's own
 * localized config, for its nonce). Degrades to a no-op if either is absent.
 *
 * @package Zorderz\Jobs
 * @since 1.7.0
 */
(function () {
	'use strict';

	var J = window.zjob || null;         // { ajaxurl, nonce, canHandoff }
	if (!J || !J.ajaxurl || !J.nonce) { return; }
	if (J.canHandoff !== '1' && J.canHandoff !== true && J.canHandoff !== 1) { return; }

	var COMPONENTS = (function () {
		var c = (J && J.components) ? J.components : { service: 'Service', other: 'Other' };
		return Object.keys(c).map(function (k) { return [k, c[k]]; });
	})();

	var STATUS_LABEL = { open: 'Open', in_progress: 'In progress', done: 'Done', cancelled: 'Cancelled' };

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	/* A repair/service line is treated as service work; the server re-classifies
	   via the Item Engine filter. */
	function guessComponent(text) {
		var t = (text || '').toLowerCase();
		if (/repair|service|rescreen|re-screen|fix/.test(t)) { return 'service'; }
		return 'other';
	}

	/* Stable line signature - MUST match ZJOB_Jobs::line_sig() in PHP. Used to
	   match a current estimate line to an already-created job (dedup) and to flag
	   orphans (a job whose source line no longer exists). */
	function lineSig(desc, sub, dims, qty) {
		var norm = function (s) { return String(s == null ? '' : s).toLowerCase().trim().replace(/\s+/g, ' '); };
		return (norm((desc || '') + ' ' + (sub || '')) + '||' + norm(dims) + '||q' + (parseInt(qty, 10) || 0)).slice(0, 191);
	}

	function tsecCfg() { return window.tsecWidget || null; }

	function jobsPost(action, data) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', J.nonce);
		Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
		return fetch(J.ajaxurl, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body
		}).then(function (r) { return r.json(); });
	}

	function tsecPost(action, data) {
		var T = tsecCfg();
		if (!T || !T.ajaxurl) { return Promise.reject(new Error('no tsec')); }
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', T.nonce || '');
		Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
		return fetch(T.ajaxurl, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body
		}).then(function (r) { return r.json(); });
	}

	/* The saved-estimate id currently being EDITED (captured from a history "Edit"
	   click). The review/edit view doesn't expose its id, so we remember it here -
	   and CLEAR it the moment a fresh parse / new estimate begins, so the review
	   "Send as job(s)" button never acts on the wrong (or an unsaved) estimate. */
	var lastEditId = '';

	/* ---- assignee roster (cached) ------------------------------------------ */
	var assigneesCache = null;
	function loadAssignees() {
		if (assigneesCache) { return Promise.resolve(assigneesCache); }
		return jobsPost('zjob_assignees', {}).then(function (res) {
			assigneesCache = (res && res.success && res.data && res.data.assignees) || [];
			return assigneesCache;
		}).catch(function () { return []; });
	}

	/* ---- inject the "Send as job(s)" button into history action rows -------- */
	function injectHistory() {
		var rows = document.querySelectorAll('.tsec-w-history-actions');
		for (var i = 0; i < rows.length; i++) {
			var actions = rows[i];
			if (actions.querySelector('.zjobx-send')) { continue; } // idempotent
			var idBtn = actions.querySelector('[data-id]');
			var id = idBtn ? idBtn.getAttribute('data-id') : '';
			if (!id) { continue; }
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'tsec-w-btn-small zjobx-send';
			b.setAttribute('data-id', id);
			b.innerHTML = '<span class="zjobx-send-ic">&rarr;</span> Send as job';
			(function (eid) {
				b.addEventListener('click', function (e) {
					e.preventDefault(); e.stopPropagation();
					openPicker(eid);
				});
			})(id);
			actions.appendChild(b);
		}
	}

	/* ---- review/edit view entry point (2C) --------------------------------- */
	/* Track which saved estimate is being edited, and blank it on a fresh parse. */
	function trackClicks(e) {
		var edit = e.target.closest ? e.target.closest('[class*="edit-history"], .tsec-w-edit-histo') : null;
		if (edit && edit.getAttribute('data-id')) { lastEditId = edit.getAttribute('data-id'); return; }
		// A new parse, a new-estimate tab, or start-over means "no saved estimate".
		if (e.target.closest && e.target.closest('#tsec-w-parse')) { lastEditId = ''; return; }
		var tabOrBtn = e.target.closest ? e.target.closest('.tsec-w-tab, button') : null;
		if (tabOrBtn && /new estimate|start over/i.test((tabOrBtn.textContent || ''))) { lastEditId = ''; }
	}

	/* Inject a "Send as job(s)" button next to the estimate's send control - ONLY while a
	   saved estimate is being edited (valid captured id). Otherwise remove any
	   stale button so a fresh parse never shows one. */
	function injectReview() {
		var create = document.getElementById('tsec-w-create');
		var host = create ? create.parentElement : null;
		var existing = host ? host.querySelector('.zjobx-send-review') : null;
		if (!create || create.offsetParent === null || !host || !lastEditId) {
			if (existing && existing.parentNode) { existing.parentNode.removeChild(existing); }
			return;
		}
		if (existing) { return; } // idempotent
		var eid = lastEditId;
		var b = document.createElement('button');
		b.type = 'button';
		b.className = 'tsec-w-btn zjobx-send-review';
		b.innerHTML = '<span class="zjobx-send-ic">&rarr;</span> Send as job(s)';
		b.addEventListener('click', function (e) {
			e.preventDefault(); e.stopPropagation();
			openPicker(eid);
		});
		create.insertAdjacentElement('afterend', b);
	}

	/* ---- picker modal ------------------------------------------------------ */
	function parseItems(d) {
		var items = d.items || d.items_json || d.line_items;
		if (typeof items === 'string') { try { items = JSON.parse(items); } catch (e) { items = []; } }
		if (!Array.isArray(items)) { items = []; }
		return items.map(function (it, i) {
			var desc = it.description || '';
			var sub = it.sub_description || '';
			var dims = it.dimensions || '';
			var qty = parseInt(it.quantity || it.qty || 0, 10) || 0;
			var comp = guessComponent(desc + ' ' + sub);
			var price = parseFloat(it.unit_price || it.price || 0) || 0;
			return {
				i: i, description: desc, sub: sub, dims: dims, qty: qty,
				price: price, component: comp,
				sig: lineSig(desc, sub, dims, qty),
				// default-check the handoff-likely lines (a real component, priced)
				checked: (comp !== 'other' && price > 0)
			};
		});
	}

	function compSelect(idx, selected) {
		var opts = COMPONENTS.map(function (c) {
			return '<option value="' + c[0] + '"' + (c[0] === selected ? ' selected' : '') + '>' + esc(c[1]) + '</option>';
		}).join('');
		return '<select class="zjobx-comp" data-idx="' + idx + '">' + opts + '</select>';
	}

	function closeModal() {
		var m = document.getElementById('zjobx-modal');
		if (m && m.parentNode) { m.parentNode.removeChild(m); }
		document.removeEventListener('keydown', onEsc);
	}
	function onEsc(e) { if (e.key === 'Escape') { closeModal(); } }

	function renderModal(d, items, assignees, rollup) {
		closeModal();
		var estNum = d.fb_estimate_num || d.estimate_number || '';
		var cust = d.customer_name || '';

		// Map already-created jobs by line signature (dedup + status badge + orphans).
		var jobs = (rollup && rollup.jobs) ? rollup.jobs : [];
		var sentBySig = {};
		jobs.forEach(function (jb) { if (jb.line_sig) { sentBySig[jb.line_sig] = jb; } });
		var currentSigs = {};
		items.forEach(function (it) { currentSigs[it.sig] = true; });
		var orphans = jobs.filter(function (jb) { return jb.line_sig && !currentSigs[jb.line_sig]; });

		var rowsHtml = items.map(function (it) {
			var sentJob = sentBySig[it.sig];
			var checked = it.checked && !sentJob; // never default-check a line already sent
			var label = esc(it.description) + (it.sub ? ' <span class="zjobx-sub">' + esc(it.sub) + '</span>' : '');
			var meta = [];
			if (it.dims) { meta.push(esc(it.dims)); }
			if (it.qty) { meta.push('&times;' + it.qty); }
			var sentBadge = sentJob
				? '<span class="zjobx-sent">Sent &middot; ' + esc(STATUS_LABEL[sentJob.status] || sentJob.status) + '</span>'
				: '';
			return '<tr class="zjobx-row' + (sentJob ? ' is-sent' : '') + '" data-idx="' + it.i + '">' +
				'<td class="zjobx-c-check"><input type="checkbox" class="zjobx-pick" data-idx="' + it.i + '"' + (checked ? ' checked' : '') + ' /></td>' +
				'<td class="zjobx-c-desc">' + label + sentBadge + (meta.length ? '<span class="zjobx-meta">' + meta.join(' &middot; ') + '</span>' : '') + '</td>' +
				'<td class="zjobx-c-comp">' + compSelect(it.i, it.component) + '</td>' +
				'</tr>';
		}).join('');

		var summaryHtml = '';
		if (rollup && rollup.total > 0) {
			var doneN = (rollup.counts && rollup.counts.done) || 0;
			var plural = rollup.total === 1 ? '' : 's';
			var bits = rollup.all_done
				? ('All ' + rollup.total + ' job' + plural + ' done')
				: (doneN + ' of ' + rollup.total + ' job' + plural + ' done');
			var orphanNote = orphans.length
				? ' &middot; <span class="zjobx-orphan">' + orphans.length + ' from changed/removed line' + (orphans.length === 1 ? '' : 's') + '</span>'
				: '';
			summaryHtml = '<div class="zjobx-rollup' + (rollup.all_done ? ' is-done' : '') + '">This estimate: ' + esc(bits) + orphanNote + '</div>';
		}

		var asnOpts = '<option value="">Select a specialist&hellip;</option>' + assignees.map(function (a) {
			return '<option value="' + parseInt(a.id, 10) + '">' + esc(a.name) + '</option>';
		}).join('');

		var html =
			'<div class="zjobx-overlay" id="zjobx-modal">' +
			'<div class="zjobx-dialog" role="dialog" aria-label="Send estimate lines as jobs">' +
				'<div class="zjobx-head">' +
					'<div class="zjobx-title">Send as job(s)</div>' +
					'<button type="button" class="zjobx-x" aria-label="Close">&times;</button>' +
				'</div>' +
				'<div class="zjobx-subhead">' + esc(cust) + (estNum ? ' &middot; estimate #' + esc(estNum) : '') + '</div>' +
				summaryHtml +
				'<div class="zjobx-body">' +
					'<table class="zjobx-table"><thead><tr>' +
						'<th></th><th>Line item</th><th>Job type</th>' +
					'</tr></thead><tbody>' + rowsHtml + '</tbody></table>' +
					'<div class="zjobx-field"><label>Assign to</label>' +
						'<select class="zjobx-assignee">' + asnOpts + '</select></div>' +
					'<div class="zjobx-field"><label>Note for the specialist <span class="zjobx-opt">(optional)</span></label>' +
						'<textarea class="zjobx-note" rows="2" placeholder="Access, timing, anything they need."></textarea></div>' +
				'</div>' +
				'<div class="zjobx-foot">' +
					'<span class="zjobx-status" aria-live="polite"></span>' +
					'<button type="button" class="zjobx-cancel">Cancel</button>' +
					'<button type="button" class="zjobx-send-go">Send jobs</button>' +
				'</div>' +
			'</div></div>';

		var wrap = document.createElement('div');
		wrap.innerHTML = html;
		var modal = wrap.firstChild;
		document.body.appendChild(modal);
		document.addEventListener('keydown', onEsc);

		modal.querySelector('.zjobx-x').addEventListener('click', closeModal);
		modal.querySelector('.zjobx-cancel').addEventListener('click', closeModal);
		modal.addEventListener('click', function (e) { if (e.target === modal) { closeModal(); } });
		modal.querySelector('.zjobx-send-go').addEventListener('click', function () {
			submit(d, items, modal);
		});
	}

	function setStatus(modal, msg, kind) {
		var el = modal.querySelector('.zjobx-status');
		if (el) { el.textContent = msg; el.className = 'zjobx-status' + (kind ? ' is-' + kind : ''); }
	}

	function submit(d, items, modal) {
		var assignee = modal.querySelector('.zjobx-assignee').value;
		if (!assignee) { setStatus(modal, 'Pick a specialist to send to.', 'warn'); return; }

		// Gather checked lines + their (possibly overridden) component.
		var picked = [];
		modal.querySelectorAll('.zjobx-pick').forEach(function (cb) {
			if (!cb.checked) { return; }
			var idx = parseInt(cb.getAttribute('data-idx'), 10);
			var it = items[idx];
			if (!it) { return; }
			var sel = modal.querySelector('.zjobx-comp[data-idx="' + idx + '"]');
			picked.push({
				description: it.description, sub_description: it.sub, dimensions: it.dims,
				quantity: it.qty, unit_price: it.price,
				component: sel ? sel.value : it.component,
				line_index: it.i
			});
		});
		if (!picked.length) { setStatus(modal, 'Check at least one line to send.', 'warn'); return; }

		var btn = modal.querySelector('.zjobx-send-go');
		btn.disabled = true;
		setStatus(modal, 'Sending...', 'busy');

		jobsPost('zjob_create_from_estimate', {
			assigned_user_id: assignee,
			context_note: modal.querySelector('.zjobx-note').value || '',
			customer_name: d.customer_name || '',
			ns_lead_id: d.ns_lead_id || '',
			ns_contact_id: d.ns_contact_id || '',
			estimate_id: d.id || '',
			estimate_num: d.fb_estimate_num || d.estimate_number || '',
			lines_json: JSON.stringify(picked)
		}).then(function (res) {
			btn.disabled = false;
			if (res && res.success) {
				var d2 = res.data || {};
				setStatus(modal, (d2.message || 'Sent.'), 'ok');
				setTimeout(closeModal, 1400);
			} else {
				setStatus(modal, humanErr(res && res.data && res.data.message), 'err');
			}
		}).catch(function () {
			btn.disabled = false;
			setStatus(modal, 'Network error - try again.', 'err');
		});
	}

	function humanErr(code) {
		var m = {
			not_permitted: 'You do not have permission to send jobs.',
			bad_assignee: 'Pick a valid specialist.',
			no_lines: 'No lines to send.',
			kiosk_forbidden: 'Not available on the shared device.'
		};
		return m[code] || (code ? String(code) : 'Something went wrong.');
	}

	function openPicker(estimateId) {
		Promise.all([
			tsecPost('tsec_get_estimate_detail', { id: estimateId }),
			loadAssignees(),
			jobsPost('zjob_estimate_rollup', { estimate_id: estimateId }).catch(function () { return null; })
		]).then(function (r) {
			var res = r[0], assignees = r[1];
			var rollup = (r[2] && r[2].success) ? r[2].data : null;
			if (!res || !res.success || !res.data) {
				// fall back: try the alternate param name once
				return tsecPost('tsec_get_estimate_detail', { estimate_id: estimateId }).then(function (res2) {
					if (res2 && res2.success && res2.data) { renderModal(res2.data, parseItems(res2.data), assignees, rollup); }
					else { flashError('Could not load that estimate.'); }
				});
			}
			renderModal(res.data, parseItems(res.data), assignees, rollup);
		}).catch(function () { flashError('Could not load that estimate.'); });
	}

	function flashError(msg) {
		var t = document.createElement('div');
		t.className = 'zjobx-toast'; t.textContent = msg;
		document.body.appendChild(t);
		setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, 3000);
	}

	/* ---- bootstrap (idempotent, mirrors nav.js) ---------------------------- */
	var mo = null, wired = false;
	function tick() { injectHistory(); injectReview(); }
	function start() {
		if (!wired) { document.addEventListener('click', trackClicks, true); wired = true; }
		tick();
		if (!mo) {
			mo = new MutationObserver(tick);
			var host = document.querySelector('.tsec-w') || document.body;
			try { mo.observe(host, { childList: true, subtree: true }); } catch (e) { /* non-fatal */ }
		}
	}

	if (document.readyState !== 'loading') { start(); }
	document.addEventListener('DOMContentLoaded', start);
	document.addEventListener('zdz_widgets_rendered', start);
	setTimeout(start, 800);
	setTimeout(start, 2000);
})();
