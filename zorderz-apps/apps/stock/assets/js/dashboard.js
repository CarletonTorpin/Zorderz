/**
 * Zorderz Stock — full admin dashboard (WP-Admin → Stock → Dashboard).
 * Renders into #zstock-dashboard-root. Vanilla JS. Reads window.zstockData.
 *
 * @package Zorderz\Stock
 */
(function () {
	'use strict';

	var DATA = window.zstockData || {};
	var AJAX = DATA.ajaxUrl || '/wp-admin/admin-ajax.php';
	var NONCE = DATA.nonce || '';
	var root = null;

	function init() {
		root = document.getElementById('zstock-dashboard-root');
		if (!root) { return; }
		root.innerHTML = ''
			+ '<div class="zstock-d-bar">'
			+ '  <button class="button button-primary" data-d="refresh">Refresh</button>'
			+ '  <button class="button" data-d="forecast">Forecast</button>'
			+ '  <button class="button" data-d="sync">Deduct from billed jobs</button>'
			+ '</div>'
			+ '<div class="zstock-d-stats" data-d="stats"></div>'
			+ '<h2>Inventory</h2><div data-d="items"><p class="description">Loading…</p></div>'
			+ '<h2>Recent supplier orders</h2><div data-d="orders"><p class="description">Loading…</p></div>'
			+ '<div data-d="forecast-out"></div>';

		root.addEventListener('click', function (e) {
			var b = e.target.closest('[data-d]');
			if (!b) { return; }
			var k = b.getAttribute('data-d');
			if (k === 'refresh') { load(); }
			else if (k === 'forecast') { loadForecast(); }
			else if (k === 'sync') { sync(b); }
		});

		load();
	}

	document.addEventListener('DOMContentLoaded', init);
	if (document.readyState !== 'loading') { init(); }

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

	function load() {
		ajax('zstock_get_stock_summary').then(function (res) {
			if (!res || !res.success) { return; }
			var d = res.data;
			var stats = root.querySelector('[data-d="stats"]');
			stats.innerHTML = tile('Total items', d.total_items || 0)
				+ tile('Low stock', d.low_stock_count || 0)
				+ tile('Pending orders', d.pending_orders || 0)
				+ tile('Inventory value', '$' + (parseFloat(d.total_value) || 0).toFixed(2));
			renderItems(d.all_items || [], d.catalog_empty);
			renderOrders(d.recent_orders || []);
		});
	}

	function tile(label, val) {
		return '<div class="zstock-d-tile"><span class="zstock-d-tile-val">' + esc(val) + '</span><span class="zstock-d-tile-label">' + esc(label) + '</span></div>';
	}

	function statusOf(it) {
		var s = parseFloat(it.current_stock) || 0, r = parseFloat(it.reorder_point) || 0, p = parseFloat(it.par_level) || 0;
		if (s <= 0) { return 'out'; }
		if (s <= r) { return 'critical'; }
		if (s <= p) { return 'low'; }
		return 'good';
	}

	function renderItems(items, empty) {
		var box = root.querySelector('[data-d="items"]');
		if (empty || !items.length) {
			box.innerHTML = '<p class="description">' + (empty ? 'The catalog is empty. Define items (and their Bill of Materials) in Zorderz → Item Engine.' : 'No stock items.') + '</p>';
			return;
		}
		var rows = '';
		for (var i = 0; i < items.length; i++) {
			var it = items[i];
			rows += '<tr>'
				+ '<td><span class="zstock-d-dot zstock-d-dot--' + statusOf(it) + '"></span> ' + esc(it.name) + '</td>'
				+ '<td>' + esc(it.sku || '') + '</td>'
				+ '<td>' + esc(it.category || '') + '</td>'
				+ '<td>' + (parseFloat(it.current_stock) || 0) + ' ' + esc(it.unit || '') + '</td>'
				+ '<td>' + (parseFloat(it.reorder_point) || 0) + '</td>'
				+ '<td>' + (parseFloat(it.par_level) || 0) + '</td>'
				+ '</tr>';
		}
		box.innerHTML = '<table class="widefat striped"><thead><tr><th>Item</th><th>SKU</th><th>Category</th><th>Stock</th><th>Reorder</th><th>Par</th></tr></thead><tbody>' + rows + '</tbody></table>';
	}

	function renderOrders(orders) {
		var box = root.querySelector('[data-d="orders"]');
		if (!orders.length) { box.innerHTML = '<p class="description">No recent orders.</p>'; return; }
		var rows = '';
		for (var i = 0; i < orders.length; i++) {
			var o = orders[i];
			rows += '<tr><td>' + esc(o.supplier_name || '') + '</td><td>' + esc(o.invoice_number || '') + '</td><td>' + esc(o.status || '') + '</td><td>$' + (parseFloat(o.total) || 0).toFixed(2) + '</td><td>' + esc(o.created_at || '') + '</td></tr>';
		}
		box.innerHTML = '<table class="widefat striped"><thead><tr><th>Supplier</th><th>Invoice</th><th>Status</th><th>Total</th><th>Created</th></tr></thead><tbody>' + rows + '</tbody></table>';
	}

	function loadForecast() {
		var out = root.querySelector('[data-d="forecast-out"]');
		out.innerHTML = '<p class="description">Loading forecast…</p>';
		ajax('zstock_get_forecast', { lookback_days: 90, forecast_days: 30 }).then(function (res) {
			if (!res || !res.success) { out.innerHTML = ''; return; }
			var items = (res.data && res.data.items) || [];
			if (!items.length) { out.innerHTML = '<h2>Forecast</h2><p class="description">Not enough usage history yet.</p>'; return; }
			var rows = '';
			for (var i = 0; i < items.length; i++) {
				var it = items[i];
				rows += '<tr><td>' + esc(it.name) + '</td><td>' + it.avg_daily_usage + '</td><td>' + it.days_of_supply + '</td><td>' + it.recommended_order + '</td></tr>';
			}
			out.innerHTML = '<h2>Forecast (30 days)</h2><table class="widefat striped"><thead><tr><th>Item</th><th>Avg/day</th><th>Days of supply</th><th>Recommended order</th></tr></thead><tbody>' + rows + '</tbody></table>';
		});
	}

	function sync(btn) {
		btn.disabled = true;
		ajax('zstock_sync_consumption').then(function (res) {
			btn.disabled = false;
			if (res && res.success) { load(); }
		}).catch(function () { btn.disabled = false; });
	}

	function esc(str) {
		if (str === null || str === undefined) { return ''; }
		var d = document.createElement('div');
		d.appendChild(document.createTextNode(String(str)));
		return d.innerHTML;
	}
})();
