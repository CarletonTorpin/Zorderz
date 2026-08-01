/**
 * ZPREP Leftovers — admin subpage JS.
 *
 * Powers the filter bar, row list, inline bin-location editing, bulk-discard,
 * and CSV export on the Leftover Inventory admin page.
 *
 * Globals:
 *   zprepLeftoversData.ajaxurl
 *   zprepLeftoversData.nonce
 */
(function () {
	'use strict';

	function $(id) { return document.getElementById(id); }
	function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }
	function fmtIn(n) { n = Number(n) || 0; if (Math.abs(n - Math.round(n)) < 0.001) return String(Math.round(n)); return String(Number(n.toFixed(2))).replace(/\.?0+$/, ''); }

	function getFilters() {
		return {
			material:   $('zprep-lo-mat').value,
			status:     $('zprep-lo-status').value,
			min_width:  $('zprep-lo-mw').value  || '',
			min_length: $('zprep-lo-ml').value  || ''
		};
	}

	function post(action, params) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', zprepLeftoversData.nonce);
		Object.keys(params || {}).forEach(function (k) {
			var v = params[k];
			body.append(k, (typeof v === 'object') ? JSON.stringify(v) : v);
		});
		return fetch(zprepLeftoversData.ajaxurl, {
			method: 'POST', credentials: 'same-origin', body: body
		}).then(function (r) { return r.json(); });
	}

	function reload() {
		var tbody = $('zprep-lo-table').querySelector('tbody');
		tbody.innerHTML = '<tr><td colspan="9"><em>Loading…</em></td></tr>';
		post('zprep_leftovers_list', getFilters()).then(function (res) {
			if (!res || !res.success) {
				tbody.innerHTML = '<tr><td colspan="9" style="color:#b32d2e;">' + esc((res && res.data && res.data.message) || 'Failed to load.') + '</td></tr>';
				return;
			}
			renderRows(res.data.rows || []);
			$('zprep-lo-count').textContent = (res.data.count || 0) + ' row' + (res.data.count === 1 ? '' : 's');
		}).catch(function (err) {
			tbody.innerHTML = '<tr><td colspan="9" style="color:#b32d2e;">Network error: ' + esc(err) + '</td></tr>';
		});
	}

	function renderRows(rows) {
		var tbody = $('zprep-lo-table').querySelector('tbody');
		if (!rows.length) {
			tbody.innerHTML = '<tr><td colspan="9"><em>No rows match those filters.</em></td></tr>';
			return;
		}
		var html = '';
		rows.forEach(function (r) {
			var canSelect = r.status === 'available';
			html += '<tr data-id="' + r.id + '" data-status="' + esc(r.status) + '">';
			html += '<td>' + (canSelect ? '<input type="checkbox" class="zprep-lo-cb" data-id="' + r.id + '">' : '') + '</td>';
			html += '<td>' + esc(r.created_at) + '</td>';
			html += '<td><code>' + esc(r.source_job || '—') + '</code></td>';
			html += '<td>' + esc(r.material) + '</td>';
			html += '<td>' + esc(r.roll_width_in) + '"</td>';
			html += '<td>' + fmtIn(r.width_in) + ' × ' + fmtIn(r.length_in) + '</td>';
			html += '<td><input type="text" class="zprep-lo-bin" data-id="' + r.id + '" value="' + esc(r.bin_location || '') + '" style="width:80px;" maxlength="32"></td>';
			html += '<td><span class="zprep-lo-badge zprep-lo-badge-' + esc(r.status) + '">' + esc(r.status) + '</span></td>';
			html += '<td>' + esc(r.used_in_job || '—') + '</td>';
			html += '</tr>';
		});
		tbody.innerHTML = html;

		// Wire bin inline-edit (save on blur).
		tbody.querySelectorAll('.zprep-lo-bin').forEach(function (inp) {
			var orig = inp.value;
			inp.addEventListener('blur', function () {
				if (inp.value === orig) return;
				post('zprep_leftovers_update_bin', { id: inp.getAttribute('data-id'), bin: inp.value }).then(function (res) {
					if (res && res.success) {
						orig = inp.value;
						inp.style.background = '#e6f4ea';
						setTimeout(function () { inp.style.background = ''; }, 800);
					} else {
						inp.style.background = '#fde4e4';
						inp.value = orig;
					}
				});
			});
		});

		// Wire row checkboxes → toggle discard button state.
		tbody.querySelectorAll('.zprep-lo-cb').forEach(function (cb) {
			cb.addEventListener('change', updateDiscardState);
		});
		updateDiscardState();
	}

	function getSelectedIds() {
		return Array.prototype.slice.call(document.querySelectorAll('.zprep-lo-cb:checked')).map(function (cb) {
			return Number(cb.getAttribute('data-id'));
		});
	}

	function updateDiscardState() {
		var n = getSelectedIds().length;
		var btn = $('zprep-lo-discard');
		btn.disabled = (n === 0);
		btn.textContent = (n > 0) ? ('Discard ' + n + ' selected') : 'Discard selected';
	}

	function discardSelected() {
		var ids = getSelectedIds();
		if (!ids.length) return;
		if (!confirm('Mark ' + ids.length + ' piece(s) as discarded? They will not appear in future leftover-first passes.')) return;
		post('zprep_leftovers_discard', { ids: ids }).then(function (res) {
			if (res && res.success) reload();
			else alert('Discard failed: ' + ((res && res.data && res.data.message) || 'unknown error'));
		});
	}

	function init() {
		$('zprep-lo-reload').addEventListener('click', reload);
		$('zprep-lo-discard').addEventListener('click', discardSelected);
		$('zprep-lo-all').addEventListener('change', function (e) {
			document.querySelectorAll('.zprep-lo-cb').forEach(function (cb) { cb.checked = e.target.checked; });
			updateDiscardState();
		});
		$('zprep-lo-csv').addEventListener('click', function (e) {
			e.preventDefault();
			var qs = new URLSearchParams({
				action: 'zprep_leftovers_export_csv',
				nonce:  zprepLeftoversData.nonce,
				material:   $('zprep-lo-mat').value,
				status:     $('zprep-lo-status').value,
				min_width:  $('zprep-lo-mw').value  || '',
				min_length: $('zprep-lo-ml').value  || ''
			}).toString();
			window.location = zprepLeftoversData.ajaxurl + '?' + qs;
		});

		// Inline style for status badges.
		var style = document.createElement('style');
		style.textContent =
			'.zprep-lo-badge{padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;}' +
			'.zprep-lo-badge-available{background:#e6f4ea;color:#1a6b2e;}' +
			'.zprep-lo-badge-reserved {background:#fff4e0;color:#8a5500;}' +
			'.zprep-lo-badge-used     {background:#e8ebf0;color:#445;}' +
			'.zprep-lo-badge-discarded{background:#f5e0e0;color:#8a1a1a;}';
		document.head.appendChild(style);

		reload();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
