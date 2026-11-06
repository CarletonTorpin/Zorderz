/* Territories — the app in its card. Vanilla JS on the platform's kit of parts (ZDZ.ui: rest, sheet, busy, toast,
   loadingHtml). Who may see or change what is decided on the server; this file draws what it is sent and escapes every
   word it did not write (names, ZIPs, Team Roles' notes, reasons).
   Views: Territories (each person's ZIPs, the zones), Rules (the sentences, the words table), Shadow (what the rules
   would have chosen next to what happened, and why), History (with a way to put an earlier list of rules back). A
   salesperson sees their own ZIPs only. Rules and Shadow each have two stages: New stubs (who sells it) and Won jobs
   (who installs it, suggested to the salesperson in the Jobs app). The kinds of work are Team Roles' items. */
(function () {
	'use strict';

	var C = window.ZTER_CONFIG || {};
	var LEVEL = { can: ['✓', 'Can'], learning: ['◐', 'Learning'], cannot: ['✕', 'Can’t'] };
	var REPAIR = { any: 'repair or new', yes: 'repairs only', no: 'new work only' };
	var WHO = {
		stub: { territory: 'the person whose territory has the ZIP', person: 'one person', anyone: 'anyone who can sell it (Team Roles)', 'default': 'keep the asking app’s own pick', refer: 'send for a referral, when the ZIP is outside every service area' },
		install: { territory: 'the person whose territory has the ZIP', person: 'one person', anyone: 'anyone who can install it (Team Roles)', 'default': 'no suggestion', refer: 'send for a referral, when the ZIP is outside every service area' }
	};
	var STAGES = [['stub', 'New stubs', 'who sells it'], ['install', 'Won jobs', 'who installs it']];

	var root = null;
	var S = { data: null, view: '', days: 7, tests: false, dec: null, stage: 'stub' };

	function kit() { return (window.ZDZ && window.ZDZ.ui) ? window.ZDZ.ui : null; }
	function api(path, opts) {
		var k = kit();
		if (!k) { return Promise.reject(new Error('Territories needs a newer Zorderz theme. Reload the app.')); }
		path = (C.restPath || 'zorderz/v1/territories/') + path;
		if (window.zdzData && /[?&]rest_route=/.test(String(window.zdzData.apiUrl || ''))) { path = path.replace('?', '&'); }
		return k.rest(path, opts || {});
	}
	function say(msg) { var k = kit(); if (k) { k.toast(msg); } }
	function sayErr(err, retry) { var k = kit(); if (k) { k.toastError(err, retry); } }
	function busy(btn, work) { var k = kit(); return k ? k.busy(btn, work) : Promise.resolve(work()); }
	function loadingHtml(label) { var k = kit(); return k && k.loadingHtml ? k.loadingHtml(label) : '<p>' + esc(label) + '</p>'; }
	function sheet(o) { var k = kit(); return k ? k.sheet(o) : null; }
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
	}
	function $(sel, el) { return (el || root).querySelector(sel); }
	function $$(sel, el) { return Array.prototype.slice.call((el || root).querySelectorAll(sel)); }
	function on(el, ev, fn) { if (el) { el.addEventListener(ev, fn); } }
	function D() { return S.data || {}; }
	function personBy(id) { var p = D().people || []; for (var i = 0; i < p.length; i++) { if (+p[i].id === +id) { return p[i]; } } return null; }
	function teamBy(id) { var t = D().team || D().people || []; for (var i = 0; i < t.length; i++) { if (+t[i].id === +id) { return t[i]; } } return null; }
	function abilityLabel(key) {
		var a = (D().items || []).concat(D().abilities || []);
		for (var i = 0; i < a.length; i++) { if (a[i].key === key) { return a[i].label; } }
		return String(key || '').replace(/_/g, ' ');
	}
	function isInstall() { return S.stage === 'install'; }
	function rulesOf(d) { d = d || D(); return (isInstall() ? d.installRules : d.rules) || { version: 0, rows: [] }; }
	function stageQ(sep) { return (sep || '?') + 'stage=' + S.stage; }
	function stageSwitch() {
		if (!D().installRules) { return ''; }
		return '<div class="zter-stages" role="radiogroup" aria-label="Which rules">' + STAGES.map(function (st) {
			return '<button type="button" role="radio" class="zter-stage" data-stage="' + st[0] + '" aria-checked="' + (S.stage === st[0]) + '"><strong>' + st[1] + '</strong><span>' + st[2] + '</span></button>';
		}).join('') + '</div>';
	}
	function bindStages(redraw) {
		$$('[data-stage]').forEach(function (b) { on(b, 'click', function () { if (S.stage !== b.getAttribute('data-stage')) { S.stage = b.getAttribute('data-stage'); redraw(); } }); });
	}
	function typeLabel(key) { var t = D().types || []; for (var i = 0; i < t.length; i++) { if (t[i].key === key) { return t[i].label; } } return key; }
	function rowNo(id) { var w = D().words || []; for (var i = 0; i < w.length; i++) { if (w[i].id === id) { return '#' + (i + 1); } } return '(since changed)'; }
	function liveCount(d) { return ((d || {}).rows || []).filter(function (r) { return r.live; }).length; }
	function when(utc) {
		if (!utc) { return ''; }
		var d = new Date(String(utc).replace(' ', 'T') + 'Z');
		return isNaN(d) ? String(utc) : d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
	}
	function badge(p) {
		var ini = p && p.initials ? p.initials : '?';
		var hex = /^#[0-9a-f]{3}([0-9a-f]{3})?$/i;
		var style = p && hex.test(p.color || '') ? ' style="--zter-p:' + p.color + (hex.test(p.ink || '') ? ';--zter-pi:' + p.ink : '') + '"' : '';
		return '<span class="zter-badge' + (style ? ' zter-badge--c' : '') + '"' + style + ' aria-hidden="true">' + esc(ini) + '</span>';
	}
	function level(l) {
		var v = LEVEL[l] || ['·', 'Not checked'];
		return '<span class="zter-lv zdz-pair zter-lv--' + esc(l || 'none') + '"><span aria-hidden="true">' + v[0] + '</span> ' + v[1] + '</span>';
	}
	function ini(x) { return x ? esc(x) : '<span class="zter-muted">' + (isInstall() ? 'no one' : 'the asking app’s pick') + '</span>'; }

	/* ------------------------------------------------------------------ frame */

	function frame() {
		root.innerHTML = '<div class="zter-head"><div><h2 class="zter-title">Territories</h2><p class="zter-scope" data-scope></p></div>'
			+ '<div class="zter-views" role="tablist" aria-label="View" data-views></div></div>'
			+ '<div data-banners></div><div data-body>' + loadingHtml('Loading Territories…') + '</div>';
	}

	function load() {
		return api('state').then(function (d) {
			S.data = d;
			if (!S.view) { S.view = d.canReview ? 'territories' : 'mine'; }
			draw();
		}).catch(function (e) {
			$('[data-body]').innerHTML = '<p class="zter-err">' + esc(e.message) + '</p><p><button type="button" class="zdz-btn zdz-btn--secondary" data-retry>Try again</button></p>';
			on($('[data-retry]'), 'click', function () { frame(); load(); });
		});
	}

	function draw() {
		var d = D();
		var n = (d.people || []).filter(function (p) { return p.zips && p.zips.length; }).length;
		$('[data-scope]').textContent = d.canReview ? n + (n === 1 ? ' person holds' : ' people hold') + ' a territory' + (d.rules ? ' · stub rules v' + d.rules.version + (d.installRules ? ' · install rules v' + d.installRules.version : '') : '')
			: 'Your ZIP codes. Only the admin tier changes them.';
		var views = d.canReview ? [['territories', 'Territories'], ['rules', 'Rules'], ['shadow', 'Shadow']].concat(d.canEdit ? [['history', 'History']] : []) : [];
		$('[data-views]').innerHTML = views.map(function (v) {
			return '<button type="button" role="tab" data-view="' + v[0] + '" aria-selected="' + (S.view === v[0]) + '">' + v[1] + '</button>';
		}).join('');
		$$('[data-view]').forEach(function (b) { on(b, 'click', function () { S.view = b.getAttribute('data-view'); draw(); }); });
		drawBanners();
		var body = $('[data-body]');
		if (S.view === 'rules') { drawRules(body); }
		else if (S.view === 'shadow') { drawShadow(body); }
		else if (S.view === 'history') { drawHistory(body); }
		else if (S.view === 'territories') { drawTerritories(body); }
		else { drawMine(body); }
	}

	function drawBanners() {
		var d = D();
		var h = '';
		if (d.canReview) {
			var live = liveCount(d.rules), ilive = liveCount(d.installRules);
			h += live ? '<p class="zter-banner zter-banner--live" role="status"><strong>' + live + (live === 1 ? ' stub rule is' : ' stub rules are') + ' live.</strong> A new stub one of them matches gets its pick in the estimate app (the salesperson). The rest keep today’s pick.</p>'
				: '<p class="zter-banner" role="status"><strong>Shadow mode.</strong> Every stub rule is off: new stubs still get today’s pick, and the Shadow tab shows what the rules would have chosen.</p>';
			if (d.installRules) {
				h += ilive ? '<p class="zter-banner zter-banner--live" role="status"><strong>' + ilive + (ilive === 1 ? ' install rule is' : ' install rules are') + ' live.</strong> When a job is won, the Jobs app suggests their pick to the salesperson, who says Yes or picks Another.</p>'
					: '<p class="zter-banner" role="status"><strong>Installers: shadow mode.</strong> The Jobs app suggests no installer yet; the Shadow tab (Won jobs) shows who the install rules would suggest.</p>';
			}
			var tr = d.teamRoles || {};
			if (!tr.active) {
				h += '<p class="zter-banner zter-banner--warn" role="status"><strong>Team Roles is not active,</strong> so “can do” can’t be checked and every stub goes to the “everything else” rule.</p>';
			} else if (tr.items === false) {
				h += '<p class="zter-banner zter-banner--warn" role="status"><strong>Team Roles has no items to sell, install and repair yet</strong> (Team Roles 2.0.0 and its move), so no “can do” rule matches.</p>';
			} else if (!tr.anyCan) {
				h += '<p class="zter-banner zter-banner--warn" role="status"><strong>Nobody is marked Can to sell or install anything in Team Roles yet.</strong> Until the grid is filled in, the “can do” rules never match and the comparison only shows the fallback.</p>';
			}
			h += dupBanner(d);
		}
		$('[data-banners]').innerHTML = h;
	}

	/* Initials two accounts share: the estimate app then keeps its own pick for that person. */
	function dupBanner(d) {
		var dups = d.duplicates || [];
		if (!dups.length) { return ''; }
		var items = dups.map(function (x) {
			var who = x.people && x.people.length ? x.people.map(function (p) { return esc(p.name || '#' + p.id) + (p.on_team ? '' : ' <span class="zter-muted">(not on the team)</span>'); }).join(', ') : (x.count || 2) + ' accounts';
			return '<li><strong>' + esc(x.initials) + '</strong>: ' + who + (x.blocks ? '' : ' <span class="zter-muted">(nobody a rule can pick)</span>') + '</li>';
		}).join('');
		return '<div class="zter-banner zter-banner--warn" role="status"><p><strong>' + (dups.length === 1 ? 'These initials are' : 'These initials are each') + ' held by more than one account.</strong> The estimate app writes a rule’s pick only when exactly one account holds the initials, so it keeps its own pick for them. Give each person their own initials, or clear a former employee’s.</p><ul class="zter-plain">' + items + '</ul>'
			+ (d.userAdminUrl ? '<p><a class="zdz-btn zdz-btn--secondary zdz-btn--sm" href="' + esc(d.userAdminUrl) + '">Open User Management</a></p>' : '') + '</div>';
	}

	/* ------------------------------------------------------------------ your own ZIPs (everyone) */

	function drawMine(body) {
		var mine = (D().people || []).filter(function (p) { return p.me; })[0];
		var others = (D().people || []).filter(function (p) { return !p.me; });
		var h = '';
		if (!mine || !mine.zips.length) {
			h += '<p class="zter-muted">You don’t have a territory yet. The admin tier sets it in this app.</p>';
		} else {
			h += '<section class="zter-card"><div class="zter-person">' + badge(mine) + '<div><strong>' + esc(mine.name) + '</strong><p class="zter-muted">' + mine.zips.length + ' ZIP codes</p></div></div>' + chips(mine.zips, {}) + '</section>';
		}
		h += zonesHtml(D());
		if (others.length) {
			h += '<h3 class="zter-h3">Your teammates</h3>' + others.map(function (p) {
				return '<section class="zter-card"><div class="zter-person">' + badge(p) + '<div><strong>' + esc(p.initials) + '</strong><p class="zter-muted">' + p.zips.length + ' ZIP codes</p></div></div>' + chips(p.zips, {}) + '</section>';
			}).join('');
		}
		body.innerHTML = h;
	}

	/* ------------------------------------------------------------------ zones */
	// Where each kind of work is offered (service) and named areas (area), each visible to staff or to the admin tier
	// only. The server decides who sees which (the state route, territories.zones); this draws what it is sent.

	// as ZTER_Zones::contains reads a zone (prefixes, "every ZIP except these", no US ZIP: no-go zones only)
	function inZone(x, z) {
		if (!z) { return !!x.nozip; }
		if (x.everywhere) { return true; }
		var hit = (x.zips || []).indexOf(z) >= 0 || (x.prefixes || []).some(function (p) { return z.indexOf(p) === 0; });
		return x.outside ? !hit : hit;
	}
	function zonesAt(z) {
		return (D().zones || []).filter(function (x) { return inZone(x, z); });
	}
	function zoneWhere(x) {
		var list = (x.zips || []).length + (x.prefixes || []).length;
		var named = (x.zips || []).concat((x.prefixes || []).map(function (p) { return p + '…'; }));
		var w = x.everywhere ? 'everywhere' : (x.outside ? 'every ZIP except ' + (named.length <= 6 ? named.join(', ') : list + ' listed') : list + ' ZIP codes' + ((x.prefixes || []).length ? ' and prefixes' : ''));
		return w + (x.nozip ? ' · and a job with no US ZIP' : '');
	}
	function personName(id) {
		var p = (D().team || []).filter(function (t) { return +t.id === +id; })[0];
		return p ? p.name + (p.initials ? ' (' + p.initials + ')' : '') : '#' + id;
	}
	function zoneBadge(x) {
		return badge({ initials: x.short, color: x.color, ink: x.ink || '' });
	}
	function zonesHtml(d) {
		var zs = d.zones || [];
		if (!zs.length && !d.canEdit) { return ''; }
		var h = '<h3 class="zter-h3">Zones</h3><p class="zter-muted">Where each kind of work is offered, and named areas. They aren’t anyone’s territory. An install rule can stay inside the service area of the work (a won job outside it gets no suggestion from that rule). A job outside every service area of its work needs a referral (the referral list). A no-go zone is where one person is never given work; only the admin tier sees it.</p>';
		h += zs.map(function (x) {
			var kinds = (x.kinds || (x.abilities || []).map(abilityLabel)).join(', ');
			return '<section class="zter-card"><div class="zter-person">' + zoneBadge(x) + '<div class="zter-grow"><strong>' + esc(x.label) + '</strong> <span class="zter-muted">'
				+ (x.kind === 'service' ? 'service area' + (kinds ? ' · ' + esc(kinds) : '') : x.kind === 'nogo' ? 'no-go · never given work here: ' + esc((x.people || []).map(personName).join(', ')) : 'area')
				+ ' · ' + esc(zoneWhere(x))
				+ (x.visible === 'admin' ? ' · <span class="zter-lock">admin tier only</span>' : '') + '</span></div>'
				+ (d.canEdit ? '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-zone="' + esc(x.id) + '">Edit</button>' : '') + '</div>'
				+ (x.everywhere ? '' : chips((x.zips || []).concat((x.prefixes || []).map(function (p) { return p + '…'; })), {})) + '</section>';
		}).join('');
		if (!zs.length) { h += '<p class="zter-muted">No zones yet.</p>'; }
		if (d.canEdit) { h += '<p><button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-zone="">Add a zone</button></p>'; }
		return h;
	}
	function openZone(id) {
		var d = D(), zs = (d.zones || []).slice();
		var x = null, i;
		for (i = 0; i < zs.length; i++) { if (zs[i].id === id) { x = zs[i]; break; } }
		var isNew = !x;
		x = x || { id: '', label: '', short: '', kind: 'service', abilities: [], everywhere: false, zips: [], visible: 'staff', people: [], prefixes: [], outside: false, nozip: false };
		var team = (d.team || []).filter(function (t) { return t.initials; });
		var abs = (d.items && d.items.length ? d.items : (d.abilities || [])).slice();
		(x.abilities || []).forEach(function (k) { // a 1.x kind a zone still names stays ticked, so saving keeps it
			if (!abs.some(function (a) { return a.key === k; })) { abs.push({ key: k, label: abilityLabel(k) + ' (1.x kind)' }); }
		});
		var body = '<div class="zter-form">'
			+ '<label for="tz-label">Name</label><input id="tz-label" maxlength="40" value="' + esc(x.label) + '">'
			+ '<label for="tz-short">Short name on the map (1–4 letters)</label><input id="tz-short" maxlength="4" value="' + esc(x.short) + '">'
			+ '<fieldset class="zter-fs"><legend>Kind</legend>'
			+ '<label class="zter-check"><input type="radio" name="tz-kind" value="service"' + (x.kind === 'service' ? ' checked' : '') + '> Where a kind of work is offered</label>'
			+ '<label class="zter-check"><input type="radio" name="tz-kind" value="area"' + (x.kind === 'area' ? ' checked' : '') + '> A named area</label>'
			+ '<label class="zter-check"><input type="radio" name="tz-kind" value="nogo"' + (x.kind === 'nogo' ? ' checked' : '') + '> A no-go zone: where someone is never given work (admin tier only)</label></fieldset>'
			+ '<fieldset class="zter-fs" data-tz-ppl><legend>Never given work here</legend><div class="zter-checks">' + team.map(function (t) {
				return '<label class="zter-check"><input type="checkbox" data-tz-p="' + t.id + '"' + ((x.people || []).indexOf(+t.id) >= 0 ? ' checked' : '') + '> ' + esc(t.name + ' (' + t.initials + ')') + '</label>';
			}).join('') + '</div></fieldset>'
			+ (abs.length ? '<fieldset class="zter-fs" data-tz-abs><legend>Work offered here (Team Roles items)</legend><div class="zter-checks">' + abs.map(function (a) {
				return '<label class="zter-check"><input type="checkbox" data-tz-ab="' + esc(a.key) + '"' + ((x.abilities || []).indexOf(a.key) >= 0 ? ' checked' : '') + '> ' + esc(a.label) + '</label>';
			}).join('') + '</div></fieldset>' : '')
			+ '<fieldset class="zter-fs" data-tz-vis><legend>Who sees it</legend>'
			+ '<label class="zter-check"><input type="radio" name="tz-vis" value="staff"' + (x.visible !== 'admin' ? ' checked' : '') + '> Everyone on staff</label>'
			+ '<label class="zter-check"><input type="radio" name="tz-vis" value="admin"' + (x.visible === 'admin' ? ' checked' : '') + '> The admin tier only</label></fieldset>'
			+ '<label class="zter-check" data-tz-everybox><input type="checkbox" id="tz-every"' + (x.everywhere ? ' checked' : '') + '> Everywhere (every ZIP)</label>'
			+ '<div data-tz-ng><label class="zter-check"><input type="checkbox" id="tz-out"' + (x.outside ? ' checked' : '') + '> Every ZIP except the ones below (“outside the county”)</label>'
			+ '<label class="zter-check"><input type="checkbox" id="tz-nozip"' + (x.nozip ? ' checked' : '') + '> Also a job with no US ZIP (Mexico, or a stub with no ZIP)</label></div>'
			+ '<label for="tz-zips">ZIP codes (commas, spaces or one per line)<span data-tz-prehint>; three digits mean every ZIP that starts with them (919)</span></label><textarea id="tz-zips" rows="6" spellcheck="false">' + esc((x.zips || []).concat(x.prefixes || []).join(', ')) + '</textarea></div>';
		function rowsWith(nx) {
			var out = zs.slice();
			if (isNew) { if (nx) { out.push(nx); } return out; }
			out = out.filter(function (z) { return z.id !== x.id; });
			if (nx) { out.splice(i, 0, nx); }
			return out;
		}
		function save(rows, msg) {
			// the version this page loaded: a list someone else changed meanwhile is refused (409), not overwritten
			return api('zones', { method: 'POST', body: { rows: rows, version: d.zonesVersion || 0 } }).then(function () { say(msg); return load(); });
		}
		var actions = [{ label: 'Save', kind: 'primary', onClick: function () {
			var kind = (document.querySelector('input[name="tz-kind"]:checked') || {}).value || 'service';
			var nx = {
				id: x.id, label: document.getElementById('tz-label').value, short: document.getElementById('tz-short').value, kind: kind,
				abilities: kind === 'service' ? Array.prototype.slice.call(document.querySelectorAll('[data-tz-ab]')).filter(function (c) { return c.checked; }).map(function (c) { return c.getAttribute('data-tz-ab'); }) : [],
				visible: kind === 'nogo' ? 'admin' : ((document.querySelector('input[name="tz-vis"]:checked') || {}).value || 'staff'),
				everywhere: kind !== 'nogo' && document.getElementById('tz-every').checked, zips: document.getElementById('tz-zips').value, color: x.color || ''
			};
			if (kind === 'nogo') {
				nx.people = Array.prototype.slice.call(document.querySelectorAll('[data-tz-p]')).filter(function (c) { return c.checked; }).map(function (c) { return +c.getAttribute('data-tz-p'); });
				nx.outside = document.getElementById('tz-out').checked;
				nx.nozip = document.getElementById('tz-nozip').checked;
			}
			return save(rowsWith(nx), 'Zone saved.');
		} }];
		if (!isNew) { actions.push({ label: 'Remove', onClick: function () { return save(rowsWith(null), 'Zone removed.'); } }); }
		actions.push({ label: 'Cancel' });
		sheet({ title: isNew ? 'New zone' : 'Zone — ' + x.label, body: body, actions: actions });
		// An area has no kinds of work, and an everywhere zone no ZIP list: hide what does not apply.
		function fit() {
			var kind = (document.querySelector('input[name="tz-kind"]:checked') || {}).value;
			var ng = kind === 'nogo';
			var abBox = document.querySelector('[data-tz-abs]'), every = document.getElementById('tz-every'), zips = document.getElementById('tz-zips');
			if (abBox) { abBox.hidden = kind !== 'service'; }
			['[data-tz-ppl]', '[data-tz-ng]', '[data-tz-prehint]'].forEach(function (q) { var n = document.querySelector(q); if (n) { n.hidden = !ng; } });
			['[data-tz-vis]', '[data-tz-everybox]'].forEach(function (q) { var n = document.querySelector(q); if (n) { n.hidden = ng; } });
			if (every && zips) { zips.disabled = !ng && every.checked; }
		}
		Array.prototype.slice.call(document.querySelectorAll('input[name="tz-kind"], #tz-every')).forEach(function (n) { n.addEventListener('change', fit); });
		fit();
	}

	function chips(zips, shared) {
		return '<ul class="zter-chips" aria-label="ZIP codes">' + zips.map(function (z) {
			var s = shared[z] && shared[z] > 1;
			return '<li class="zter-chip' + (s ? ' zter-chip--shared' : '') + '"' + (s ? ' title="Shared by ' + shared[z] + ' people"' : '') + '>' + esc(z) + (s ? '<span class="zter-sr"> (shared)</span><span aria-hidden="true"> ⇄</span>' : '') + '</li>';
		}).join('') + '</ul>';
	}

	/* ------------------------------------------------------------------ territories */

	function drawTerritories(body) {
		var d = D();
		var count = {};
		(d.people || []).forEach(function (p) { (p.zips || []).forEach(function (z) { count[z] = (count[z] || 0) + 1; }); });
		var shared = Object.keys(count).filter(function (z) { return count[z] > 1; });
		var h = '';
		h += '<div class="zter-find"><label for="zter-find">Who covers a ZIP?</label><input id="zter-find" inputmode="numeric" maxlength="5" autocomplete="off" placeholder="5 digits"><span class="zter-find-out" data-find aria-live="polite"></span></div>';
		if (shared.length) {
			h += '<p class="zter-muted">' + shared.length + ' ZIP' + (shared.length === 1 ? ' is' : 's are') + ' covered by more than one person (⇄). The rules decide those: “can do” first, then taking turns.</p>';
		}
		var withZ = (d.people || []).filter(function (p) { return p.zips && p.zips.length; });
		var without = (d.people || []).filter(function (p) { return !p.zips || !p.zips.length; });
		h += withZ.map(function (p) {
			return '<section class="zter-card"><div class="zter-person">' + badge(p) + '<div class="zter-grow"><strong>' + esc(p.name) + '</strong> <span class="zter-muted">' + (p.initials ? esc(p.initials) : 'no initials') + ' · ' + p.zips.length + ' ZIP codes</span>'
				+ (p.initials ? '' : '<p class="zter-warn">No initials in User Management, so this person can’t be assigned work.</p>') + '</div>'
				+ (d.canEdit ? '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-edit="' + p.id + '">Edit</button>' : '') + '</div>' + chips(p.zips, count) + '</section>';
		}).join('');
		if (!withZ.length) { h += '<p class="zter-muted">No one has a territory yet.' + (d.canEdit ? ' Give someone ZIPs below.' : '') + '</p>'; }
		if (without.length && d.canEdit) {
			h += '<h3 class="zter-h3">No territory</h3><ul class="zter-plain">' + without.map(function (p) {
				return '<li>' + badge(p) + ' ' + esc(p.name) + ' <button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-edit="' + p.id + '">Give ZIPs</button></li>';
			}).join('') + '</ul>';
		}
		h += zonesHtml(d);
		if (d.canEdit) {
			h += '<h3 class="zter-h3">Settings</h3><div class="zter-card"><label class="zter-check"><input type="checkbox" data-set="peers"' + (d.settings.peers_visible ? ' checked' : '') + '> Salespeople may see each other’s territories (here and on maps other apps draw)</label>'
				+ '<p class="zter-muted">Reviewers see every decision and why, without changing anything (for whoever hands out jobs today).</p>'
				+ '<div class="zter-checks">' + (d.team || []).map(function (p) {
					return '<label class="zter-check"><input type="checkbox" data-rev="' + p.id + '"' + (d.settings.reviewers.indexOf(+p.id) >= 0 ? ' checked' : '') + '> ' + esc(p.name) + '</label>';
				}).join('') + '</div><p><button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-act="settings">Save settings</button></p></div>';
		}
		body.innerHTML = h;
		$$('[data-zone]').forEach(function (b) { on(b, 'click', function () { openZone(b.getAttribute('data-zone')); }); });
		$$('[data-edit]').forEach(function (b) { on(b, 'click', function () { openZips(+b.getAttribute('data-edit')); }); });
		on($('#zter-find'), 'input', function (e) {
			var z = String(e.target.value || '').replace(/\D/g, '');
			var out = $('[data-find]');
			if (z.length < 5) { out.textContent = ''; return; }
			var who = (d.people || []).filter(function (p) { return (p.zips || []).indexOf(z) >= 0; });
			var zs = zonesAt(z);
			out.textContent = (who.length ? who.map(function (p) { return p.name + (p.initials ? ' (' + p.initials + ')' : ''); }).join(', ') : 'Nobody — a stub there goes to the “everything else” rule.')
				+ (zs.length ? ' · Zones: ' + zs.map(function (x) { return x.label; }).join(', ') : '');
		});
		on($('[data-act="settings"]'), 'click', function (e) {
			var rev = $$('[data-rev]').filter(function (c) { return c.checked; }).map(function (c) { return +c.getAttribute('data-rev'); });
			busy(e.currentTarget, function () {
				return api('settings', { method: 'POST', body: { peers_visible: $('[data-set="peers"]').checked, reviewers: rev } }).then(function () { say('Settings saved.'); return load(); });
			}).catch(function (err) { sayErr(err); });
		});
	}

	function openZips(uid) {
		var p = personBy(uid);
		if (!p) { return; }
		var body = '<div class="zter-form"><p>' + badge(p) + ' <strong>' + esc(p.name) + '</strong></p>'
			+ '<label for="zter-zips">ZIP codes (commas, spaces or one per line)</label><textarea id="zter-zips" rows="8" spellcheck="false">' + esc((p.zips || []).join(', ')) + '</textarea>'
			+ '<p class="zter-muted">Overlaps are fine: the rules decide them.</p></div>';
		sheet({
			title: 'Territory — ' + p.name, body: body,
			actions: [{ label: 'Save', kind: 'primary', onClick: function () {
				var v = document.getElementById('zter-zips').value;
				return api('zips', { method: 'POST', body: { user_id: uid, zips: v } }).then(function (r) {
					say(r.added.length || r.removed.length ? 'Saved: ' + r.added.length + ' added, ' + r.removed.length + ' removed.' : 'No change.');
					return load();
				});
			} }, { label: 'Cancel' }]
		});
	}

	/* ------------------------------------------------------------------ rules */

	function drawRules(body) {
		var d = D();
		var rows = rulesOf(d).rows;
		var inst = isInstall();
		var h = stageSwitch()
			+ '<p class="zter-muted">' + (inst ? 'When a job is won (the estimate is accepted), the Jobs app asks who should install it and suggests that person to the salesperson, who says Yes or picks Another. These rules choose the suggestion.' : 'When the operator makes a new estimate stub, the estimate app asks who should sell it. These rules choose that person.') + '</p>'
			+ '<section class="zter-card zter-card--accent" data-golive aria-labelledby="zter-golive-h"><h3 class="zter-h3" id="zter-golive-h">Go-live checklist' + (inst ? ': won jobs' : ': new stubs') + '</h3><div data-golive-body>' + loadingHtml('Loading the checklist…') + '</div></section>'
			+ '<h3 class="zter-h3">The rules' + (inst ? ' for won jobs' : ' for new stubs') + '</h3><p class="zter-muted">In order; the first rule that matches decides. Each rule is off (shadow) until you turn it live from the checklist above.</p><ol class="zter-rules">';
		h += rows.map(function (r, i) {
			return '<li class="zter-rule"><div class="zter-rule-text"><span class="zter-rule-no">' + (i + 1) + '</span><span>' + esc(r.sentence) + '</span></div><div class="zter-rule-tools">'
				+ '<span class="zter-mode zdz-pair zter-mode--' + (r.live ? 'live' : 'shadow') + '">' + (r.live ? '● Live' : '○ Shadow') + '</span>'
				+ (d.canEdit ? '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-rule="' + i + '">Edit</button>'
					+ (!r.fallback && i > 0 ? '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-up="' + i + '" aria-label="Move rule ' + (i + 1) + ' up">↑</button>' : '')
					+ (!r.fallback && i < rows.length - 2 ? '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-down="' + i + '" aria-label="Move rule ' + (i + 1) + ' down">↓</button>' : '') : '')
				+ '</div></li>';
		}).join('') + '</ol>';
		if (d.canEdit) { h += '<p><button type="button" class="zdz-btn zdz-btn--secondary" data-act="add-rule">Add a rule</button></p>'; }
		h += '<h3 class="zter-h3">Words → item</h3><p class="zter-muted">How a line becomes one of Team Roles’ items. The catalog’s own names and aliases already name most lines; these rows are for what the catalog can’t tell apart. Whether it is new work or a repair is read from the line (repair, fix, broken…). First matching row wins. The same table serves both stages.' + (d.jobs ? '' : ' The Jobs app is not active here, so rows that name a kind of project never match.') + '</p>';
		h += '<div class="zter-tablewrap"><table class="zter-table"><thead><tr><th scope="col">#</th><th scope="col">Kind of project</th><th scope="col">Words</th><th scope="col">For</th><th scope="col">Means</th></tr></thead><tbody>'
			+ d.words.map(function (w, i) {
				return '<tr><td>' + (i + 1) + '</td><td>' + (w.type ? esc(typeLabel(w.type)) : '<span class="zter-muted">any</span>') + '</td><td>' + (w.words.length ? esc(w.words.join(', ')) : '<span class="zter-muted">—</span>') + '</td><td>' + esc(REPAIR[w.repair]) + '</td><td>' + esc(abilityLabel(w.item || w.ability)) + '</td></tr>';
			}).join('') + '</tbody></table></div>';
		h += '<p>' + (d.canEdit ? '<button type="button" class="zdz-btn zdz-btn--secondary" data-act="words">Edit the words</button> ' : '') + '<button type="button" class="zdz-btn zdz-btn--secondary" data-act="try">Try ' + (inst ? 'a won job' : 'a stub') + '</button></p>';
		h += '<div data-service></div>';
		body.innerHTML = h;
		bindStages(draw);
		loadChecklist();
		$$('[data-rule]').forEach(function (b) { on(b, 'click', function () { openRule(+b.getAttribute('data-rule')); }); });
		$$('[data-up]').forEach(function (b) { on(b, 'click', function (e) { move(+b.getAttribute('data-up'), -1, e.currentTarget); }); });
		$$('[data-down]').forEach(function (b) { on(b, 'click', function (e) { move(+b.getAttribute('data-down'), 1, e.currentTarget); }); });
		on($('[data-act="add-rule"]'), 'click', function () { openRule(-1); });
		on($('[data-act="words"]'), 'click', openWords);
		on($('[data-act="try"]'), 'click', openTry);
	}

	function plainRule(r) {
		return { id: r.id, words: r.words, kinds: r.kinds, zips: r.zips, who: r.who, person: r.person, need_can: r.need_can, live: r.live, fallback: r.fallback,
			all_kinds: !!r.all_kinds, unknown_only: !!r.unknown_only, in_area: !!r.in_area, with_rest: !!r.with_rest, seller_first: !!r.seller_first };
	}
	function postRules(rows) {
		return api('rules', { method: 'POST', body: { stage: S.stage, version: rulesOf().version, rows: rows.map(plainRule) } });
	}
	function saveRules(rows, btn) {
		return busy(btn, function () {
			return postRules(rows).then(function (r) { say('Rules saved (version ' + r.version + ').'); return load(); });
		});
	}
	function move(i, dir, btn) {
		var rows = rulesOf().rows.slice();
		var j = i + dir;
		if (j < 0 || j >= rows.length - 1) { return; }
		var t = rows[i]; rows[i] = rows[j]; rows[j] = t;
		saveRules(rows, btn).catch(function (e) { sayErr(e); });
	}

	function openRule(i) {
		var d = D();
		var inst = isInstall();
		var rows = rulesOf(d).rows.slice();
		var r = i >= 0 ? rows[i] : { id: '', words: [], kinds: [], zips: [], who: inst ? 'anyone' : 'territory', person: 0, need_can: true, live: false, fallback: false };
		var team = (d.team || []).filter(function (t) { return t.initials; });
		var items = d.items || [];
		var chk = function (id, on_, label, extra) { return '<label class="zter-check"' + (extra || '') + '><input type="checkbox" id="' + id + '"' + (on_ ? ' checked' : '') + '> ' + label + '</label>'; };
		var h = '<div class="zter-form">';
		if (!r.fallback) {
			h += '<fieldset data-tr-when><legend>When (all that are filled in)</legend>'
				+ '<label for="tr-words">A line mentions any of these words</label><input id="tr-words" value="' + esc(r.words.join(', ')) + '" placeholder="a word or phrase">'
				+ '<p class="zter-label">The work includes any of these items</p><div class="zter-checks">' + items.map(function (a) {
					return '<label class="zter-check"><input type="checkbox" data-kind="' + esc(a.key) + '"' + (r.kinds.indexOf(a.key) >= 0 ? ' checked' : '') + '> ' + esc(a.label) + (a.addon ? ' <span class="zter-muted">(add-on)</span>' : '') + '</label>';
				}).join('') + '</div>'
				+ chk('tr-all', r.all_kinds, 'Only when all of the work is these items')
				+ (inst ? '' : chk('tr-unknown', r.unknown_only, 'Only when a line doesn’t say what the work is (a bare “repair”, or a stub with only a ZIP)'))
				+ '<label for="tr-zips">The ZIP is one of</label><textarea id="tr-zips" rows="2" spellcheck="false">' + esc(r.zips.join(', ')) + '</textarea>'
				+ (inst ? chk('tr-area', r.in_area, 'Only inside the service area of the work (Zones)') : '')
				+ '</fieldset>';
		} else {
			h += '<p>This is the last rule: it catches everything the rules above don’t.</p>';
		}
		var who = WHO[S.stage];
		h += '<fieldset><legend>Then ' + (inst ? 'suggest' : 'give it to') + '</legend><select id="tr-who">' + Object.keys(who).filter(function (k) { return !(r.fallback && (k === 'territory' || k === 'anyone' || k === 'refer')); }).map(function (k) {
			return '<option value="' + k + '"' + (r.who === k ? ' selected' : '') + '>' + esc(who[k]) + '</option>';
		}).join('') + '</select>'
			+ '<p class="zter-muted" data-tr-refer>When every item on the job is offered only in service zones that don’t hold its ZIP (Zones). It names nobody: ' + (inst ? 'no suggestion' : 'the asking app keeps its own pick') + ', and the job is listed as needing a referral, where a person finds another company and opens the email to the customer. Nothing is sent by itself. A job with no ZIP, or a line that doesn’t say what the work is, is never a referral.</p>'
			+ '<div data-tr-person><label for="tr-person">Person</label><select id="tr-person"><option value="0">—</option>' + team.map(function (t) {
				return '<option value="' + t.id + '"' + (+r.person === +t.id ? ' selected' : '') + '>' + esc(t.name + ' (' + t.initials + ')') + '</option>';
			}).join('') + '</select></div>'
			+ (r.fallback ? '' : chk('tr-can', r.need_can, inst ? 'Only if they can install or repair every item on it (Team Roles)' : 'Only if they can sell every item on it (Team Roles)', ' data-tr-can'))
			+ (r.fallback ? '' : inst ? chk('tr-seller', r.seller_first, 'The salesperson first, when they can do it themselves', ' data-tr-seller')
					/* a won job's named installer can hand the rest on, as a stub's salesperson can */
					+ chk('tr-rest', r.with_rest, 'With other work on the job, also a second installer for the rest (the rules below pick them: two installers, “AB &amp; CD”)', ' data-tr-rest')
				: chk('tr-rest', r.with_rest, 'With other work on the stub, also the best person for the rest (two people: “AB &amp; CD”)', ' data-tr-rest'))
			+ '</fieldset><p class="zter-muted">' + (r.live ? '<strong>● Live.</strong> Saving keeps it live. Put it back to shadow from the go-live checklist.' : '<strong>○ Shadow</strong> (recorded only). ' + (i >= 0 ? 'Turn it live from the go-live checklist.' : 'A new rule starts in shadow; turn it live from the go-live checklist.')) + '</p></div>';
		var g = function (id) { return document.getElementById(id); };
		var acts = [{ label: 'Save', kind: 'primary', onClick: function (sh) {
			var b = function (id) { var el = g(id); return !!(el && el.checked); };
			var n = {
				id: r.id, fallback: r.fallback, who: g('tr-who').value, person: +g('tr-person').value, live: !!r.live, // the server keeps each rule's mode
				words: r.fallback ? [] : g('tr-words').value.split(',').map(function (x) { return x.trim(); }).filter(Boolean),
				kinds: r.fallback ? [] : $$('[data-kind]', sh.el).filter(function (c) { return c.checked; }).map(function (c) { return c.getAttribute('data-kind'); }),
				zips: r.fallback ? [] : g('tr-zips').value.split(/[\s,;]+/).filter(Boolean),
				need_can: r.fallback ? false : b('tr-can'),
				all_kinds: b('tr-all'), unknown_only: b('tr-unknown'), in_area: b('tr-area'), with_rest: b('tr-rest'), seller_first: b('tr-seller')
			};
			if (i >= 0) { rows[i] = n; } else { rows.splice(rows.length - 1, 0, n); }
			return postRules(rows).then(function (res) { say('Rules saved (version ' + res.version + ').'); return load(); });
		} }];
		if (i >= 0 && !r.fallback) {
			acts.push({ label: 'Delete', onClick: function () {
				rows.splice(i, 1);
				return postRules(rows).then(function (res) { say('Rule deleted (version ' + res.version + ').'); return load(); });
			} });
		}
		acts.push({ label: 'Cancel' });
		sheet({ title: (i >= 0 ? 'Rule ' + (i + 1) : 'New rule') + (inst ? ' (won jobs)' : ' (new stubs)'), body: h, actions: acts });
		// Show only what applies to the "then" picked: a person for "one person"; "can do" is the check itself for
		// "anyone"; "the rest of the work" only for a named person.
		function fit() {
			var w = (g('tr-who') || {}).value;
			var pBox = document.querySelector('[data-tr-person]'), can = document.querySelector('[data-tr-can]'), rest = document.querySelector('[data-tr-rest]');
			var when = document.querySelector('[data-tr-when]'), ref = document.querySelector('[data-tr-refer]');
			if (pBox) { pBox.hidden = w !== 'person'; }
			if (can) { can.hidden = w === 'anyone' || w === 'default' || w === 'refer'; }
			if (rest) { rest.hidden = w !== 'person'; }
			if (when) { when.hidden = w === 'refer'; }   // the referral rule is its own condition
			if (ref) { ref.hidden = w !== 'refer'; }
		}
		on(g('tr-who'), 'change', fit);
		fit();
	}

	function openWords() {
		var d = D();
		var items = d.items && d.items.length ? d.items : (d.abilities || []);
		var rows = d.words.map(function (w) { return { id: w.id, type: w.type, words: w.words.join(', '), repair: w.repair, item: w.item || w.ability || '' }; });
		var box = document.createElement('div');
		box.className = 'zter-form';
		function paint() {
			box.innerHTML = '<p class="zter-muted">Rows are checked from the top; the first that fits a line decides it. Kind of project and words both have to fit when both are set.</p>'
				+ rows.map(function (w, i) {
					var opts = items.slice();
					if (w.item && !opts.some(function (a) { return a.key === w.item; })) { opts.unshift({ key: w.item, label: abilityLabel(w.item) + ' (not in Team Roles)' }); }
					return '<fieldset class="zter-wrow"><legend>Row ' + (i + 1) + '</legend>'
						+ '<label for="ww-t' + i + '">Kind of project</label><select id="ww-t' + i + '" data-w="type" data-i="' + i + '"><option value="">any</option>' + (d.types || []).map(function (t) { return '<option value="' + t.key + '"' + (w.type === t.key ? ' selected' : '') + '>' + esc(t.label) + '</option>'; }).join('') + '</select>'
						+ '<label for="ww-w' + i + '">Words (any of)</label><input id="ww-w' + i + '" data-w="words" data-i="' + i + '" value="' + esc(w.words) + '">'
						+ '<label for="ww-r' + i + '">For</label><select id="ww-r' + i + '" data-w="repair" data-i="' + i + '">' + Object.keys(REPAIR).map(function (k) { return '<option value="' + k + '"' + (w.repair === k ? ' selected' : '') + '>' + REPAIR[k] + '</option>'; }).join('') + '</select>'
						+ '<label for="ww-a' + i + '">Means</label><select id="ww-a' + i + '" data-w="item" data-i="' + i + '">' + opts.map(function (a) { return '<option value="' + esc(a.key) + '"' + (w.item === a.key ? ' selected' : '') + '>' + esc(a.label) + '</option>'; }).join('') + '</select>'
						+ '<div class="zter-wtools">' + (i > 0 ? '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-wup="' + i + '">↑ Up</button>' : '') + '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-wdel="' + i + '">Remove</button></div></fieldset>';
				}).join('') + '<p><button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-wadd>Add a row</button></p>';
			$$('[data-w]', box).forEach(function (el) { on(el, 'change', function () { rows[+el.getAttribute('data-i')][el.getAttribute('data-w')] = el.value; }); });
			$$('[data-wup]', box).forEach(function (b) { on(b, 'click', function () { var i = +b.getAttribute('data-wup'); var t = rows[i - 1]; rows[i - 1] = rows[i]; rows[i] = t; paint(); }); });
			$$('[data-wdel]', box).forEach(function (b) { on(b, 'click', function () { rows.splice(+b.getAttribute('data-wdel'), 1); paint(); }); });
			on($('[data-wadd]', box), 'click', function () { rows.push({ id: '', type: '', words: '', repair: 'any', item: (items[0] ? items[0].key : '') }); paint(); });
		}
		paint();
		sheet({
			title: 'Words → item', body: box,
			actions: [{ label: 'Save', kind: 'primary', onClick: function () {
				$$('[data-w="words"]', box).forEach(function (el) { rows[+el.getAttribute('data-i')].words = el.value; });
				return api('words', { method: 'POST', body: { rows: rows } }).then(function () { say('Words saved.'); return load(); });
			} }, { label: 'Cancel' }]
		});
	}

	/* ------------------------------------------------------------------ the go-live checklist */

	function loadChecklist() {
		var box = $('[data-golive-body]');
		if (!box) { return; }
		var asked = S.stage;
		api('checklist' + stageQ()).then(function (c) {
			if (asked !== S.stage) { return; } // the stage was switched while this loaded
			S.check = c;
			box = $('[data-golive-body]');
			if (!box) { return; }
			var edit = !!D().canEdit;
			var inst = isInstall();
			var h = '<p class="zter-muted">Turn rules live one at a time. For each rule: what it decided in shadow on real ' + (inst ? 'won jobs' : 'stubs') + ' in the last ' + (+c.days || 7) + ' days, how many of those differ from what happened (' + (inst ? 'who the salesperson confirmed' : 'what the estimate app wrote') + ', or where a reviewer’s note says it went), and who it can choose.</p><ol class="zter-golive">';
			h += c.rules.map(function (r, i) {
				var ppl = '';
				if (r.who === 'default') {
					ppl = '<p class="zter-muted">' + (inst ? 'Suggests no one.' : 'Keeps the asking app’s own pick: it never chooses a person.') + '</p>';
				} else if (!r.people.length && !r.hiddenPeople) {
					ppl = '<p class="zter-warn">Nobody yet' + (r.who === 'territory' ? ': no one holds a territory' + (r.sentence.indexOf('the ZIP is') >= 0 ? ' with its ZIPs' : '') + '.' : r.who === 'anyone' ? ': no one is marked Can to ' + (inst ? 'install' : 'sell') + ' its items in Team Roles.' : '.') + '</p>';
				} else {
					ppl = '<p class="zter-label">Can choose</p><ul class="zter-gl-people">' + r.people.map(function (p) {
						var what = !p.assignable ? '<span class="zter-warn">no initials: skipped</span>'
							: p.hidden ? '<span class="zter-muted">Team Roles shows what they are marked for only to them, their crew lead and the admin tier</span>'
							: p.can.length ? 'Can ' + (inst ? 'install' : 'sell') + ': ' + esc(p.can.join(', ')) : '<span class="zter-warn">not marked Can to ' + (inst ? 'install' : 'sell') + ' ' + (r.sentence.indexOf('the work includes') >= 0 ? 'its items' : 'any item') + ' yet</span>';
						return '<li class="' + (p.able === false ? 'zter-gl-off' : '') + '">' + badge(p) + ' <span><strong>' + esc(p.name) + '</strong> <span class="zter-muted">' + esc(p.initials || '') + '</span> · ' + what + (p.dup ? ' · <span class="zter-warn">initials shared</span>' : '') + '</span></li>';
					}).join('') + '</ul>';
				}
				if (r.hiddenPeople) { ppl += '<p class="zter-muted">And ' + (+r.hiddenPeople) + ' more whose Team Roles marks only they, their crew lead and the admin tier see.</p>'; }
				var warn = r.warnings.length ? '<ul class="zter-gl-warn">' + r.warnings.map(function (w) { return '<li><span aria-hidden="true">⚠ </span>' + esc(w) + '</li>'; }).join('') + '</ul>' : '';
				var ch = r.changed ? '<p class="zter-muted">' + (r.changed.live ? 'Turned live ' : 'Put back to shadow ') + esc(when(r.changed.at)) + ' by ' + esc(r.changed.by) + '.</p>' : '';
				var btn = edit ? '<p><button type="button" class="zdz-btn ' + (r.live ? 'zdz-btn--secondary' : 'zdz-btn--primary') + ' zdz-btn--sm" data-golive="' + i + '">' + (r.live ? 'Back to shadow' : 'Turn live') + '</button></p>' : '';
				return '<li class="zter-gl' + (r.live ? ' zter-gl--live' : '') + '"><div class="zter-gl-head"><span class="zter-rule-no">' + r.no + '</span><span class="zter-gl-sentence">' + esc(r.sentence) + '</span>'
					+ '<span class="zter-mode zdz-pair zter-mode--' + (r.live ? 'live' : 'shadow') + '">' + (r.live ? '● Live' : '○ Shadow') + '</span></div>'
					+ '<dl class="zter-gl-facts"><div><dt>Decided</dt><dd>' + (+r.decided || 0) + '</dd></div><div' + (r.differ ? ' class="zter-gl-diff"' : '') + '><dt>Differ from what happened</dt><dd>' + (+r.differ || 0) + (r.differ ? ' <span aria-hidden="true">≠</span>' : '') + '</dd></div><div><dt>Answered live</dt><dd>' + (+r.answeredLive || 0) + '</dd></div></dl>'
					+ ppl + warn + ch + btn + '</li>';
			}).join('') + '</ol>';
			box.innerHTML = h;
			$$('[data-golive]', box).forEach(function (b) { on(b, 'click', function () { openGoLive(c, c.rules[+b.getAttribute('data-golive')]); }); });
			drawService(c);
		}).catch(function (e) {
			box = $('[data-golive-body]');
			if (!box) { return; }
			box.innerHTML = '<p class="zter-err">' + esc(e.message) + '</p><p><button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-golive-retry>Try again</button></p>';
			on($('[data-golive-retry]', box), 'click', function () { box.innerHTML = loadingHtml('Loading the checklist…'); loadChecklist(); });
		});
	}

	/* Who can sell, install and repair is changed only in Team Roles: a link, never a change made here. */
	function drawService(c) {
		var el = $('[data-service]');
		if (!el) { return; }
		var open = typeof window.openApp === 'function';
		el.innerHTML = '<div class="zter-card"><p><strong>Who can sell, install and repair each item is set in Team Roles</strong> (the Can, Learning and Can’t marks). '
			+ (isInstall() ? 'Install rules ask Install for new work and Repair for a repair.' : 'Stub rules ask Sell. A rule for unknown work can send a repair that names no item, or a stub with only a ZIP, to whoever holds the ZIP.') + '</p>'
			+ (c.teamRoles && c.teamRoles.items === false ? '<p class="zter-warn">Team Roles has no items yet.</p>' : '')
			+ (open ? '<p><button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-open-roles>Open Team Roles</button></p>' : '<p class="zter-muted">Team Roles is on the home screen.</p>') + '</div>';
		on($('[data-open-roles]', el), 'click', function () { try { window.openApp('team-roles'); } catch (e) { say('Open Team Roles from the home screen.'); } });
	}

	function openGoLive(c, r) {
		if (!r) { return; }
		var on_ = !r.live;
		var inst = c.stage === 'install';
		var h = '<div class="zter-form"><p class="zter-gl-quote">' + esc(r.sentence) + '</p>';
		if (on_) {
			if (inst) {
				h += r.who === 'default'
					? '<p>This rule suggests no one, so turning it live suggests no installer for the jobs it matches. It does stop the rules after it from deciding them.</p>'
					: '<p>From now on, when a job this rule matches is won, the Jobs app suggests this rule’s pick to the salesperson, who says Yes or picks Another. Nobody is assigned until they confirm.</p>';
			} else {
				h += r.who === 'default'
					? '<p>This rule keeps the asking app’s own pick, so turning it live gives no one new work. It does stop the rules after it from deciding the stubs it matches.</p>'
					: '<p>From now on, when a new operator stub matches this rule, the estimate app writes this rule’s pick as the salesperson, and the person gets a notice. A stub it doesn’t match keeps today’s pick, or the next live rule’s.</p>';
				h += '<p>Stubs that already exist are not changed.</p>';
			}
		} else {
			h += inst ? '<p>Won jobs it matches get no suggestion from it again (or a later live rule’s). Nothing already confirmed changes.</p>' : '<p>New stubs it matches go back to today’s pick (or a later live rule). Nothing already written changes.</p>';
		}
		h += '<ul class="zter-plain"><li>In shadow, the last ' + (+c.days || 7) + ' days: decided ' + (+r.decided || 0) + ' real ' + (inst ? 'won job' + (r.decided === 1 ? '' : 's') : 'stub' + (r.decided === 1 ? '' : 's')) + ', ' + (+r.differ || 0) + ' different from what happened.</li>'
			+ (r.who !== 'default' ? '<li>People it can choose now: ' + (+r.able || 0) + '.</li>' : '') + '</ul>';
		if (on_ && r.warnings.length) {
			h += '<p class="zter-label">Before you do</p><ul class="zter-gl-warn">' + r.warnings.map(function (w) { return '<li><span aria-hidden="true">⚠ </span>' + esc(w) + '</li>'; }).join('') + '</ul>';
		}
		h += '<p class="zter-muted">Recorded in History with your initials and the time.</p></div>';
		sheet({
			title: on_ ? 'Turn rule ' + r.no + ' live?' : 'Put rule ' + r.no + ' back to shadow?', body: h,
			actions: [{ label: on_ ? 'Turn live' : 'Back to shadow', kind: 'primary', onClick: function () {
				return api('rules/' + encodeURIComponent(r.id) + '/live', { method: 'POST', body: { live: on_, version: c.version, stage: c.stage || 'stub' } }).then(function (res) {
					say((inst ? 'Install rule ' : 'Rule ') + res.no + (on_ ? ' is live.' : ' is back in shadow.'));
					return load();
				});
			} }, { label: 'Cancel' }]
		});
	}

	/* ------------------------------------------------------------------ try a stub */

	function openTry() {
		var inst = isInstall();
		var team = (D().team || D().people || []).filter(function (t) { return t.initials; });
		var h = '<div class="zter-form"><label for="tt-zip">ZIP</label><input id="tt-zip" inputmode="numeric" maxlength="10" placeholder="5 digits">'
			+ '<label for="tt-lines">Lines, one per line (name | description)</label><textarea id="tt-lines" rows="4" placeholder="Item name | description"></textarea>'
			+ (inst ? '<label for="tt-seller">Sold by</label><select id="tt-seller"><option value="0">—</option>' + team.map(function (t) { return '<option value="' + t.id + '">' + esc(t.name + ' (' + t.initials + ')') + '</option>'; }).join('') + '</select>'
				: '<label for="tt-def">Today’s pick (initials the estimate app would write)</label><input id="tt-def" maxlength="3" placeholder="optional">')
			+ '<p class="zter-muted">Nothing is recorded and no turn is taken.</p><div data-tryout aria-live="polite"></div></div>';
		sheet({
			title: inst ? 'Try a won job' : 'Try a stub', body: h,
			actions: [{ label: 'Try', kind: 'primary', onClick: function (sh) {
				var g = function (id) { var el = document.getElementById(id); return el ? el.value : ''; };
				return api('try', { method: 'POST', body: { stage: S.stage, zip: g('tt-zip'), text: g('tt-lines'), 'default': g('tt-def'), seller: +g('tt-seller') || 0 } }).then(function (r) {
					$('[data-tryout]', sh.el).innerHTML = decisionHtml(r, true);
					return false; // keep the sheet open to read the answer
				});
			} }, { label: 'Close' }]
		});
	}

	/* ------------------------------------------------------------------ shadow */

	function drawShadow(body) {
		var inst = isInstall();
		body.innerHTML = stageSwitch() + '<div class="zter-filters"><label for="zter-days">Last</label><select id="zter-days">' + [7, 14, 30, 90].map(function (n) {
			return '<option value="' + n + '"' + (S.days === n ? ' selected' : '') + '>' + n + ' days</option>';
		}).join('') + '</select><label class="zter-check"><input type="checkbox" id="zter-tests"' + (S.tests ? ' checked' : '') + '> Include test stubs</label>'
			+ '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-act="try">' + (inst ? 'Try a won job' : 'Try a stub') + '</button>'
			+ (D().canEdit ? '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-act="tests">' + (inst ? 'Run the test stubs as won jobs' : 'Run the test stubs') + '</button><button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-act="clear">Clear tests</button>' : '')
			+ '</div><div data-list>' + loadingHtml('Loading decisions…') + '</div>'
			+ (!inst && D().canEdit ? '<section class="zter-asks" data-asks aria-label="Last asked">' + loadingHtml('Loading the last asks…') + '</section>' : '');
		bindStages(function () { drawShadow(body); });
		on($('#zter-days'), 'change', function (e) { S.days = +e.target.value; loadDecisions(); });
		on($('#zter-tests'), 'change', function (e) { S.tests = e.target.checked; loadDecisions(); });
		on($('[data-act="try"]'), 'click', openTry);
		on($('[data-act="tests"]'), 'click', function (e) {
			busy(e.currentTarget, function () {
				return api('tests', { method: 'POST', body: { stage: S.stage } }).then(function (r) { say(r.rows.length + (inst ? ' test stubs run as won jobs.' : ' test stubs routed.')); S.tests = true; drawShadow(body); });
			}).catch(function (err) { sayErr(err); });
		});
		on($('[data-act="clear"]'), 'click', function (e) {
			busy(e.currentTarget, function () {
				return api('tests' + stageQ(), { method: 'DELETE' }).then(function (r) { say(r.cleared + ' test decisions cleared.'); loadDecisions(); });
			}).catch(function (err) { sayErr(err); });
		});
		loadDecisions();
		if (!inst && D().canEdit) { loadAsks(); }
	}

	/* The last 20 times an app asked Territories who gets a stub, and who may ask (the registry's answer). */
	function loadAsks() {
		var box = $('[data-asks]');
		if (!box) { return; }
		api('asks').then(function (r) {
			var h = '<h4 class="zter-h4">Last asked</h4>';
			if (!r.rows.length) {
				h += '<p class="zter-muted">No app has asked who gets a stub' + (r.since ? ' since ' + esc(when(r.since)) : '') + '. The estimate app asks on every new $0 stub, so if any were made since then, the ask never reached Territories: see who may ask below.</p>';
			} else {
				h += '<ul class="zter-plain">' + r.rows.map(function (a) {
					return '<li>' + esc(when(a.at)) + ' · ' + esc(a.app || 'an app') + (a.who ? ' · ' + esc(a.who) + (a.tier ? ' (' + esc(a.tier) + ')' : '') : '') + (a.test ? ' · <span class="zter-test">test</span>' : '')
						+ ' · ' + (a.outcome === 'decision' ? esc(a.words) : '<span class="zter-warn">' + esc(a.words) + '</span>') + '</li>';
				}).join('') + '</ul>';
			}
			h += '<h4 class="zter-h4">Who may ask</h4>';
			h += r.who.length ? '<ul class="zter-plain">' + r.who.map(function (w) {
				return '<li>' + esc(w.who || '?') + (w.kiosk ? ' (shared device)' : '') + ': ' + (w.why ? '<span class="zter-warn">' + esc(w.words) + '</span>' : esc(w.words)) + '</li>';
			}).join('') + '</ul>' : '<p class="zter-muted">Nobody can make $0 stubs in the estimate app.</p>';
			box.innerHTML = h;
		}).catch(function (e) { box.innerHTML = '<p class="zter-err">' + esc(e.message) + '</p>'; });
	}

	function loadDecisions() {
		var list = $('[data-list]');
		if (!list) { return; }
		list.innerHTML = loadingHtml('Loading decisions…');
		var asked = S.stage;
		api('decisions?days=' + S.days + (S.tests ? '&tests=1' : '') + stageQ('&')).then(function (r) {
			if (asked !== S.stage) { return; }
			S.dec = r;
			var s = r.summary;
			var inst = isInstall();
			var h = '<div class="zter-tiles">' + (inst
				? tile(s.total, 'won jobs asked about') + tile(s.live, 'suggested live') + tile(s.fallback, 'no suggestion (“everything else”)') + tile(s.unknownKind, 'kind of work unknown')
				: tile(s.total, 'decided') + tile(s.same, 'same as today') + tile(s.differ, 'different from today', s.differ ? 'diff' : '') + tile(s.fallback, 'went to “everything else”') + tile(s.unknownKind, 'kind of work unknown'))
				+ (s.teamRolesOff ? tile(s.teamRolesOff, 'Team Roles off') : '') + '</div>';
			if (!r.rows.length) {
				h += '<p class="zter-muted">' + (inst ? 'No won jobs in this period. The Jobs app asks Territories who should install a job when it is won; try a won job or run the test stubs as won jobs to see the install rules work.' : 'No stubs in this period. The estimate app asks Territories on every new operator stub; try a stub or run the test stubs to see the rules work.') + '</p>';
			} else {
				h += '<ul class="zter-decs">' + r.rows.map(function (d, i) {
					var differ = inst ? (d.confirmed && d.confirmedDone !== false && d.would && d.confirmed !== d.would) : (d['default'] && d.would !== d['default']);   /* both parts in first */
					return '<li><button type="button" class="zter-dec' + (differ ? ' zter-dec--diff' : '') + '" data-dec="' + i + '">'
						+ '<span class="zter-dec-when">' + esc(when(d.at)) + (d.test ? ' · <span class="zter-test">test</span>' : '') + '</span>'
						+ '<span class="zter-dec-what">' + (d.label ? esc(d.label) + ' · ' : '') + (d.zip ? esc(d.zip) : 'no ZIP') + ' · ' + (d.kinds.length ? esc(d.kinds.map(function (k) { return k.label; }).join(', ')) : (d.kindState === 'none' ? 'no work lines' : 'kind unknown')) + '</span>'
						+ '<span class="zter-dec-who">' + (inst ? 'Sold by: ' + (d.seller ? esc(d.seller) : '<span class="zter-muted">—</span>') : 'Today: ' + ini(d['default'])) + ' · Rules: <strong>' + ini(d.would) + '</strong>' + (d.ruleNo ? ' (rule ' + d.ruleNo + ')' : '') + (inst && d.confirmed ? ' · Confirmed: ' + esc(d.confirmed) : '') + (differ ? ' <span class="zter-diffmark">≠</span>' : '') + (d.mode === 'live' ? ' · <span class="zter-mode zdz-pair zter-mode--live">● Live</span>' : '') + (d.refer ? ' · <span class="zter-refer">Needs a referral' + (d.refer === 2 ? '' : ' (shadow)') + '</span>' : '') + '</span>'
						+ (d.note ? '<span class="zter-dec-note">Note: ' + esc(d.note.reason) + '</span>' : '')
						+ '</button></li>';
				}).join('') + '</ul>';
			}
			list.innerHTML = h;
			$$('[data-dec]', list).forEach(function (b) { on(b, 'click', function () { openDecision(r.rows[+b.getAttribute('data-dec')]); }); });
		}).catch(function (e) {
			list.innerHTML = '<p class="zter-err">' + esc(e.message) + '</p>';
		});
	}
	function tile(n, label, mod) { return '<div class="zter-tile' + (mod ? ' zter-tile--' + mod : '') + '"><strong>' + (+n || 0) + '</strong><span>' + esc(label) + '</span></div>'; }

	function decisionHtml(d, dry) {
		var inst = d.stage === 'install';
		var two = (d.wouldPeople || []).length > 1;
		var h = '<dl class="zter-dl">'
			+ '<dt>ZIP</dt><dd>' + (d.zip ? esc(d.zip) + (d.zipFrom === 'reference' ? ' (from the reference)' : '') : 'none') + '</dd>'
			+ '<dt>Kind of work</dt><dd>' + (d.kinds.length ? esc(d.kinds.map(function (k) { return k.label; }).join(', ')) : '—') + (d.kindState === 'unknown' ? ' <span class="zter-warn">(a line’s kind is unknown)</span>' : d.kindState === 'none' ? ' <span class="zter-muted">(no line says what the work is)</span>' : '') + '</dd>'
			+ '<dt>Rule</dt><dd>' + (d.ruleNo ? 'Rule ' + d.ruleNo + ' (version ' + d.ruleVersion + '): ' + esc(d.sentence) : 'none') + '</dd>'
			+ (inst ? '<dt>Sold by</dt><dd>' + (d.seller ? esc(d.seller) : '—') + '</dd>' : '<dt>Today’s pick</dt><dd>' + ini(d['default']) + '</dd>')
			+ '<dt>The rules pick</dt><dd><strong>' + ini(d.would) + '</strong> (all rules, live or not)' + (two ? ' <span class="zter-muted">(two people: the first for the item the rule names, the second for the rest of the work)</span>' : '') + '</dd>'
			+ '<dt>' + (inst ? 'Suggested' : 'Answered') + '</dt><dd>' + ini(d.answered) + (d.mode === 'live' ? ' (live rule ' + (d.liveRuleNo || '?') + ')' : inst ? ' (no live rule: no suggestion)' : ' (no live rule: today’s pick)') + '</dd>'
			+ (inst && d.confirmed ? '<dt>Confirmed</dt><dd>' + esc(d.confirmed) + (d.confirmedDone !== false && d.answered && d.confirmed !== d.answered ? ' (Another)' : '') + '</dd>' : '')
			+ '</dl>';
		if (d.lines.length) {
			h += '<h4 class="zter-h4">Lines</h4><ul class="zter-plain">' + d.lines.map(function (l) {
				return '<li>Line ' + l.n + ': ' + (l.types.length ? esc(l.types.map(typeLabel).join(' + ')) : '—') + (l.repair ? ' (repair)' : ' (new)') + ' → ' + (l.label ? esc(l.label) + ' <span class="zter-muted">(' + (l.by === 'catalog' ? 'the catalog' : 'words row ' + rowNo(l.by)) + ')</span>' : '<span class="zter-warn">' + (l.by === 'repair' ? 'a repair that names no item' : 'unknown' + (/^gone:/.test(l.by) ? ' (not in Team Roles)' : '')) + '</span>') + '</li>';
			}).join('') + '</ul>';
		}
		h += '<h4 class="zter-h4">How it was decided</h4><ol class="zter-trail">' + d.trail.map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('') + '</ol>';
		if (d.candidates.length) {
			h += '<h4 class="zter-h4">People looked at</h4><ul class="zter-plain">' + d.candidates.map(function (c) {
				return '<li><strong>' + esc(c.ini || '#' + c.u) + '</strong> ' + (c.levels.length ? c.levels.map(function (l) { return esc(l.label) + ': ' + level(l.level); }).join(' ') : '<span class="zter-muted">' + (c.hidden ? 'Team Roles shows this person’s levels only to them, their crew lead and the admin tier' : '“can do” not checked') + '</span>')
					+ (!dry && d.id && c.levels.length ? ' <button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-why="' + c.u + '">Team Roles’ notes</button><span data-whyout="' + c.u + '"></span>' : '') + '</li>';
			}).join('') + '</ul>';
		}
		if (d.hiddenCandidates) { h += '<p class="zter-muted">' + (+d.hiddenCandidates) + ' more looked at whose Team Roles marks only they, their crew lead and the admin tier see.</p>'; }
		if (d.applied) { h += inst ? '<p>Confirmed in the Jobs app ' + esc(when(d.applied)) + (d.doc ? ' on job ' + esc(d.doc) : '') + '.</p>' : '<p>Written by the estimate app ' + esc(when(d.applied)) + (d.doc ? ' on #' + esc(d.doc) : '') + (d.notice ? ' · told them by ' + esc(d.notice) : '') + '.</p>'; }
		if (d.note) { h += '<p class="zter-note"><strong>Note by ' + esc(d.note.by) + ':</strong> ' + (d.note.to ? 'really went to ' + esc(d.note.to) + '. ' : '') + esc(d.note.reason) + '</p>'; }
		return h;
	}

	function openDecision(d) {
		var team = (D().team || D().people || []).filter(function (t) { return t.initials; });
		var h = '<div class="zter-form">' + decisionHtml(d, false)
			+ '<h4 class="zter-h4">Note who it really went to</h4><p class="zter-muted">' + (d.stage === 'install' ? 'The installer is confirmed in the Jobs app.' : 'The change itself is made in the estimate app.') + ' The note shows in this comparison so the rules can be fixed.</p>'
			+ '<label for="td-to">Went to</label><select id="td-to"><option value="0">—</option>' + team.map(function (t) { return '<option value="' + t.id + '">' + esc(t.name + ' (' + t.initials + ')') + '</option>'; }).join('') + '</select>'
			+ '<label for="td-why">Why</label><input id="td-why" maxlength="300"></div>';
		var sh = sheet({
			title: (d.label || (d.stage === 'install' ? 'Won job' : 'Stub')) + (d.zip ? ' · ' + d.zip : ''), body: h,
			actions: [{ label: 'Save note', kind: 'primary', onClick: function () {
				return api('decisions/' + d.id + '/note', { method: 'POST', body: { to: +document.getElementById('td-to').value, reason: document.getElementById('td-why').value } }).then(function () { say('Note saved.'); loadDecisions(); });
			} }, { label: 'Close' }]
		});
		if (!sh) { return; }
		$$('[data-why]', sh.el).forEach(function (b) {
			on(b, 'click', function () {
				var u = b.getAttribute('data-why');
				busy(b, function () {
					return api('why?decision=' + d.id + '&user=' + u).then(function (r) {
						var out = $('[data-whyout="' + u + '"]', sh.el);
						out.innerHTML = '<ul class="zter-plain zter-why">' + r.now.map(function (x) { return '<li>' + esc(x.why) + '</li>'; }).join('') + '</ul>';
					});
				}).catch(function (err) { sayErr(err); });
			});
		});
	}

	/* ------------------------------------------------------------------ history */

	function drawHistory(body) {
		body.innerHTML = loadingHtml('Loading history…');
		api('history?limit=100').then(function (r) {
			if (!r.rows.length) { body.innerHTML = '<p class="zter-muted">Nothing has changed yet.</p>'; return; }
			var cur = { stub: (D().rules || {}).version, install: (D().installRules || {}).version };
			body.innerHTML = '<ul class="zter-hist">' + r.rows.map(function (x) {
				var d = x.detail || {};
				var st = d.stage === 'install' ? 'install' : 'stub';
				var back = D().canEdit && x.action === 'rules' && d.version && +d.version !== +cur[st]
					? ' <button type="button" class="zdz-btn zdz-btn--quiet zdz-btn--sm" data-revert="' + esc(d.version) + '" data-stage="' + st + '">Put this version back</button>' : '';
				return '<li><span class="zter-muted">' + esc(when(x.changed_at)) + ' · ' + esc(x.by) + '</span> ' + esc(histText(x)) + back + '</li>';
			}).join('') + '</ul>' + (r.more ? '<p class="zter-muted">Older changes are kept; the last 100 are shown.</p>' : '');
			$$('[data-revert]', body).forEach(function (b) {
				on(b, 'click', function () {
					var st = b.getAttribute('data-stage');
					var to = +b.getAttribute('data-revert');
					sheet({
						title: 'Put rules version ' + to + ' back?',
						body: '<p>The ' + (st === 'install' ? 'won-jobs' : 'new-stub') + ' rules become what they were at version ' + to + ', saved as a new version. Every rule keeps the mode it has now: nothing turns live by this. The list in use now stays in History.</p>',
						actions: [{ label: 'Put it back', kind: 'primary', onClick: function () {
							return api('rules/revert', { method: 'POST', body: { stage: st, to: to, version: cur[st] } }).then(function () { say('Rules version ' + to + ' put back.'); return load(); });
						} }, { label: 'Cancel' }]
					});
				});
			});
		}).catch(function (e) { body.innerHTML = '<p class="zter-err">' + esc(e.message) + '</p>'; });
	}
	function histText(x) {
		var d = x.detail || {};
		switch (x.action) {
			case 'zips': return x.person + ': ' + (d.added && d.added.length ? 'added ' + d.added.join(', ') : '') + (d.added && d.added.length && d.removed && d.removed.length ? '; ' : '') + (d.removed && d.removed.length ? 'removed ' + d.removed.join(', ') : '') + (d.source && d.source !== 'page' ? ' (' + d.source + ')' : '');
			case 'rules': return (d.stage === 'install' ? 'Install rules' : 'Rules') + (d.seeded ? ' set up (version 1)' : ' saved: version ' + d.version) + '.';
			case 'revert': return (d.stage === 'install' ? 'Install rules' : 'Rules') + ' version ' + d.to + ' put back as version ' + d.version + ' (each rule kept its live or shadow mode).';
			case 'installer': return 'Installer confirmed for decision #' + d.decision + (d.another ? ' (Another, not the suggestion)' : '') + '.';
			case 'words': return 'Words table saved.';
			case 'settings': return 'Settings saved.';
			case 'zones': return 'Zones saved (version ' + d.version + ')' + ((d.changes || []).length ? ': ' + d.changes.map(function (c) {
				return c.zone + ' ' + c.did + (c.did === 'added' ? ' (' + c.zips + (c.zips === 'everywhere' ? '' : ' ZIPs') + ')' : '')
					+ (c.did === 'changed' && ((c.added || []).length || (c.removed || []).length) ? ' (+' + (c.added || []).length + ' −' + (c.removed || []).length + ' ZIPs)' : '')
					+ ((c.people_added || []).length ? ' (now keeps out ' + c.people_added.join(', ') + ')' : '') + ((c.people_removed || []).length ? ' (no longer keeps out ' + c.people_removed.join(', ') + ')' : '');
			}).join('; ') : '') + '.';
			case 'tests': return d.ran ? 'Ran ' + d.ran + ' test stubs' + (d.stage === 'install' ? ' as won jobs.' : '.') : 'Cleared ' + (d.cleared || 0) + ' test decisions.';
			case 'note': return 'Note on decision #' + d.decision + ': ' + (d.reason || '');
			case 'live': return (d.stage === 'install' ? 'Install rule ' : 'Rule ') + d.no + (d.live ? ' turned live' : ' put back to shadow') + ' (version ' + d.version + '): ' + (d.sentence || '');
			case 'reassign': return d.undone ? 'The estimate app undid re-assign batch ' + d.batch + ' (' + d.undone.length + ' estimate' + (d.undone.length === 1 ? '' : 's') + ').' : 'The estimate app re-assigned ' + d.turns + ' existing stub' + (d.turns === 1 ? '' : 's') + ' (batch ' + d.batch + ')' + (d.skipped ? '; ' + d.skipped + ' not counted as a turn' : '') + '.';
			case 'referral': return 'Referral case ' + d['case'] + ' (' + (d.zip || 'no ZIP') + ') closed: ' + (d.how === 'emailed' ? 'the email was opened from the referral list' : 'closed by hand') + '.';
			default: return x.action;
		}
	}

	/* ------------------------------------------------------------------ start */

	function start() {
		var el = document.getElementById('zter-app');
		if (!el || el.getAttribute('data-started')) { return; }
		el.setAttribute('data-started', '1');
		root = el;
		frame();
		load();
	}
	if (document.getElementById('zter-app')) { start(); }
	document.addEventListener('zdz_widgets_rendered', start);
	document.addEventListener('DOMContentLoaded', start);
})();
