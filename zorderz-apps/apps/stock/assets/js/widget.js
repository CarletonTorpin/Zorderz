/**
 * Zorderz Stock — inline dashboard widget.
 * Vanilla JS, no framework. Hydrates the server-rendered #zstock-widget skeleton.
 * Field names mirror ZSTOCK_Engine::get_stock_summary(); AJAX actions are the zstock_* handlers.
 *
 * @package Zorderz\Stock
 */
(function () {
	'use strict';

	var DATA = window.zstockWidgetData || {};
	var AJAX = DATA.ajaxUrl || '/wp-admin/admin-ajax.php';
	var NONCE = DATA.nonce || '';
	var TOAST_MS = 3000;

	var root = null;
	var busy = false;

	function init() {
		root = document.getElementById('zstock-widget');
		if (!root || root.getAttribute('data-ready') === '1') {
			return;
		}
		root.setAttribute('data-ready', '1');
		bind();
		loadSummary();
	}

	document.addEventListener('DOMContentLoaded', init);
	document.addEventListener('zdz_widgets_rendered', init);
	if (document.getElementById('zstock-widget')) {
		init();
	}

	/* ---- AJAX helpers ---- */

	function ajax(action, data) {
		data = data || {};
		data.action = action;
		data.nonce = NONCE;
		return fetch(AJAX, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams(data)
		}).then(function (r) {
			if (!r.ok) { throw new Error('HTTP ' + r.status); }
			return r.json();
		});
	}

	function ajaxUpload(action, formData) {
		formData.append('action', action);
		formData.append('nonce', NONCE);
		return fetch(AJAX, { method: 'POST', body: formData }).then(function (r) {
			if (!r.ok) { throw new Error('HTTP ' + r.status); }
			return r.json();
		});
	}

	/* ---- Events ---- */

	function bind() {
		root.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-action]');
			if (!btn) { return; }
			var action = btn.getAttribute('data-action');
			if (action === 'upload') { openUpload(); }
			else if (action === 'cycle') { toggleCycle(true); }
			else if (action === 'cancel-cycle') { toggleCycle(false); }
			else if (action === 'submit-cycle') { submitCycle(); }
			else if (action === 'sync') { syncConsumption(); }
		});

		var input = root.querySelector('.zstock-w-upload-input');
		if (input) {
			input.addEventListener('change', function (e) {
				var file = e.target.files && e.target.files[0];
				if (file) { uploadInvoice(file); }
			});
		}
	}

	/* ---- Summary ---- */

	function loadSummary() {
		ajax('zstock_get_stock_summary').then(function (res) {
			if (!res || !res.success) {
				toast((res && res.data && res.data.message) || 'Failed to load stock summary.', 'error');
				return;
			}
			var d = res.data;
			setStat('total', d.total_items || 0);
			setStat('low', d.low_stock_count || 0);
			setStat('pending', d.pending_orders || 0);
			renderAlerts(d.low_stock_items || [], d.catalog_empty);
			renderOrders(d.recent_orders || []);
			fillCycle(d.all_items || []);
		}).catch(function (err) {
			toast('Could not connect to server.', 'error');
			if (window.console) { console.error('[ZStock]', err); }
		});
	}

	function setStat(key, val) {
		var el = root.querySelector('[data-stat="' + key + '"] .zstock-w-stat-val');
		if (el) { el.textContent = String(val); }
		var card = root.querySelector('[data-stat="' + key + '"]');
		if (card && key === 'low') { card.classList.toggle('zstock-w-stat--warn', (val || 0) > 0); }
	}

	function statusOf(item) {
		var s = parseFloat(item.current_stock) || 0;
		var r = parseFloat(item.reorder_point) || 0;
		var p = parseFloat(item.par_level) || 0;
		if (s <= 0) { return 'out'; }
		if (s <= r) { return 'critical'; }
		if (s <= p) { return 'low'; }
		return 'good';
	}

	function renderAlerts(items, catalogEmpty) {
		var box = root.querySelector('[data-list="alerts"]');
		if (!box) { return; }
		if (!items.length) {
			box.innerHTML = '<p class="zstock-w-empty">' + (catalogEmpty ? 'No catalog items yet. Define items in the Item Engine.' : 'No low-stock alerts right now.') + '</p>';
			return;
		}
		var html = '';
		for (var i = 0; i < items.length; i++) {
			var it = items[i];
			html += '<div class="zstock-w-row">'
				+ '<span class="zstock-w-dot zstock-w-dot--' + statusOf(it) + '"></span>'
				+ '<span class="zstock-w-row-name">' + esc(it.name) + '</span>'
				+ '<span class="zstock-w-row-meta">' + (parseFloat(it.current_stock) || 0) + ' / ' + (parseFloat(it.reorder_point) || 0) + ' ' + esc(it.unit || '') + '</span>'
				+ '</div>';
		}
		box.innerHTML = html;
	}

	function renderOrders(orders) {
		var box = root.querySelector('[data-list="orders"]');
		if (!box) { return; }
		if (!orders.length) {
			box.innerHTML = '<p class="zstock-w-empty">No recent orders.</p>';
			return;
		}
		var html = '';
		for (var i = 0; i < orders.length; i++) {
			var o = orders[i];
			var total = o.total ? '$' + parseFloat(o.total).toFixed(2) : '';
			html += '<div class="zstock-w-row">'
				+ '<span class="zstock-w-row-name">' + esc(o.supplier_name || 'Supplier') + '</span>'
				+ '<span class="zstock-w-row-meta">' + total + ' <span class="zstock-w-badge zstock-w-badge--' + esc((o.status || 'draft')) + '">' + esc(o.status || 'draft') + '</span></span>'
				+ '</div>';
		}
		box.innerHTML = html;
	}

	function fillCycle(items) {
		var sel = root.querySelector('.zstock-w-cycle-item');
		if (!sel) { return; }
		var html = '<option value="">-- Select item --</option>';
		for (var i = 0; i < items.length; i++) {
			html += '<option value="' + esc(items[i].id) + '">' + esc(items[i].name || items[i].id) + '</option>';
		}
		sel.innerHTML = html;
	}

	/* ---- Cycle count ---- */

	function toggleCycle(show) {
		var box = root.querySelector('.zstock-w-cycle');
		if (box) { box.style.display = show ? '' : 'none'; }
	}

	function submitCycle() {
		var sel = root.querySelector('.zstock-w-cycle-item');
		var cnt = root.querySelector('.zstock-w-cycle-count');
		var id = sel ? sel.value : '';
		var count = cnt ? cnt.value.trim() : '';
		if (!id) { toast('Select an item.', 'error'); return; }
		if (count === '' || isNaN(parseFloat(count)) || parseFloat(count) < 0) { toast('Enter a valid count.', 'error'); return; }
		if (busy) { return; }
		busy = true;
		ajax('zstock_manual_adjust', { item_id: id, new_quantity: count, adjustment_type: 'CYCLE_COUNT', notes: 'Cycle count via widget' }).then(function (res) {
			busy = false;
			if (!res || !res.success) { toast((res && res.data && res.data.message) || 'Cycle count failed.', 'error'); return; }
			toggleCycle(false);
			toast('Cycle count recorded.', 'success');
			loadSummary();
		}).catch(function () { busy = false; toast('Could not submit cycle count.', 'error'); });
	}

	/* ---- Invoice upload ---- */

	function openUpload() {
		var input = root.querySelector('.zstock-w-upload-input');
		if (input) { input.value = ''; input.click(); }
	}

	function uploadInvoice(file) {
		if (busy) { return; }
		busy = true;
		toast('Parsing invoice…', 'info');
		var fd = new FormData();
		fd.append('invoice_file', file);
		ajaxUpload('zstock_upload_invoice', fd).then(function (res) {
			busy = false;
			if (!res || !res.success) { toast((res && res.data && res.data.message) || 'Invoice upload failed.', 'error'); return; }
			var d = res.data;
			var msg = 'Parsed ' + ((d.items && d.items.length) || 0) + ' line(s), ' + (d.matched_count || 0) + ' matched. Approve and add to stock?';
			if (d.order_id && window.confirm(msg)) {
				ajax('zstock_approve_order', { order_id: d.order_id }).then(function (r2) {
					if (r2 && r2.success) { toast('Order approved. Stock updated.', 'success'); loadSummary(); }
					else { toast((r2 && r2.data && r2.data.message) || 'Approve failed.', 'error'); }
				});
			} else {
				toast('Saved as a draft order.', 'info');
				loadSummary();
			}
		}).catch(function () { busy = false; toast('Upload failed. Please try again.', 'error'); });
	}

	/* ---- Consumption sync ---- */

	function syncConsumption() {
		if (busy) { return; }
		busy = true;
		ajax('zstock_sync_consumption').then(function (res) {
			busy = false;
			if (!res || !res.success) { toast((res && res.data && res.data.message) || 'Sync failed.', 'error'); return; }
			toast('Sync complete. ' + ((res.data && res.data.synced_count) || 0) + ' deduction(s).', 'success');
			loadSummary();
		}).catch(function () { busy = false; toast('Sync failed. Please try again.', 'error'); });
	}

	/* ---- Utils ---- */

	function toast(message, type) {
		if (!root) { return; }
		var old = root.querySelector('.zstock-w-toast');
		if (old) { old.remove(); }
		var t = document.createElement('div');
		t.className = 'zstock-w-toast zstock-w-toast--' + (type || 'success');
		t.setAttribute('role', 'status');
		t.textContent = message;
		root.appendChild(t);
		setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, TOAST_MS);
	}

	function esc(str) {
		if (str === null || str === undefined) { return ''; }
		var d = document.createElement('div');
		d.appendChild(document.createTextNode(String(str)));
		return d.innerHTML;
	}
})();
