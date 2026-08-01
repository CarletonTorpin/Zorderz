/**
 * Zorderz Receipts — Auto-Lookup bar (v2.9.0).
 *
 * Bolted on top of the existing shortcode form. User types estimate #,
 * invoice #, name, or phone → searches FreshBooks → picks a match →
 * pre-fills the form's invoice_url, invoice_number, and optionally
 * Nutshell install notes → tech uploads photos only.
 *
 * Globals:
 *   tserLookupData.ajaxurl
 *   tserLookupData.nonce
 *   tserLookupData.fromCutter  // query-params dict when ?zrcpt_from_cutter=1
 */
(function () {
	'use strict';

	function $(id) { return document.getElementById(id); }
	function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }
	function show(el) { if (el) el.style.display = ''; }
	function hide(el) { if (el) el.style.display = 'none'; }

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', tserLookupData.nonce);
		Object.keys(data || {}).forEach(function (k) {
			var v = data[k];
			body.append(k, (typeof v === 'object') ? JSON.stringify(v) : v);
		});
		return fetch(tserLookupData.ajaxurl, {
			method: 'POST', credentials: 'same-origin', body: body
		}).then(function (r) { return r.json(); });
	}

	function runLookup(q, opts) {
		opts = opts || {};
		var statusEl = $('zrcpt-lookup-status');
		var errEl    = $('zrcpt-lookup-error');
		var cardsEl  = $('zrcpt-lookup-cards');
		if (statusEl) { statusEl.textContent = 'Searching FreshBooks…'; show(statusEl); }
		if (errEl)    { errEl.textContent = ''; hide(errEl); }
		if (cardsEl)  { cardsEl.innerHTML = ''; }

		return post('zrcpt_lookup', { query: q }).then(function (res) {
			hide(statusEl);
			if (!res || !res.success) {
				if (errEl) {
					errEl.textContent = (res && res.data && res.data.message) || 'Lookup failed.';
					show(errEl);
				}
				return;
			}
			var matches = (res.data && res.data.matches) || [];
			if (!matches.length) {
				if (errEl) {
					errEl.textContent = 'No matches for "' + q + '".';
					show(errEl);
				}
				return;
			}
			renderCards(matches, opts);
		}).catch(function (err) {
			hide(statusEl);
			if (errEl) {
				errEl.textContent = 'Network error: ' + err;
				show(errEl);
			}
		});
	}

	function renderCards(matches, opts) {
		var cardsEl = $('zrcpt-lookup-cards');
		if (!cardsEl) return;

		// Trap 2 — warn if we got an estimate but no invoice: receipts attach to invoices.
		var hasInvoice = matches.some(function (m) { return m.type === 'invoice'; });
		var hasEstimate = matches.some(function (m) { return m.type === 'estimate'; });

		var html = '';
		if (!hasInvoice && hasEstimate) {
			html += '<div class="zrcpt-lookup-warn">⚠ Found an estimate but no invoice yet. A receipt normally attaches to an invoice — once the job is invoiced in FreshBooks, re-run the lookup. You can still proceed using the estimate; the receipt link will be attached to it.</div>';
		}

		matches.forEach(function (m, i) {
			var badge = m.type === 'invoice' ? 'Invoice' : 'Estimate';
			html += '<div class="zrcpt-lookup-card" data-i="' + i + '">';
			html += '<div class="zrcpt-lookup-card-head">';
			html += '<strong>' + esc(m.customer_name || '(unknown)') + '</strong>';
			html += '<span class="zrcpt-lookup-badge">' + esc(badge) + ' #' + esc(m.number) + '</span>';
			if (m.reference) html += '<span class="zrcpt-lookup-ref">Ref ' + esc(m.reference) + '</span>';
			html += '</div>';
			if (m.customer_detail && m.customer_detail.address) {
				html += '<div class="zrcpt-lookup-sub">' + esc(m.customer_detail.address) + '</div>';
			}
			html += '<button type="button" class="zrcpt-lookup-use" data-i="' + i + '">Use this one →</button>';
			html += '</div>';
		});

		cardsEl.innerHTML = html;

		cardsEl.querySelectorAll('.zrcpt-lookup-use').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var i = Number(btn.getAttribute('data-i'));
				selectMatch(matches[i], opts);
			});
		});

		// If only one match AND we're handed a handoff from cutter, auto-pick it.
		if (opts.autoSelect && matches.length === 1) {
			selectMatch(matches[0], opts);
		}
	}

	function selectMatch(match, opts) {
		var confirmedEl = $('zrcpt-lookup-confirmed');
		if (confirmedEl) {
			var summary = (match.customer_name || '(unknown)') + ' · ' +
				(match.type === 'invoice' ? 'Invoice' : 'Estimate') + ' #' + match.number +
				(match.reference ? ' · ' + match.reference : '');
			confirmedEl.innerHTML = '<strong>✓ ' + esc(summary) + '</strong>' +
				' <button type="button" id="zrcpt-lookup-change" class="zrcpt-lookup-linkbtn">Change</button>';
			show(confirmedEl);
			var changeBtn = $('zrcpt-lookup-change');
			if (changeBtn) changeBtn.addEventListener('click', function () {
				hide(confirmedEl);
				$('zrcpt-lookup-input').value = '';
				$('zrcpt-lookup-input').focus();
			});
		}

		// Pre-fill the existing form's invoice_url field if we know it.
		// (AJAX for field-prefill could be extended later; for now we set
		// what we already have from the search result.)
		var invUrlInput = document.querySelector('#zrcpt-inv-url');
		if (invUrlInput && match.invoice_url) {
			invUrlInput.value = match.invoice_url;
		}

		// Stash selected match on the form root so the submit handler can read it.
		var app = $('zrcpt-app');
		if (app) app.dataset.selectedMatch = JSON.stringify( {
			type: match.type,
			number: match.number,
			customer_id: match.customer_id,
			customer_name: match.customer_name,
			reference: match.reference,
			invoice_url: match.invoice_url || ''
		} );

		// Pull Nutshell install notes asynchronously (non-blocking).
		post('zrcpt_pull_nutshell_install', {
			customer: JSON.stringify( {
				name:            match.customer_name || '',
				email:           (match.customer_detail && match.customer_detail.email) || '',
				phone:           (match.customer_detail && match.customer_detail.phone) || '',
				estimate_number: match.type === 'estimate' ? match.number : '',
			} )
		}).then(function (res) {
			var hintEl = $('zrcpt-lookup-nutshell-hint');
			if (!hintEl) return;
			if (!res || !res.success) {
				hintEl.innerHTML = '<em style="color:#888;">No Nutshell install notes found — proceed with photos only.</em>';
				return;
			}
			var notes = (res.data && res.data.install_notes) || [];
			if (!notes.length) {
				hintEl.innerHTML = '<em style="color:#888;">Lead found on Nutshell but no install notes matched — proceed with photos only.</em>';
				return;
			}
			hintEl.innerHTML = '<strong>' + notes.length + ' install note' + (notes.length === 1 ? '' : 's') + ' found on Nutshell:</strong> <span style="color:#1e4d6e;">AI will include them when generating the receipt.</span>';
			// Stash notes on the app for submission.
			if (app) app.dataset.installNotes = JSON.stringify(notes);
		}).catch(function () { /* ignore */ });

		// Scroll down to the form so the tech sees what to do next.
		var dateField = document.querySelector('#zrcpt-date');
		if (dateField) dateField.scrollIntoView({ behavior: 'smooth', block: 'center' });
	}

	function init() {
		var input = $('zrcpt-lookup-input');
		var btn   = $('zrcpt-lookup-btn');
		if (!input || !btn) return;

		btn.addEventListener('click', function () {
			var q = input.value.trim();
			if (q) runLookup(q, {});
		});
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') btn.click();
		});

		// Handoff from Prep — ?zrcpt_from_cutter=1&estimate_id=N
		if (tserLookupData.fromCutter && tserLookupData.fromCutter.estimate_id) {
			var autoQ = String(tserLookupData.fromCutter.estimate_id);
			input.value = autoQ;
			runLookup(autoQ, { autoSelect: true });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
