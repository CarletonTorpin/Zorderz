/* Team Roles — the app in its card. Vanilla JS on the platform's kit of parts (ZDZ.ui: rest, sheet, busy, toast,
   loadingHtml). Starts when its root exists: at once, on the theme's `zdz_widgets_rendered`, or at DOMContentLoaded.
   Two views of the same answers: the GRID (people down the side, abilities across the top) and ONE PERSON (their
   abilities as a list; for the admin tier a Can / Learning / Can't switch on each, the quick way to fill a row).
   Catalog ITEMS have a column per role, Sell / Install / Repair, under the cell key "<item-id>__<role>"; a kind of
   work that moved into items is no longer drawn. An admin with older kind-of-work marks still to move sees "Move the
   marks into items", which shows exactly what it will write before it does.
   Who may see or change what is decided on the server; this file draws what it is sent. */
(function () {
	'use strict';

	var C = window.ZRO_CONFIG || {};
	var LEVELS = [['can', 'Can', '✓'], ['learning', 'Learning', '◐'], ['cannot', 'Can’t', '✕']];
	var WORD = { can: 'Can', learning: 'Learning', cannot: 'Can’t', '': 'Not marked' };
	var GLYPH = { can: '✓', learning: '◐', cannot: '✕', '': '·' };
	var WIDE = '(min-width: 760px)';

	var root = null;
	var S = { data: null, view: '', person: 0 };

	function kit() { return (window.ZDZ && window.ZDZ.ui) ? window.ZDZ.ui : null; }
	function api(path, opts) {
		var k = kit();
		if (!k) { return Promise.reject(new Error('Team Roles needs a newer Zorderz theme. Reload the app.')); }
		path = (C.restPath || 'zorderz/v1/team-roles/') + path;
		// a site on plain links serves the API at ?rest_route=/…, so the route's own query joins with & (one ? only)
		if (window.zdzData && /[?&]rest_route=/.test(String(window.zdzData.apiUrl || ''))) { path = path.replace('?', '&'); }
		return k.rest(path, opts || {});
	}
	function say(msg, opts) { var k = kit(); if (k) { k.toast(msg, opts || {}); } }
	function sayErr(err, retry) { var k = kit(); if (k) { k.toastError(err, retry); } }
	function busy(btn, work) { var k = kit(); return k ? k.busy(btn, work) : Promise.resolve(work()); }
	function loadingHtml(label) { var k = kit(); return k && k.loadingHtml ? k.loadingHtml(label) : '<p>' + esc(label) + '</p>'; }
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
	}
	function canEdit() { return !!(S.data && S.data.canEdit); }
	// in use, and not a kind of work that moved into items (its answer comes from the items now)
	function abilities() { return (S.data.abilities || []).filter(function (a) { return !a.retired && !(a.merged && a.merged.length); }); }
	function abilityOf(key) { var all = S.data.abilities || []; for (var i = 0; i < all.length; i++) { if (all[i].key === key) { return all[i]; } } return null; }
	/** A column: a plain ability, or one role of an item ("<item-id>__sell"). {key, label, type_label, item, role}. */
	function colOf(key) {
		var a = abilityOf(key);
		if (a && a.group !== 'item') { return { key: a.key, label: a.label, type_label: a.type_label, item: null, role: '' }; }
		var all = S.data.abilities || [];
		for (var i = 0; i < all.length; i++) {
			var r = all[i].roles || [];
			for (var j = 0; j < r.length; j++) {
				if (r[j].key === key) { return { key: key, label: all[i].label + ' · ' + r[j].label, type_label: all[i].type_label, item: all[i], role: r[j].role, roleLabel: r[j].label }; }
			}
		}
		return null;
	}
	/** The columns in grid order: an item gives one per role. */
	function columns() {
		var out = [];
		abilities().forEach(function (a) {
			if (a.group === 'item') {
				(a.roles || []).forEach(function (r, n) { out.push({ key: r.key, group: a.group, group_label: a.group_label, item: a, role: r.role, roleLabel: r.label, first: n === 0, span: (a.roles || []).length }); });
			} else {
				out.push({ key: a.key, group: a.group, group_label: a.group_label, item: null, a: a });
			}
		});
		return out;
	}
	function hasItems() { return abilities().some(function (a) { return a.group === 'item'; }); }
	function personOf(id) { var p = S.data.people || []; for (var i = 0; i < p.length; i++) { if (+p[i].id === +id) { return p[i]; } } return null; }
	function cellOf(p, key) { return (p && p.cells && p.cells[key]) || null; }
	function levelOf(p, key) { var c = cellOf(p, key); return c ? c.level : ''; }
	function isWide() { return window.matchMedia ? window.matchMedia(WIDE).matches : true; }

	function mark(level, opts) {
		opts = opts || {};
		var l = level || '';
		return '<span class="zro-mark zdz-pair zro-mark--' + (l || 'unset') + '"><span class="zro-glyph" aria-hidden="true">' + GLYPH[l] + '</span>'
			+ (opts.short ? '' : '<span>' + esc(WORD[l]) + '</span>') + (opts.noted ? '<span class="zro-noted" aria-hidden="true"></span>' : '') + '</span>';
	}

	/* ------------------------------------------------------------------ frame */

	function frame() {
		closePick(false);
		root.innerHTML = '<div class="zro-head"><div><h2 class="zro-title">Team Roles</h2><p class="zro-scope" data-scope></p></div>'
			+ '<div class="zro-tools" data-tools></div></div>'
			+ '<ul class="zro-legend" aria-label="What the marks mean">'
			+ '<li>' + mark('can') + ' can do it</li><li>' + mark('learning') + ' learning it</li><li>' + mark('cannot') + ' can’t</li><li>' + mark('') + ' not marked yet (reads as can’t)</li></ul>'
			+ '<p class="zro-tip" data-tip hidden>Tap a box, then tap the mark: it saves at once (Undo in the message). On a keyboard: C, L or X, Delete to clear, arrows to move.</p>'
			+ '<div data-body>' + loadingHtml('Loading Team Roles…') + '</div>';
	}

	function drawTools() {
		var scope = root.querySelector('[data-scope]');
		var n = (S.data.people || []).length;
		var ni = abilities().filter(function (a) { return a.group === 'item'; }).length;
		scope.textContent = S.data.scope === 'everyone' ? n + ' ' + (n === 1 ? 'person' : 'people') + (ni ? ' · ' + ni + ' items (sell, install, repair)' : '') + ' · ' + (abilities().length - ni) + ' other abilities'
			: S.data.scope === 'crew' ? 'You and your crew (' + n + ' people). Only the admin tier changes answers.'
				: 'Your own row. Only the admin tier changes answers; ask them if something here is wrong.';
		var t = root.querySelector('[data-tools]');
		var html = '';
		if (n > 1) {
			html += '<div class="zro-views" role="group" aria-label="View"><button type="button" data-view="grid" aria-pressed="' + (S.view === 'grid') + '">Grid</button>'
				+ '<button type="button" data-view="person" aria-pressed="' + (S.view === 'person') + '">One person</button></div>';
		}
		html += '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-act="history">History</button>';
		if (canEdit()) { html += '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-act="abilities">Abilities</button>'; }
		t.innerHTML = html;
		Array.prototype.forEach.call(t.querySelectorAll('[data-view]'), function (b) {
			b.addEventListener('click', function () { S.view = b.getAttribute('data-view'); draw(); });
		});
		var h = t.querySelector('[data-act="history"]');
		h.addEventListener('click', function () { openHistory(S.view === 'person' || n === 1 ? S.person : 0); });
		var a = t.querySelector('[data-act="abilities"]');
		if (a) { a.addEventListener('click', openAbilities); }
	}

	function load() {
		return api('grid').then(function (d) {
			S.data = d;
			if (!S.person || !personOf(S.person)) { S.person = d.people && d.people.length ? +d.people[0].id : 0; }
			if (!S.view) { S.view = (d.people || []).length > 1 && isWide() ? 'grid' : 'person'; }
			if ((d.people || []).length <= 1) { S.view = 'person'; }
			draw();
		}).catch(function (e) {
			closePick(false);
			root.querySelector('[data-body]').innerHTML = '<p class="zro-err">' + esc(e.message) + '</p><p><button type="button" class="zdz-btn zdz-btn--secondary" data-retry>Try again</button></p>';
			root.querySelector('[data-retry]').addEventListener('click', function () { frame(); load(); });
		});
	}

	function draw() {
		// A redraw rebuilds the grid box, which would jump back to the top-left after every save and drop focus:
		// keep where it was scrolled and which cell had focus, and put both back.
		var wrap = root.querySelector('.zro-gridwrap');
		var keep = wrap ? { left: wrap.scrollLeft, top: wrap.scrollTop } : null;
		var act = document.activeElement;
		var focusSel = act && root.contains(act) && act.getAttribute('data-a')
			? '[data-a="' + act.getAttribute('data-a') + '"]' + (act.getAttribute('data-p') ? '[data-p="' + act.getAttribute('data-p') + '"]' : '') + (act.getAttribute('data-set') ? '[data-set="' + act.getAttribute('data-set') + '"]' : '')
			: '';
		// a save that lands while the menu is open on another box redraws the grid; put that menu
		// back on the new box instead of dropping it (and the keyboard focus with it).
		var reopen = pickBox ? { pid: pickBox._pid, key: pickBox._key } : null;
		drawInner();
		var w2 = root.querySelector('.zro-gridwrap');
		if (w2 && keep) { w2.scrollLeft = keep.left; w2.scrollTop = keep.top; }
		if (focusSel) { var f = root.querySelector(focusSel); if (f && f.focus) { f.focus({ preventScroll: true }); } }
		if (reopen && S.view === 'grid') {
			var nb = root.querySelector('.zro-cell-btn[data-p="' + reopen.pid + '"][data-a="' + reopen.key + '"]');
			if (nb) { openPick(reopen.pid, reopen.key, nb); }
		}
	}

	function drawInner() {
		drawTools();
		closePick(false);
		var tip = root.querySelector('[data-tip]');
		if (tip) { tip.hidden = !(canEdit() && S.view === 'grid'); }
		var body = root.querySelector('[data-body]');
		if (!S.data.people || !S.data.people.length) {
			body.innerHTML = '<p class="zro-muted">No one on the team yet.</p>';
			return;
		}
		if (!abilities().length) {
			body.innerHTML = '<p class="zro-muted">There are no abilities in use.' + (canEdit() ? ' Add some under Abilities.' : '') + '</p>';
			return;
		}
		if (S.view === 'grid') { drawGrid(body); } else { drawPerson(body); }
		drawMove(body);
	}

	/* ------------------------------------------------------------------ the one-time move (admin tier) */

	function drawMove(body) {
		var m = S.data.migration;
		if (!canEdit() || !m || !m.needed) { return; }
		var box = document.createElement('div');
		box.className = 'zro-move';
		box.setAttribute('role', 'region');
		box.setAttribute('aria-label', 'Move the marks into items');
		box.innerHTML = '<p><strong>Sell, Install and Repair.</strong> The marks under Kinds of work say who works with something, not whether they sell it or install it. Move them into the items: you’ll see exactly what gets written first.</p>'
			+ '<button type="button" class="zdz-btn zdz-btn--primary zdz-btn--sm" data-move>Move the marks into items…</button>';
		body.insertBefore(box, body.firstChild);
		box.querySelector('[data-move]').addEventListener('click', openMove);
	}

	function openMove() {
		var k = kit();
		if (!k) { return; }
		var body = document.createElement('div');
		body.className = 'zro-form zro-moveform';
		body.innerHTML = loadingHtml('Working out the move…');
		var P = { people: {}, map: {} };
		var last = null;
		var sheet = k.sheet({
			title: 'Move the marks into items',
			body: body,
			actions: [{ label: 'Cancel' }, { label: 'Move the marks', kind: 'primary', onClick: function () {
				return api('migrate', { method: 'POST', body: plan() }).then(function (r) {
					say(r.message, { kind: 'success' });
					return load();
				});
			} }],
		});
		function plan() {
			var people = Object.keys(P.people).map(function (id) { return { id: +id, sells: P.people[id].sells, installs: P.people[id].installs }; });
			var map = Object.keys(P.map).map(function (key) { return { key: key, targets: P.map[key] }; });
			return { people: people, map: map };
		}
		var WORK = { 'new': 'new work', repair: 'repairs', any: 'new work and repairs' };
		function levelSelect(name, val) {
			return '<select data-' + name + '>' + LEVELS.map(function (L) { return '<option value="' + L[0] + '"' + (L[0] === val ? ' selected' : '') + '>' + esc(L[1]) + '</option>'; }).join('') + '</select>';
		}
		function render(d) {
			last = d;
			var h = '<p class="zro-help">Each mark under Kinds of work goes onto the items it covered: <strong>Sell</strong> for people who sell, <strong>Install</strong> and <strong>Repair</strong> for people who install. Change anything below; nothing is written until you press <strong>Move the marks</strong>. The old marks are kept, and anything already marked on the new columns is left alone.</p>';
			h += '<h3 class="zro-ab-group">Who sells, who installs</h3><ul class="zro-move-people">';
			d.people.forEach(function (p) {
				h += '<li data-pid="' + p.id + '"><span class="zro-move-name">' + esc(p.name) + '<span class="zro-move-n">' + (p.cells.length ? p.cells.length + ' to write' : 'nothing to write') + '</span></span>'
					+ '<div class="zro-move-pair"><label>Sells ' + levelSelect('sells', p.sells) + '</label><small>' + esc(p.sells_why) + '</small></div>'
					+ '<div class="zro-move-pair"><label>Installs and repairs ' + levelSelect('installs', p.installs) + '</label><small>' + esc(p.installs_why) + '</small></div></li>';
			});
			h += '</ul><h3 class="zro-ab-group">Where each kind of work goes</h3><ul class="zro-move-map">';
			d.map.forEach(function (m) {
				// the work a pick starts with: the kind's own (current, then suggested), else read from its name
				var wk = m.targets.length ? m.targets[0].work : (m.defaults && m.defaults.length ? m.defaults[0].work : (/repair|service/i.test(m.label) ? 'repair' : (/install|new/i.test(m.label) ? 'new' : 'any')));
				var multi = m.defaults && m.defaults.length > 1;
				var asSuggested = multi && JSON.stringify(m.targets.map(function (x) { return [x.item, x.work]; })) === JSON.stringify(m.defaults.map(function (x) { return [x.item, x.work]; }));
				var t = m.targets.length ? m.targets.map(function (x) { return esc(x.item_label) + ' (' + esc(WORK[x.work]) + ')'; }).join(', ') : '<em>stays as it is</em>';
				h += '<li data-key="' + esc(m.key) + '"><span class="zro-move-name">' + esc(m.label) + ' <small>' + m.marks + ' ' + (m.marks === 1 ? 'mark' : 'marks') + '</small></span>'
					+ '<span class="zro-move-to">→ ' + t + '</span>'
					+ '<label class="zro-move-pick"><span class="zro-sr">Move to</span><select data-item aria-label="Move ' + esc(m.label) + ' to"><option value="">(stays as it is)</option>'
					// a kind that goes to several items can always go back to the suggestion
					+ (multi ? '<option value="*"' + (asSuggested ? ' selected' : '') + '>(as suggested: ' + esc(m.defaults.length) + ' items)</option>' : '')
					+ d.items.map(function (i) { return '<option value="' + esc(i.key) + '"' + (m.targets.length === 1 && m.targets[0].item === i.key ? ' selected' : '') + '>' + esc(i.label) + '</option>'; }).join('')
					+ '</select>'
					+ '<select data-work aria-label="Which work"' + (asSuggested ? ' disabled' : '') + '>' + ['new', 'repair', 'any'].map(function (w) { return '<option value="' + w + '"' + (w === wk ? ' selected' : '') + '>' + esc(WORK[w]) + '</option>'; }).join('') + '</select></label></li>';
			});
			h += '</ul><h3 class="zro-ab-group">What gets written</h3>';
			h += '<p><strong>' + d.cells + '</strong> ' + (d.cells === 1 ? 'mark' : 'marks') + (d.kept ? ' (' + d.kept + ' already marked on the new columns, left alone)' : '') + '.</p><ul class="zro-hist">';
			var RL = { sell: 'Sell', install: 'Install', repair: 'Repair' };
			d.people.forEach(function (p) {
				if (!p.cells.length) { return; }
				var byItem = {};
				var order = [];
				p.cells.forEach(function (c) { if (!byItem[c.item]) { byItem[c.item] = []; order.push(c.item); } byItem[c.item].push(c); });
				h += '<li><strong>' + esc(p.name) + '</strong><ul class="zro-move-items">' + order.map(function (it) {
					return '<li>' + esc(it) + ': ' + byItem[it].map(function (c) { return esc(RL[c.role]) + ' ' + mark(c.level, { short: true }); }).join(' ') + '</li>';
				}).join('') + '</ul></li>';
			});
			h += '</ul>';
			body.innerHTML = h;
			Array.prototype.forEach.call(body.querySelectorAll('[data-pid]'), function (li) {
				var id = li.getAttribute('data-pid');
				['sells', 'installs'].forEach(function (f) {
					li.querySelector('[data-' + f + ']').addEventListener('change', function (ev) {
						P.people[id] = P.people[id] || { sells: li.querySelector('[data-sells]').value, installs: li.querySelector('[data-installs]').value };
						P.people[id][f] = ev.target.value;
						refresh();
					});
				});
			});
			Array.prototype.forEach.call(body.querySelectorAll('[data-key]'), function (li) {
				var key = li.getAttribute('data-key');
				var mm = null;
				d.map.forEach(function (x) { if (x.key === key) { mm = x; } });
				var change = function () {
					var item = li.querySelector('[data-item]').value;
					if (item === '*') {
						P.map[key] = (mm && mm.defaults || []).map(function (x) { return { item: x.item, work: x.work }; });
					} else {
						P.map[key] = item ? [{ item: item, work: li.querySelector('[data-work]').value }] : [];
					}
					refresh();
				};
				li.querySelector('[data-item]').addEventListener('change', change);
				li.querySelector('[data-work]').addEventListener('change', change);
			});
		}
		function refresh() {
			sheet.status('', '');
			return api('migrate/preview', { method: 'POST', body: plan() }).then(render).catch(function (e) { sheet.status(e.message, 'error'); });
		}
		refresh();
	}

	/* ------------------------------------------------------------------ the grid */

	function drawGrid(body) {
		var cols = columns();
		var items = hasItems();
		var groups = [];
		cols.forEach(function (c) {
			var g = groups[groups.length - 1];
			if (!g || g.key !== c.group) { groups.push({ key: c.group, label: c.group_label, n: 1 }); } else { g.n++; }
		});
		var h = '<div class="zro-gridwrap" tabindex="0" role="region" aria-label="Abilities grid: people down the side, abilities across the top"><table class="zro-grid' + (items ? ' has-items' : '') + '"><thead><tr><th class="zro-corner" rowspan="' + (items ? 3 : 2) + '" scope="col">Person</th>';
		groups.forEach(function (g) { h += '<th colspan="' + g.n + '" scope="colgroup">' + esc(g.label) + '</th>'; });
		h += '</tr><tr>';
		var prev = '';
		cols.forEach(function (c) {
			if (c.item) {
				if (c.first) {
					h += '<th scope="colgroup" colspan="' + c.span + '" class="zro-item' + (c.group !== prev ? ' zro-gstart' : ' zro-istart') + '" title="' + esc(c.item.label + (c.item.type_label ? ' — ' + c.item.type_label : '')) + '">'
						+ esc(c.item.label) + (c.item.addon ? ' <span class="zro-addon">add-on</span>' : '') + '</th>';
				}
			} else {
				h += '<th scope="col"' + (items ? ' rowspan="2"' : '') + ' class="' + (c.group !== prev ? 'zro-gstart' : '') + '" title="' + esc(c.a.label + (c.a.type_label ? ' — ' + c.a.type_label : '')) + '">' + esc(c.a.label) + '</th>';
			}
			prev = c.group;
		});
		h += '</tr>';
		if (items) {
			h += '<tr>';
			prev = '';
			cols.forEach(function (c) {
				if (c.item) {
					h += '<th scope="col" class="zro-role' + (c.first ? (c.group !== prev ? ' zro-gstart' : ' zro-istart') : '') + '" title="' + esc(c.item.label + ' · ' + c.roleLabel) + '">' + esc(c.roleLabel) + '</th>';
				}
				prev = c.group;
			});
			h += '</tr>';
		}
		h += '</thead><tbody>';
		S.data.people.forEach(function (p) {
			h += '<tr class="' + (p.me ? 'is-me' : '') + '"><th scope="row" class="zro-who"><button type="button" class="zro-who-btn" data-person="' + p.id + '" aria-label="' + esc(p.name) + ': open one person view">'
				+ chip(p) + '<span class="zro-who-name">' + esc(p.name) + (p.me ? ' <span class="zro-you">(you)</span>' : '') + '</span></button></th>';
			prev = '';
			cols.forEach(function (col) {
				var c = cellOf(p, col.key);
				var l = c ? c.level : '';
				var noted = !!(c && (c.note || c.since));
				var name = col.item ? col.item.label + ', ' + col.roleLabel : col.a.label;
				var label = p.name + ', ' + name + ': ' + WORD[l] + (c && c.since ? ', since ' + c.since : '') + (c && c.note ? '. Note: ' + c.note : '');
				var cls = col.group !== prev ? ' zro-gstart' : (col.item && col.first ? ' zro-istart' : '');
				h += '<td class="zro-cell' + (col.item ? ' zro-cell--role' : '') + cls + '"><button type="button" class="zro-cell-btn" data-p="' + p.id + '" data-a="' + esc(col.key) + '" aria-label="' + esc(label + (canEdit() ? '. Change' : '')) + '"'
					+ ' title="' + esc(label) + '"' + (canEdit() ? ' aria-haspopup="dialog" aria-expanded="false"' : '') + (canEdit() || noted ? '' : ' disabled') + '>' + mark(l, { short: true, noted: noted }) + '</button></td>';
				prev = col.group;
			});
			h += '</tr>';
		});
		h += '</tbody></table></div>';
		body.innerHTML = h;
		stickHeads(body);
		Array.prototype.forEach.call(body.querySelectorAll('[data-person]'), function (b) {
			b.addEventListener('click', function () { S.person = +b.getAttribute('data-person'); S.view = 'person'; draw(); });
		});
		Array.prototype.forEach.call(body.querySelectorAll('.zro-cell-btn'), function (b) {
			b.addEventListener('click', function () {
				// the admin tier picks the mark straight from the cell (two taps, saved at once, Undo in the
				// message); the sheet with the note and the since date is one tap further, under "Note or date…".
				if (canEdit() && pickBox && pickBox._pid === +b.getAttribute('data-p') && pickBox._key === b.getAttribute('data-a')) { closePick(true); return; }
				if (canEdit()) { openPick(+b.getAttribute('data-p'), b.getAttribute('data-a'), b); } else { openCell(+b.getAttribute('data-p'), b.getAttribute('data-a'), b); }
			});
			if (canEdit()) { b.addEventListener('keydown', function (ev) { cellKey(ev, b); }); }
		});
	}

	/* ------------------------------------------------------------------ change a mark in two taps */

	var KEYS = { c: 'can', l: 'learning', x: 'cannot' };
	var pickBox = null;
	var inFlight = {}; // "pid|key" while its save is on the way: a second press on the same box is dropped

	function closePick(refocus) {
		if (!pickBox) { return; }
		var box = pickBox;
		var from = box._from;
		pickBox = null;
		box.parentNode && box.parentNode.removeChild(box);
		document.removeEventListener('mousedown', box._outside, true);
		document.removeEventListener('touchstart', box._outside, true);
		document.removeEventListener('keydown', box._esc, true);
		document.removeEventListener('scroll', box._scroll, true);
		window.removeEventListener('resize', box._scroll);
		if (from && from.setAttribute) { from.setAttribute('aria-expanded', 'false'); }
		if (refocus && from && document.body.contains(from) && from.focus) { from.focus({ preventScroll: true }); }
	}

	/** Set one cell from the grid: no sheet, no Save. Same level again is a no-op. */
	function setFromGrid(pid, key, level) {
		var p = personOf(pid);
		if (!p) { return Promise.resolve(); }
		var c = cellOf(p, key);
		if ((c ? c.level : '') === (level === 'unset' ? '' : level)) { return Promise.resolve(); }
		var id = pid + '|' + key;
		if (inFlight[id]) { return Promise.resolve(); }
		inFlight[id] = true;
		var done = function () { delete inFlight[id]; };
		return save(p, key, level, c ? c.note : '', c ? c.since : '', true).then(done, function (e) { done(); sayErr(e); });
	}

	/** A small menu at the cell: Can, Learning, Can't, Not marked, and "Note or date…". One tap saves. */
	function openPick(pid, key, from) {
		closePick(false);
		var p = personOf(pid);
		var a = colOf(key);
		if (!p || !a) { return; }
		var l = levelOf(p, key);
		var box = document.createElement('div');
		box.className = 'zro-pick';
		box.setAttribute('role', 'dialog');
		box.setAttribute('aria-label', p.name + ', ' + a.label);
		var opts = LEVELS.concat([['unset', 'Not marked', '·']]);
		box.innerHTML = '<p class="zro-pick-title">' + esc(p.name) + ' · ' + esc(a.label) + '</p><div class="zro-pick-opts">'
			+ opts.map(function (L) {
				var on = (L[0] === 'unset' ? '' : L[0]) === l;
				return '<button type="button" class="is-' + L[0] + '" data-pickset="' + L[0] + '" aria-pressed="' + on + '"><span aria-hidden="true">' + L[2] + '</span> ' + esc(L[1]) + '</button>';
			}).join('')
			+ '</div><button type="button" class="zro-pick-more" data-pickmore>Note or date…</button>';
		document.body.appendChild(box);
		box._from = from;
		box._pid = pid;
		box._key = key;
		from.setAttribute('aria-expanded', 'true');
		// under the cell, kept on screen; above it when there is no room below
		var r = from.getBoundingClientRect();
		var bw = box.offsetWidth, bh = box.offsetHeight;
		var vw = document.documentElement.clientWidth || window.innerWidth, vh = window.innerHeight || document.documentElement.clientHeight;
		var left = Math.max(8, Math.min(r.left + r.width / 2 - bw / 2, vw - bw - 8));
		var top = r.bottom + 6 + bh <= vh - 8 ? r.bottom + 6 : Math.max(8, r.top - bh - 6);
		box.style.left = left + 'px';
		box.style.top = top + 'px';
		Array.prototype.forEach.call(box.querySelectorAll('[data-pickset]'), function (b) {
			b.addEventListener('click', function () {
				var want = b.getAttribute('data-pickset');
				closePick(true);
				setFromGrid(pid, key, want);
			});
		});
		box.querySelector('[data-pickmore]').addEventListener('click', function () { closePick(false); openCell(pid, key, from); });
		box._outside = function (ev) { if (pickBox && !pickBox.contains(ev.target) && ev.target !== from && !from.contains(ev.target)) { closePick(false); } };
		box._esc = function (ev) { if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); closePick(true); } };
		box._scroll = function () { closePick(false); };
		// focus leaving for anywhere but the menu or its box (Tab out of it) closes it
		box.addEventListener('focusout', function (ev) {
			var to = ev.relatedTarget;
			if (to && pickBox === box && !box.contains(to) && to !== from) { closePick(false); }
		});
		document.addEventListener('mousedown', box._outside, true);
		document.addEventListener('touchstart', box._outside, true);
		document.addEventListener('keydown', box._esc, true);
		// any scroll (the grid's box or the page; capture sees both) or a resize moves the box away
		document.addEventListener('scroll', box._scroll, true);
		window.addEventListener('resize', box._scroll);
		pickBox = box;
		var first = box.querySelector('[aria-pressed="true"]') || box.querySelector('[data-pickset]');
		if (first && first.focus) { first.focus({ preventScroll: true }); }
	}

	/** On a keyboard: C, L, X set the focused cell; Delete clears it; the arrows move between cells. */
	function cellKey(ev, b) {
		if (ev.metaKey || ev.ctrlKey || ev.altKey) { return; }
		var k0 = String(ev.key || '');
		if (ev.repeat && !/^Arrow/.test(k0)) { ev.preventDefault(); return; } // a held key saves once
		var k = String(ev.key || '');
		var pid = +b.getAttribute('data-p');
		var key = b.getAttribute('data-a');
		var set = KEYS[k.toLowerCase()] || (k === 'Delete' || k === 'Backspace' ? 'unset' : '');
		if (set) { ev.preventDefault(); closePick(false); setFromGrid(pid, key, set); return; }
		var mv = { ArrowLeft: [0, -1], ArrowRight: [0, 1], ArrowUp: [-1, 0], ArrowDown: [1, 0] }[k];
		if (!mv) { return; }
		var rows = Array.prototype.slice.call(root.querySelectorAll('.zro-grid tbody tr'));
		var tr = b.closest('tr');
		var ri = rows.indexOf(tr);
		var cells = Array.prototype.slice.call(tr.querySelectorAll('.zro-cell-btn'));
		var ci = cells.indexOf(b);
		var nr = rows[ri + mv[0]];
		if (!nr) { return; }
		var to = nr.querySelectorAll('.zro-cell-btn')[ci + mv[1]];
		if (to) { ev.preventDefault(); to.focus(); }
	}

	/** The header rows stick one under the other: each row's top is the height of the rows above it, measured. */
	function stickHeads(body) {
		var rows = body.querySelectorAll('.zro-grid thead tr');
		var top = 0;
		Array.prototype.forEach.call(rows, function (tr) {
			var h = tr.getBoundingClientRect().height;
			if (!h) { return; } // not on screen yet: the stylesheet's tops stand
			Array.prototype.forEach.call(tr.children, function (th) { if (!th.classList.contains('zro-corner')) { th.style.top = top + 'px'; } });
			top += h;
		});
	}

	function chip(p) {
		var k = kit();
		// The kit's badge only for someone the kit knows: for anyone else it draws the shared-kiosk shape with the raw id.
		if (k && k.person && k.personChip && k.person(p.id)) {
			try { return k.personChip(p.id, { size: 'sm', title: p.name }); } catch (e) { /* the initials below */ }
		}
		return '<span class="zro-ini" aria-hidden="true">' + esc(p.initials) + '</span>';
	}

	/* ------------------------------------------------------------------ one person */

	function drawPerson(body) {
		var p = personOf(S.person) || S.data.people[0];
		S.person = +p.id;
		var h = '';
		if (S.data.people.length > 1) {
			h += '<div class="zro-person-head"><label class="zro-muted" for="zro-who">Person</label><select id="zro-who">'
				+ S.data.people.map(function (q) { return '<option value="' + q.id + '"' + (+q.id === +p.id ? ' selected' : '') + '>' + esc(q.name + (q.me ? ' (you)' : '')) + '</option>'; }).join('')
				+ '</select>' + (canEdit() ? '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-copy>Copy from…</button>' : '') + '</div>';
		}
		var groups = {};
		var order = [];
		abilities().forEach(function (a) {
			if (!groups[a.group]) { groups[a.group] = { label: a.group_label, items: [] }; order.push(a.group); }
			groups[a.group].items.push(a);
		});
		order.forEach(function (g) {
			h += '<section class="zro-group" aria-label="' + esc(groups[g].label) + '"><h3 class="zro-group-title">' + esc(groups[g].label) + '</h3>';
			groups[g].items.forEach(function (a) {
				if (a.group === 'item') { h += itemRows(p, a); return; }
				var c = cellOf(p, a.key);
				var l = c ? c.level : '';
				var sub = [];
				if (c && c.since) { sub.push('since ' + c.since); }
				if (c && c.note) { sub.push(c.note); }
				if (c && canEdit()) { sub.push('set by ' + c.by + ', ' + c.at); }
				h += '<div class="zro-row"><div class="zro-row-main"><span class="zro-row-label">' + esc(a.label) + '</span>'
					+ (sub.length ? '<span class="zro-row-sub">' + esc(sub.join(' · ')) + '</span>' : '') + '</div><div class="zro-row-side">';
				if (canEdit()) {
					h += '<div class="zro-seg" role="group" aria-label="' + esc(a.label) + '">' + LEVELS.map(function (L) {
						return '<button type="button" class="is-' + L[0] + '" data-set="' + L[0] + '" data-a="' + esc(a.key) + '" aria-pressed="' + (l === L[0]) + '"><span aria-hidden="true">' + L[2] + '</span> ' + esc(L[1]) + '</button>';
					}).join('') + '</div>'
						+ '<button type="button" class="zro-icon-btn" data-edit="' + esc(a.key) + '" aria-label="Note and since date for ' + esc(a.label) + '" title="Note and since date">✎</button>';
				} else {
					h += mark(l);
				}
				h += '</div></div>';
			});
			h += '</section>';
		});
		body.innerHTML = h;
		var sel = body.querySelector('#zro-who');
		if (sel) { sel.addEventListener('change', function () { S.person = +sel.value; draw(); }); }
		var cp = body.querySelector('[data-copy]');
		if (cp) { cp.addEventListener('click', function () { openCopy(p); }); }
		Array.prototype.forEach.call(body.querySelectorAll('[data-set]'), function (b) {
			b.addEventListener('click', function () {
				var key = b.getAttribute('data-a');
				var want = b.getAttribute('data-set');
				var c = cellOf(p, key);
				if ((c ? c.level : '') === want) { return; }
				busy(b, function () { return save(p, key, want, c ? c.note : '', c ? c.since : '', true); }).catch(function (e) { sayErr(e); });
			});
		});
		Array.prototype.forEach.call(body.querySelectorAll('[data-edit]'), function (b) {
			b.addEventListener('click', function () { openCell(p.id, b.getAttribute('data-edit'), b); });
		});
	}

	/** One item in the person view: its name, then a row per role (Sell / Install / Repair). */
	function itemRows(p, a) {
		var h = '<div class="zro-item-block"><div class="zro-item-name">' + esc(a.label) + (a.addon ? ' <span class="zro-addon">add-on</span>' : '') + '</div>';
		(a.roles || []).forEach(function (r) {
			var c = cellOf(p, r.key);
			var l = c ? c.level : '';
			var sub = [];
			if (c && c.since) { sub.push('since ' + c.since); }
			if (c && c.note) { sub.push(c.note); }
			if (c && canEdit()) { sub.push('set by ' + c.by + ', ' + c.at); }
			h += '<div class="zro-row zro-row--role zro-row--' + esc(r.role) + '"><div class="zro-row-main"><span class="zro-row-label">' + esc(r.label) + '</span>'
				+ (sub.length ? '<span class="zro-row-sub">' + esc(sub.join(' · ')) + '</span>' : '') + '</div><div class="zro-row-side">';
			if (canEdit()) {
				h += '<div class="zro-seg" role="group" aria-label="' + esc(a.label + ', ' + r.label) + '">' + LEVELS.map(function (L) {
					return '<button type="button" class="is-' + L[0] + '" data-set="' + L[0] + '" data-a="' + esc(r.key) + '" aria-pressed="' + (l === L[0]) + '"><span aria-hidden="true">' + L[2] + '</span> ' + esc(L[1]) + '</button>';
				}).join('') + '</div>'
					+ '<button type="button" class="zro-icon-btn" data-edit="' + esc(r.key) + '" aria-label="Note and since date for ' + esc(a.label + ', ' + r.label) + '" title="Note and since date">✎</button>';
			} else {
				h += mark(l);
			}
			h += '</div></div>';
		});
		return h + '</div>';
	}

	/* ------------------------------------------------------------------ saving a cell */

	/** POST one cell; on success update the local copy and redraw. undoable: offer Undo in the toast. */
	function save(p, key, level, note, since, undoable) {
		var before = cellOf(p, key);
		return api('cell', { method: 'POST', body: { user: p.id, ability: key, level: level, note: note || '', since: since || '' } }).then(function (r) {
			if (!p.cells || Array.isArray(p.cells)) { p.cells = {}; }
			if (r.cell) { p.cells[key] = r.cell; } else { delete p.cells[key]; }
			draw();
			var a = colOf(key);
			if (r.changed) {
				var msg = p.name + ' — ' + (a ? a.label : key) + ': ' + WORD[r.cell ? r.cell.level : '']
					// cleared: the row goes with its note and date; Undo brings them back
					+ (!r.cell && before && (before.note || before.since) ? ' (its note and date were removed too)' : '');
				say(msg, undoable ? { action: 'Undo', onAction: function () {
					// no cell before: back to not marked ('unset'), not an explicit Can't
					save(p, key, before ? before.level : 'unset', before ? before.note : '', before ? before.since : '', false).catch(function (e) { sayErr(e); });
				} } : { kind: 'success' });
			}
			return r;
		});
	}

	function openCell(pid, key, from) {
		var p = personOf(pid);
		var a = colOf(key);
		var k = kit();
		if (!p || !a || !k) { return; }
		var c = cellOf(p, key) || { level: '', note: '', since: '' };
		if (!canEdit()) {
			k.sheet({ title: a.label, body: '<div class="zro-form"><p>' + esc(p.name) + ': ' + mark(c.level) + '</p>' + (c.since ? '<p>Since ' + esc(c.since) + '</p>' : '') + (c.note ? '<p>' + esc(c.note) + '</p>' : '') + '</div>', actions: [{ label: 'Close' }] });
			return;
		}
		var pick = c.level || 'cannot';
		var body = document.createElement('div');
		body.className = 'zro-form';
		body.innerHTML = '<p>' + esc(p.name) + (a.type_label ? ' <span class="zro-help">(' + esc(a.type_label) + ')</span>' : '') + '</p>'
			+ '<div class="zro-seg" role="group" aria-label="' + esc(a.label) + '">' + LEVELS.map(function (L) {
				return '<button type="button" class="is-' + L[0] + '" data-pick="' + L[0] + '" aria-pressed="' + (pick === L[0]) + '"><span aria-hidden="true">' + L[2] + '</span> ' + esc(L[1]) + '</button>';
			}).join('') + '</div>'
			+ (c.level ? '' : '<p class="zro-help">Not marked yet, which reads as Can’t.</p>')
			+ '<label for="zro-since">Since (optional)</label><input type="date" id="zro-since" max="' + esc(C.today || '') + '" value="' + esc(c.since || '') + '">'
			+ '<label for="zro-note">Note (optional)</label><textarea id="zro-note" maxlength="300" placeholder="For example: only with a spotter">' + esc(c.note || '') + '</textarea>'
			+ '<p class="zro-help">Every change is kept in History.</p>';
		Array.prototype.forEach.call(body.querySelectorAll('[data-pick]'), function (b) {
			b.addEventListener('click', function () {
				pick = b.getAttribute('data-pick');
				Array.prototype.forEach.call(body.querySelectorAll('[data-pick]'), function (x) { x.setAttribute('aria-pressed', String(x === b)); });
			});
		});
		k.sheet({
			title: a.label,
			body: body,
			actions: [{ label: 'Cancel' }, { label: 'Save', kind: 'primary', onClick: function () {
				return save(p, key, pick, body.querySelector('#zro-note').value, body.querySelector('#zro-since').value, true);
			} }],
			onClose: function () {
				var again = from && document.body.contains(from) ? from
					: root.querySelector('.zro-cell-btn[data-p="' + p.id + '"][data-a="' + key + '"]') || root.querySelector('[data-edit="' + key + '"]');
				if (again && again.focus) { again.focus({ preventScroll: true }); }
			},
		});
	}

	/* ------------------------------------------------------------------ copy from */

	function openCopy(p) {
		var k = kit();
		var others = S.data.people.filter(function (q) { return +q.id !== +p.id; });
		if (!k || !others.length) { return; }
		var body = document.createElement('div');
		body.className = 'zro-form';
		body.innerHTML = '<label for="zro-copy-from">Start ' + esc(p.name) + ' from</label><select id="zro-copy-from">'
			+ others.map(function (q) { return '<option value="' + q.id + '">' + esc(q.name) + '</option>'; }).join('') + '</select>'
			+ '<p class="zro-help">Every ability in use becomes the same as that person’s (not marked counts as Can’t). Their notes and dates are not copied. You can change any answer afterwards, and each change is kept in History.</p>';
		k.sheet({
			title: 'Copy answers to ' + p.name,
			body: body,
			actions: [{ label: 'Cancel' }, { label: 'Copy', kind: 'primary', onClick: function () {
				var from = +body.querySelector('#zro-copy-from').value;
				return api('copy', { method: 'POST', body: { from: from, to: p.id } }).then(function (r) {
					say(r.message, { kind: 'success' });
					return load();
				});
			} }],
		});
	}

	/* ------------------------------------------------------------------ the list of abilities (admin tier) */

	function openAbilities() {
		var k = kit();
		if (!k) { return; }
		var body = document.createElement('div');
		body.className = 'zro-form';
		var sheet = k.sheet({ title: 'Abilities', body: body, actions: [{ label: 'Done' }], onClose: function () { draw(); } });

		function typeOptions(sel) {
			return '<option value="">(none)</option>' + (S.data.types || []).map(function (t) { return '<option value="' + esc(t.slug) + '"' + (t.slug === sel ? ' selected' : '') + '>' + esc(t.label) + '</option>'; }).join('');
		}
		function groupOptions(sel) {
			return (S.data.groups || []).map(function (g) { return '<option value="' + esc(g.key) + '"' + (g.key === sel ? ' selected' : '') + '>' + esc(g.label) + '</option>'; }).join('');
		}
		function render() {
			var h = '<p class="zro-help">Renaming keeps every answer. Retiring hides a column and keeps its answers and history; bring it back any time. Apps ask by the ability’s key, which never changes. Items come from the catalog: their names and order are edited there.</p>';
			(S.data.groups || []).forEach(function (g) {
				var items = (S.data.abilities || []).filter(function (a) { return a.group === g.key; });
				h += '<h3 class="zro-ab-group">' + esc(g.label) + '</h3><ul class="zro-ab-list">';
				items.forEach(function (a, i) {
					var moved = a.merged && a.merged.length;
					var extra = a.group === 'item' ? ' · ' + (a.roles || []).map(function (r) { return r.label; }).join(', ') + (a.addon ? ' · add-on' : '') : '';
					if (moved) {
						h += '<li class="is-retired"><span class="zro-ab-name">' + esc(a.label) + '<small>' + esc(a.key) + ' · moved into ' + esc(a.merged.map(function (t) { return t.item_label; }).join(', ')) + ' (still answers when asked by its key)</small></span></li>';
						return;
					}
					var isItem = a.group === 'item';
					h += '<li class="' + (a.retired ? 'is-retired' : '') + '"><span class="zro-ab-name">' + esc(a.label) + (a.retired ? (isItem ? ' (not tracked)' : ' (retired)') : '')
						+ '<small>' + esc(a.key + (a.type_label ? ' · ' + a.type_label : '') + (a.job_role ? ' · ' + a.job_role : '') + extra) + '</small></span>'
						+ (isItem ? '' : '<button type="button" class="zdz-btn zdz-btn--quiet zdz-btn--sm" data-up="' + esc(a.key) + '" aria-label="Move ' + esc(a.label) + ' up"' + (i === 0 ? ' disabled' : '') + '>↑</button>'
						+ '<button type="button" class="zdz-btn zdz-btn--quiet zdz-btn--sm" data-down="' + esc(a.key) + '" aria-label="Move ' + esc(a.label) + ' down"' + (i === items.length - 1 ? ' disabled' : '') + '>↓</button>')
						+ '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-rename="' + esc(a.key) + '">Edit</button>'
						+ '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-retire="' + esc(a.key) + '" data-to="' + (a.retired ? '0' : '1') + '">' + (a.retired ? (isItem ? 'Track again' : 'Bring back') : (isItem ? 'Stop tracking' : 'Retire')) + '</button></li>';
				});
				h += '</ul>';
			});
			h += '<h3 class="zro-ab-group">Add an ability</h3>'
				+ '<label for="zro-new-label">Name</label><input type="text" id="zro-new-label" maxlength="60" placeholder="For example: Commercial jobs">'
				+ '<label for="zro-new-group">Group</label><select id="zro-new-group">' + groupOptions('work').replace(/<option value="item"[^>]*>[^<]*<\/option>/, '') + '</select>'
				+ '<div data-typewrap><label for="zro-new-type">Kind of project (from Jobs)</label><select id="zro-new-type">' + typeOptions('') + '</select></div>'
				+ '<div data-stepwrap><label for="zro-new-step">The Jobs step it stands for</label><select id="zro-new-step">' + stepOptions('') + '</select></div>'
				+ '<p><button type="button" class="zdz-btn zdz-btn--primary" data-add>Add</button></p>';
			body.innerHTML = h;
			var grp = body.querySelector('#zro-new-group');
			grp.value = 'work';
			var tw = body.querySelector('[data-typewrap]');
			var sw = body.querySelector('[data-stepwrap]');
			var syncType = function () { tw.hidden = grp.value !== 'work' || !(S.data.types || []).length; sw.hidden = grp.value !== 'task'; };
			grp.addEventListener('change', syncType);
			syncType();
			body.querySelector('[data-add]').addEventListener('click', function (ev) {
				var label = body.querySelector('#zro-new-label').value.trim();
				if (!label) { sheet.status('Give the ability a name.', 'error'); return; }
				var data = { label: label, group: grp.value, type: grp.value === 'work' ? body.querySelector('#zro-new-type').value : '', job_role: grp.value === 'task' ? body.querySelector('#zro-new-step').value : '' };
				run(ev.currentTarget, data, 'ability', '');
			});
			Array.prototype.forEach.call(body.querySelectorAll('[data-retire]'), function (b) {
				b.addEventListener('click', function () { run(b, { key: b.getAttribute('data-retire'), retired: b.getAttribute('data-to') }, 'ability/retire', b.getAttribute('data-to') === '1' ? 'Retired' : 'Brought back'); });
			});
			Array.prototype.forEach.call(body.querySelectorAll('[data-up],[data-down]'), function (b) {
				b.addEventListener('click', function () { run(b, { key: b.getAttribute('data-up') || b.getAttribute('data-down'), dir: b.hasAttribute('data-up') ? -1 : 1 }, 'ability/move', ''); });
			});
			Array.prototype.forEach.call(body.querySelectorAll('[data-rename]'), function (b) {
				b.addEventListener('click', function () { editOne(b.getAttribute('data-rename')); });
			});
		}
		function stepOptions(sel) {
			return '<option value="">(none)</option>' + (S.data.jobRoles || []).map(function (r) { return '<option value="' + esc(r) + '"' + (r === sel ? ' selected' : '') + '>' + esc(r) + '</option>'; }).join('');
		}
		function itemFields(id, a) {
			var have = a ? (a.roles || []).map(function (r) { return r.role; }) : ['sell', 'install', 'repair'];
			return '<div data-itemwrap><fieldset class="zro-roles"><legend>What people do with it</legend>'
				+ (S.data.roles || []).map(function (r) { return '<label><input type="checkbox" data-role="' + esc(r.key) + '" id="' + id + '-role-' + esc(r.key) + '"' + (have.indexOf(r.key) >= 0 ? ' checked' : '') + '> ' + esc(r.label) + '</label>'; }).join('')
				+ '</fieldset><label class="zro-check"><input type="checkbox" id="' + id + '-addon"' + (a && a.addon ? ' checked' : '') + '> An add-on (fitted to another item)</label>'
				+ '<p class="zro-help">An item gets a column for each of these. Unticking one keeps its answers; they come back if you tick it again.</p></div>';
		}
		function readRoles(id) {
			return (S.data.roles || []).map(function (r) { return r.key; }).filter(function (k2) { var x = body.querySelector('#' + id + '-role-' + k2); return x && x.checked; });
		}
		function editOne(key) {
			var a = abilityOf(key);
			if (!a) { return; }
			var isItem = a.group === 'item';
			body.innerHTML = (isItem ? '<p><strong>' + esc(a.label) + '</strong></p><input type="hidden" id="zro-ed-group" value="item">'
					: '<label for="zro-ed-label">Name</label><input type="text" id="zro-ed-label" maxlength="60" value="' + esc(a.label) + '">'
					+ '<label for="zro-ed-group">Group</label><select id="zro-ed-group">' + groupOptions(a.group).replace(/<option value="item"[^>]*>[^<]*<\/option>/, '') + '</select>'
					+ '<div data-typewrap><label for="zro-ed-type">Kind of project (from Jobs)</label><select id="zro-ed-type">' + typeOptions(a.type) + '</select></div>'
					+ '<div data-stepwrap><label for="zro-ed-step">The Jobs step it stands for</label><select id="zro-ed-step">' + stepOptions(a.job_role || '') + '</select></div>')
				+ (isItem ? itemFields('zro-ed', a) : '')
				+ '<p class="zro-help">Key: ' + esc(a.key) + ' (never changes).</p>'
				+ '<p><button type="button" class="zdz-btn zdz-btn--primary" data-save>Save</button> <button type="button" class="zdz-btn zdz-btn--secondary" data-back>Back</button></p>';
			var grp = body.querySelector('#zro-ed-group');
			var tw = body.querySelector('[data-typewrap]');
			var sw = body.querySelector('[data-stepwrap]');
			var syncType = function () {
				if (tw) { tw.hidden = grp.value !== 'work' || !(S.data.types || []).length; }
				if (sw) { sw.hidden = grp.value !== 'task'; }
			};
			if (grp.tagName === 'SELECT') { grp.addEventListener('change', syncType); }
			syncType();
			body.querySelector('[data-back]').addEventListener('click', render);
			body.querySelector('[data-save]').addEventListener('click', function (ev) {
				var data = { key: key };
				if (!isItem) {
					data.label = body.querySelector('#zro-ed-label').value;
					data.group = grp.value;
					data.type = grp.value === 'work' ? body.querySelector('#zro-ed-type').value : '';
					data.job_role = grp.value === 'task' ? body.querySelector('#zro-ed-step').value : '';
				} else {
					data.roles = readRoles('zro-ed');
					data.addon = body.querySelector('#zro-ed-addon').checked ? 1 : 0;
					if (!data.roles.length) { sheet.status('Tick at least one of Sell, Install and Repair.', 'error'); return; }
				}
				run(ev.currentTarget, data, 'ability', 'Saved');
			});
		}
		function run(btn, data, route, done) {
			sheet.status('', '');
			busy(btn, function () {
				return api(route, { method: 'POST', body: data }).then(function (r) {
					S.data.abilities = r.abilities;
					render();
					if (done || r.message) { sheet.status(r.message || done, 'success'); }
				});
			}).catch(function (e) { sheet.status(e.message, 'error'); });
		}
		render();
	}

	/* ------------------------------------------------------------------ history */

	function histLine(r) {
		var when = '<time>' + esc(r.at) + ' · ' + esc(r.by) + '</time>';
		if (r.source === 'ability') { return when + esc(r.detail); }
		var h = when + (r.person ? '<strong>' + esc(r.person) + '</strong> — ' : '') + esc(r.ability) + ': ' + mark(r.from) + ' → ' + mark(r.to);
		var bits = [];
		if (r.toSince !== r.fromSince) { bits.push(r.toSince ? 'since ' + r.toSince : 'since date cleared'); }
		if (r.toNote !== r.fromNote) { bits.push(r.toNote ? 'note: “' + r.toNote + '”' : 'note cleared'); }
		if (r.detail) { bits.push(r.detail); }
		return h + (bits.length ? '<br><span class="zro-muted">' + esc(bits.join(' · ')) + '</span>' : '');
	}

	function openHistory(pid) {
		var k = kit();
		if (!k) { return; }
		var p = pid ? personOf(pid) : null;
		var body = document.createElement('div');
		body.innerHTML = loadingHtml('Loading history…');
		k.sheet({ title: p ? 'History — ' + p.name : 'History', body: body, actions: [{ label: 'Close' }] });
		var rows = [];
		function page(before) {
			return api('history?limit=50' + (p ? '&user=' + p.id : '') + (before ? '&before=' + before : '')).then(function (r) {
				rows = rows.concat(r.rows || []);
				body.innerHTML = rows.length ? '<ul class="zro-hist">' + rows.map(function (x) { return '<li>' + histLine(x) + '</li>'; }).join('') + '</ul>'
					+ (r.more ? '<p><button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-more>Older</button></p>' : '')
					: '<p class="zro-muted">No changes yet.</p>';
				var m = body.querySelector('[data-more]');
				if (m) { m.addEventListener('click', function () { busy(m, function () { return page(rows[rows.length - 1].id); }).catch(function (e) { sayErr(e); }); }); }
			});
		}
		page(0).catch(function (e) { body.innerHTML = '<p class="zro-err">' + esc(e.message) + '</p>'; });
	}

	/* ------------------------------------------------------------------ start */

	function start() {
		root = document.getElementById('zro-app');
		if (!root || root.getAttribute('data-zro-init')) { return; }
		root.setAttribute('data-zro-init', '1');
		if (!kit()) {
			root.innerHTML = '<p class="zro-err">Team Roles needs a newer Zorderz theme. Reload the app.</p>';
			return;
		}
		frame();
		load();
		document.addEventListener('zdz_app_focus', function (ev) {
			if (ev && ev.detail && ev.detail.appId === 'roles' && S.data) { load(); }
		});
	}

	// The header rows' heights change when labels re-wrap (a resize, a rotation, a late font): measure again.
	var reStick = null;
	function onResize() {
		if (reStick) { clearTimeout(reStick); }
		reStick = setTimeout(function () { if (root && root.querySelector('.zro-grid')) { stickHeads(root); } }, 120);
	}
	window.addEventListener('resize', onResize);
	if (document.fonts && document.fonts.ready) { document.fonts.ready.then(onResize).catch(function () {}); }

	if (document.getElementById('zro-app')) { start(); }
	document.addEventListener('zdz_widgets_rendered', start);
	document.addEventListener('DOMContentLoaded', start);
})();
