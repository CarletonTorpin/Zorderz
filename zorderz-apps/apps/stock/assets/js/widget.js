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
	var ITEMS = [];          // stock items for the pickers: { id, name, unit }
	var SUPPLIERS = [];      // supplier names for the New order form
	var EMAIL = false;       // the Email app answers "Look in my email"
	var ALL_ITEMS = [];      // every item view from the summary (for a search sent from the ask bar)
	var PENDING_QUERY = '';  // a search that arrived before the widget had its items

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
			else if (action === 'new-order') { openNewOrder(); }
			else if (action === 'look-email') { lookInEmail(btn); }
			else if (action === 'receive') { openReceive(btn.getAttribute('data-id')); }
			else if (action === 'not-ours') { notOurs(btn.getAttribute('data-id')); }
			else if (action === 'close-rest') { closeRest(btn.getAttribute('data-id')); }
			else if (action === 'close-nothing') { closeNothing(btn.getAttribute('data-id')); }
			else if (action === 'panel-cancel') { closePanel(); }
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
			renderAlerts(d.low_stock_items || [], d.catalog_empty, d.uncounted_count || 0);
			renderOrders(d.recent_orders || []);
			renderIncoming(d.incoming || []);
			fillCycle(d.all_items || []);
			ALL_ITEMS = d.all_items || [];
			if (PENDING_QUERY) { var q = PENDING_QUERY; PENDING_QUERY = ''; showMatches(q); }
			loadIncomingMeta();
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
		if (!item.counted) { return 'uncounted'; } // nobody has counted it: never called low or out
		var s = parseFloat(item.current_stock) || 0;
		var r = parseFloat(item.reorder_point) || 0;
		var p = parseFloat(item.par_level) || 0;
		if (s <= 0) { return 'out'; }
		if (s <= r) { return 'critical'; }
		if (s <= p) { return 'low'; }
		return 'good';
	}

	function renderAlerts(items, catalogEmpty, uncounted) {
		var box = root.querySelector('[data-list="alerts"]');
		if (!box) { return; }
		var note = uncounted ? '<p class="zstock-w-empty zstock-w-uncounted">' + uncounted + (uncounted === 1 ? ' item is' : ' items are') + ' not counted yet — Stock does not call those low.</p>' : '';
		if (!items.length) {
			box.innerHTML = '<p class="zstock-w-empty">' + (catalogEmpty ? 'No catalog items yet. Define items in the Item Engine.' : 'No low-stock alerts right now.') + '</p>' + note;
			return;
		}
		var html = note;
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
			html += '<option value="' + esc(items[i].id) + '">' + esc(items[i].name || items[i].id) + (items[i].counted ? '' : ' (not counted yet)') + '</option>';
		}
		sel.innerHTML = html;
	}

	/* ---- Incoming ---- */

	function loadIncomingMeta() {
		ajax('zstock_get_incoming').then(function (res) {
			if (!res || !res.success) { return; }
			ITEMS = res.data.items || [];
			SUPPLIERS = res.data.suppliers || [];
			EMAIL = !!res.data.email;
			var b = root.querySelector('[data-action="look-email"]');
			if (b) { b.hidden = !(EMAIL && DATA.canWrite); }
			INCOMING = res.data.orders || INCOMING;
			renderIncoming(INCOMING);
		}).catch(function () { /* the summary already showed the list */ });
	}

	var INCOMING = [];
	function findOrder(id) {
		for (var i = 0; i < INCOMING.length; i++) { if (String(INCOMING[i].id) === String(id)) { return INCOMING[i]; } }
		return null;
	}

	function renderIncoming(orders) {
		INCOMING = orders || [];
		var box = root.querySelector('[data-list="incoming"]');
		if (!box) { return; }
		if (!INCOMING.length) {
			box.innerHTML = '<p class="zstock-w-empty">Nothing incoming. An order shows here until someone taps Received.</p>';
			return;
		}
		var html = '';
		for (var i = 0; i < INCOMING.length; i++) {
			var o = INCOMING[i];
			var part = o.status === 'partial';
			var lines = '';
			for (var j = 0; j < o.lines.length; j++) {
				var l = o.lines[j];
				lines += '<li>' + esc(l.description) + ' — ' + num(l.qty) + (l.unit ? ' ' + esc(l.unit) : '')
					+ (l.received > 0 ? ' <span class="zstock-w-row-meta">(' + num(l.received) + ' came, ' + num(l.remaining) + ' to come)</span>' : '') + '</li>';
			}
			html += '<div class="zstock-w-order">'
				+ '<div class="zstock-w-order-head"><b>' + esc(o.supplier) + '</b>' + (o.ref ? ' #' + esc(o.ref) : '')
				+ ' <span class="zstock-w-badge zstock-w-badge--' + (part ? 'partial' : 'incoming') + '">' + (part ? 'Part came' : 'Incoming') + '</span></div>'
				+ (o.source === 'email' ? '<div class="zstock-w-row-meta">From email' + (o.from ? ' · ' + esc(o.from) : '') + '</div>' : '')
				+ '<ul class="zstock-w-order-lines">' + lines + '</ul>'
				+ (DATA.canWrite ? '<div class="zstock-w-actions">'
					+ '<button class="zstock-w-btn zstock-w-btn-primary" data-action="receive" data-id="' + o.id + '" type="button">' + (part ? 'More came…' : 'Received…') + '</button>'
					+ (part ? '<button class="zstock-w-btn" data-action="close-rest" data-id="' + o.id + '" type="button">Nothing more coming</button>'
						: '<button class="zstock-w-btn" data-action="not-ours" data-id="' + o.id + '" type="button">Not ours</button>'
						+ (o.receipts === 0 ? '<button class="zstock-w-btn" data-action="close-nothing" data-id="' + o.id + '" type="button">It came, nothing for stock</button>' : ''))
					+ '</div>' : '')
				+ '</div>';
		}
		box.innerHTML = html;
	}

	function panel() { return root.querySelector('.zstock-w-panel'); }
	function closePanel() { var p = panel(); if (p) { p.hidden = true; p.innerHTML = ''; } }

	function itemOptions(selected) {
		var html = '<option value="">— not a stock item —</option>';
		for (var i = 0; i < ITEMS.length; i++) {
			html += '<option value="' + esc(ITEMS[i].id) + '"' + (String(ITEMS[i].id) === String(selected || '') ? ' selected' : '') + '>' + esc(ITEMS[i].name) + '</option>';
		}
		return html;
	}

	/* Received: per line, which stock item and how many came. Fewer than ordered ticks "still coming" (until the
	   person touches it). "+ Another item that came" adds what the order's lines did not say. */
	function openReceive(id) {
		var o = findOrder(id);
		var p = panel();
		if (!o || !p) { return; }
		var rows = '';
		for (var i = 0; i < o.lines.length; i++) {
			var l = o.lines[i];
			if (l.remaining <= 0) { continue; }
			rows += '<div class="zstock-w-rcv-line" data-line="' + l.id + '" data-remaining="' + l.remaining + '">'
				+ '<div class="zstock-w-rcv-desc">' + esc(l.description) + ' <span class="zstock-w-row-meta">(' + num(l.remaining) + ' to come)</span></div>'
				+ '<select class="zstock-w-rcv-item">' + itemOptions(l.item_id) + '</select>'
				+ '<input class="zstock-w-rcv-qty" type="number" min="0" step="any" value="' + num(l.remaining) + '" aria-label="How many came">'
				+ '</div>';
		}
		p.innerHTML = '<h4 class="zstock-w-section-title">Received — ' + esc(o.supplier) + (o.ref ? ' #' + esc(o.ref) : '') + '</h4>'
			+ rows
			+ '<div class="zstock-w-rcv-extra"></div>'
			+ '<button class="zstock-w-btn" type="button" data-rcv="add">+ Another item that came</button>'
			+ '<label class="zstock-w-rcv-more"><input type="checkbox" class="zstock-w-rcv-more-cb"> Some of it is still coming</label>'
			+ '<div class="zstock-w-actions"><button class="zstock-w-btn zstock-w-btn-primary" type="button" data-rcv="confirm">Confirm</button>'
			+ '<button class="zstock-w-btn" type="button" data-action="panel-cancel">Cancel</button></div>';
		p.hidden = false;
		var more = p.querySelector('.zstock-w-rcv-more-cb');
		var touched = false;
		more.addEventListener('change', function () { touched = true; });
		function autoMore() {
			if (touched) { return; }
			var fewer = false;
			p.querySelectorAll('.zstock-w-rcv-line').forEach(function (r) {
				var q = parseFloat(r.querySelector('.zstock-w-rcv-qty').value) || 0;
				if (q < (parseFloat(r.getAttribute('data-remaining')) || 0)) { fewer = true; }
			});
			more.checked = fewer;
		}
		p.addEventListener('input', autoMore);
		p.querySelector('[data-rcv="add"]').addEventListener('click', function () {
			var x = document.createElement('div');
			x.className = 'zstock-w-rcv-line zstock-w-rcv-x';
			x.innerHTML = '<select class="zstock-w-rcv-item">' + itemOptions('') + '</select><input class="zstock-w-rcv-qty" type="number" min="0" step="any" placeholder="How many">';
			p.querySelector('.zstock-w-rcv-extra').appendChild(x);
		});
		p.querySelector('[data-rcv="confirm"]').addEventListener('click', function () {
			var picks = {}, extra = [];
			p.querySelectorAll('.zstock-w-rcv-line').forEach(function (r) {
				var item = r.querySelector('.zstock-w-rcv-item').value;
				var qty = parseFloat(r.querySelector('.zstock-w-rcv-qty').value) || 0;
				if (r.classList.contains('zstock-w-rcv-x')) { if (item || qty) { extra.push({ item_id: item, qty: qty }); } }
				else { picks[r.getAttribute('data-line')] = { item_id: item, qty: qty }; }
			});
			if (busy) { return; }
			busy = true;
			ajax('zstock_receive_order', { order_id: o.id, picks: JSON.stringify(picks), extra: JSON.stringify(extra), more: more.checked ? '1' : '', seen: String(o.receipts) }).then(function (res) {
				busy = false;
				if (!res || !res.success) { toast((res && res.data && res.data.message) || 'Stock could not be updated.', 'error'); if (res && res.data && res.data.already) { closePanel(); loadSummary(); } return; }
				closePanel();
				toast(res.data.status === 'partial' ? 'Received. The order stays open for the rest.' : 'Received. Stock updated.', 'success');
				loadSummary();
			}).catch(function () { busy = false; toast('Could not reach the server. Nothing was added; check Incoming.', 'error'); });
		});
	}

	function simpleOrderAction(action, id, confirmText, okText) {
		if (confirmText && !window.confirm(confirmText)) { return; }
		if (busy) { return; }
		busy = true;
		var o = findOrder(id);
		ajax(action, { order_id: id, seen: o ? String(o.receipts) : '' }).then(function (res) {
			busy = false;
			if (!res || !res.success) { toast((res && res.data && res.data.message) || 'That did not work.', 'error'); return; }
			toast(okText, 'success');
			loadSummary();
		}).catch(function () { busy = false; toast('Could not reach the server.', 'error'); });
	}
	function notOurs(id) { simpleOrderAction('zstock_reject_order', id, 'Not ours, or not coming? It closes without touching stock.', 'Marked Not ours.'); }
	function closeRest(id) { simpleOrderAction('zstock_close_rest', id, 'Nothing more is coming for this order? It closes; stock does not change.', 'Order closed.'); }
	function closeNothing(id) { simpleOrderAction('zstock_close_nothing', id, 'It came, but nothing in it goes into stock? It closes; stock does not change.', 'Order closed.'); }

	/* New order by hand. A one-time form key makes a retry after "no answer" the same order. */
	function openNewOrder() {
		var p = panel();
		if (!p) { return; }
		var key = '';
		for (var i = 0; i < 16; i++) { key += Math.floor(Math.random() * 16).toString(16); }
		var dl = '';
		for (var j = 0; j < SUPPLIERS.length; j++) { dl += '<option value="' + esc(SUPPLIERS[j]) + '">'; }
		p.innerHTML = '<h4 class="zstock-w-section-title">New order</h4>'
			+ '<input class="zstock-w-no-sup" list="zstock-w-sup-list" placeholder="Supplier" maxlength="120"><datalist id="zstock-w-sup-list">' + dl + '</datalist>'
			+ '<input class="zstock-w-no-ref" placeholder="Order number (optional)" maxlength="100">'
			+ '<input class="zstock-w-no-date" type="date">'
			+ '<div class="zstock-w-no-lines"></div>'
			+ '<button class="zstock-w-btn" type="button" data-no="add">+ Line</button>'
			+ '<div class="zstock-w-actions"><button class="zstock-w-btn zstock-w-btn-primary" type="button" data-no="save">Save to Incoming</button>'
			+ '<button class="zstock-w-btn" type="button" data-action="panel-cancel">Cancel</button></div>';
		p.hidden = false;
		function addLine() {
			var r = document.createElement('div');
			r.className = 'zstock-w-no-line';
			r.innerHTML = '<input class="zstock-w-no-desc" placeholder="What was ordered" maxlength="500"><input class="zstock-w-no-qty" type="number" min="0" step="any" placeholder="How many"><select class="zstock-w-no-item">' + itemOptions('') + '</select>';
			p.querySelector('.zstock-w-no-lines').appendChild(r);
		}
		addLine();
		p.querySelector('[data-no="add"]').addEventListener('click', addLine);
		p.querySelector('[data-no="save"]').addEventListener('click', function () {
			var lines = [];
			p.querySelectorAll('.zstock-w-no-line').forEach(function (r) {
				var d = r.querySelector('.zstock-w-no-desc').value.trim(), q = parseFloat(r.querySelector('.zstock-w-no-qty').value) || 0, it = r.querySelector('.zstock-w-no-item').value;
				if (d || it) { lines.push({ description: d, qty: q, item_id: it }); }
			});
			if (busy) { return; }
			busy = true;
			ajax('zstock_new_order', { supplier: p.querySelector('.zstock-w-no-sup').value, ref: p.querySelector('.zstock-w-no-ref').value, date: p.querySelector('.zstock-w-no-date').value, lines: JSON.stringify(lines), form_key: key }).then(function (res) {
				busy = false;
				if (!res || !res.success) { toast((res && res.data && res.data.message) || 'The order could not be saved.', 'error'); return; }
				closePanel();
				toast('Saved under Incoming.', 'success');
				loadSummary();
			}).catch(function () { busy = false; toast('No answer from the server. Try Save again — it will not make a second order.', 'error'); });
		});
	}

	/* Look in my email: your own mailbox, last 30 days, for orders from listed suppliers. */
	function lookInEmail(btn) {
		if (busy) { return; }
		busy = true;
		if (btn) { btn.disabled = true; }
		ajax('zstock_look_in_email').then(function (res) {
			busy = false;
			if (btn) { btn.disabled = false; }
			if (!res || !res.success) { toast((res && res.data && res.data.message) || 'Your email could not be read just now.', 'error'); return; }
			var d = res.data;
			toast(d.created ? (d.created + (d.created === 1 ? ' new order' : ' new orders') + ' under Incoming.') : (d.scanned ? 'Nothing new: every supplier order is already here.' : 'No supplier mail in the last 30 days.'), 'success');
			loadSummary();
		}).catch(function () { busy = false; if (btn) { btn.disabled = false; } toast('Your email could not be read just now.', 'error'); });
	}

	/* A search sent from the ask bar: items whose NAME holds every word first; the ones found only through their
	   category or SKU after them, under "Also in <category>"; those alone when no name fits. Quantities only. */
	var STOP = ['the', 'a', 'an', 'any', 'some', 'our', 'of', 'for', 'in', 'on', 'we', 'do', 'have', 'got', 'is', 'are', 'there', 'how', 'many', 'much', 'left', 'stock', 'available', 'hand', 'inventory'];
	function singular(w) { return (w.length > 3 && w.slice(-1) === 's' && w.slice(-2) !== 'ss') ? w.slice(0, -1) : w; }
	function words(q) {
		var out = [];
		String(q || '').toLowerCase().replace(/[^a-z0-9\s\-]/g, ' ').split(/\s+/).forEach(function (w) {
			if (!w || STOP.indexOf(w) >= 0 || w.length < 3) { return; }
			w = singular(w);
			if (out.indexOf(w) < 0) { out.push(w); }
		});
		return out;
	}
	function holds(item, ws, fields) {
		var toks = {};
		fields.forEach(function (f) { String(item[f] || '').toLowerCase().split(/[^a-z0-9]+/).forEach(function (t) { if (t) { toks[singular(t)] = true; } }); });
		if (fields.indexOf('sku') >= 0 && item.sku) { toks[singular(String(item.sku).toLowerCase())] = true; }
		for (var i = 0; i < ws.length; i++) { if (!toks[ws[i]]) { return false; } }
		return ws.length > 0;
	}
	function showMatches(q) {
		var box = root.querySelector('[data-list="matches"]');
		var sec = root.querySelector('.zstock-w-matches');
		if (!box || !sec) { return; }
		if (!ALL_ITEMS.length) { PENDING_QUERY = q; return; }
		var ws = words(q), named = [], also = [];
		ALL_ITEMS.forEach(function (it) {
			if (holds(it, ws, ['name'])) { named.push(it); } else if (holds(it, ws, ['name', 'category', 'sku'])) { also.push(it); }
		});
		function row(it) {
			var st = statusOf(it);
			var on = (!it.counted && !(parseFloat(it.current_stock) > 0)) ? 'not counted yet' : num(parseFloat(it.current_stock) || 0) + ' ' + esc(it.unit || '');
			return '<div class="zstock-w-row"><span class="zstock-w-dot zstock-w-dot--' + st + '"></span><span class="zstock-w-row-name">' + esc(it.name) + '</span><span class="zstock-w-row-meta">' + on + '</span></div>';
		}
		var html = '';
		(named.length ? named : also).forEach(function (it) { html += row(it); });
		if (named.length && also.length) {
			var byCat = {};
			also.forEach(function (it) { var c = it.category || 'Other'; (byCat[c] = byCat[c] || []).push(it); });
			Object.keys(byCat).forEach(function (c) {
				html += '<p class="zstock-w-section-title zstock-w-match-also">Also in ' + esc(c) + '</p>';
				byCat[c].forEach(function (it) { html += row(it); });
			});
		}
		box.innerHTML = html || '<p class="zstock-w-empty">Nothing in the stock list matches “' + esc(q) + '”.</p>';
		sec.hidden = false;
	}
	document.addEventListener('zdz_app_launch', function (ev) {
		var d = ev && ev.detail;
		if (!d || d.appId !== (DATA.appId || 'stock') || !d.options || !d.options.query) { return; }
		if (!root) { PENDING_QUERY = String(d.options.query); return; }
		showMatches(String(d.options.query));
	});

	function num(n) { n = parseFloat(n) || 0; return (Math.round(n * 100) / 100).toString(); }

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
		ajax('zstock_count_item', { item_id: id, count: count }).then(function (res) {
			busy = false;
			if (!res || !res.success) { toast((res && res.data && res.data.message) || 'The count could not be saved.', 'error'); return; }
			toggleCycle(false);
			toast('Count saved.', 'success');
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
			toast('Read ' + ((d.items && d.items.length) || 0) + ' line(s), ' + (d.matched_count || 0) + ' matched. The order waits under Incoming until someone taps Received.', 'info');
			loadSummary();
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
