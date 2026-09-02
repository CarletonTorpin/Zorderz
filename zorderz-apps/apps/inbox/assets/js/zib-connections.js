/**
 * Zorderz Inbox — connection card (P0), hardened for NitroPack + late-injected DOM.
 *
 * Each #zib-card reads its own config from data-attributes (rest/nonce/start),
 * falling back to window.zibCfg. The card is initialized on load, on
 * DOMContentLoaded, and via a MutationObserver — so it works whether the
 * dashboard renders the widget server-side or injects it after this script has
 * already run. Talks only to the inbox REST route, owner-scoped server-side;
 * no mail fetched.
 */
(function () {
	'use strict';
	try { console.log('[ZIB] connections.js loaded'); } catch (e) {}

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function cfgFor(card) {
		var g = window.zibCfg || {};
		return {
			restUrl:  card.getAttribute('data-zib-rest')  || g.restUrl  || '',
			nonce:    card.getAttribute('data-zib-nonce') || g.nonce    || '',
			startUrl: card.getAttribute('data-zib-start') || g.startUrl || ''
		};
	}

	function initCard(card) {
		if (!card || card.getAttribute('data-zib-inited') === '1') { return; }
		var cfg = cfgFor(card);
		if (!cfg.restUrl) { try { console.warn('[ZIB] card has no rest URL; skipping'); } catch (e) {} return; }
		card.setAttribute('data-zib-inited', '1');

		function api(path, method, body) {
			return fetch(cfg.restUrl + path, {
				method: method || 'GET',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
				body: body ? JSON.stringify(body) : undefined
			}).then(function (r) { return r.json().catch(function () { return {}; }); });
		}

		function goConnect() {
			var u = String(cfg.startUrl || '').replace(/&amp;/g, '&');
			if (u) { window.location.href = u; }
		}

		function statusChip(status) {
			if (status === 'ok') { return '<span class="zib-chip zib-ok">Connected</span>'; }
			if (status === 'reauth_needed') { return '<span class="zib-chip zib-warn">Reconnect needed</span>'; }
			return '<span class="zib-chip">' + esc(status) + '</span>';
		}

		function render(state) {
			var conn = state && state.connection;

			if (!conn) {
				card.innerHTML =
					'<div class="zib-head"><span class="zib-title">Connected Email</span></div>' +
					'<p class="zib-sub">Connect your Microsoft 365 mailbox so it can be indexed — privately, for you. ' +
					'Read-only for now: this cannot send or change your mail.</p>' +
					'<button type="button" class="zib-btn zib-primary" id="zib-connect">Connect Microsoft 365</button>';
				var c = card.querySelector('#zib-connect');
				if (c) { c.addEventListener('click', goConnect); }
				return;
			}

			var modeOpts = card.getAttribute('data-zib-modeopts') || '';
			card.innerHTML =
				'<div class="zib-head"><span class="zib-title">Connected Email</span> ' + statusChip(conn.status) + '</div>' +
				'<p class="zib-sub">' + esc(conn.email_label || conn.upn || 'Your mailbox') + '</p>' +
				'<label class="zib-row"><span>What to index</span>' +
				'<select id="zib-mode">' + modeOpts + '</select></label>' +
				'<label class="zib-row zib-check"><input type="checkbox" id="zib-admin" ' +
				(conn.admin_search_enabled ? 'checked' : '') + '/> ' +
				'<span>Let an administrator search my indexed mail</span></label>' +
				'<div class="zib-actions">' +
				'<button type="button" class="zib-btn ' + (conn.status === 'reauth_needed' ? 'zib-primary' : '') + '" id="zib-reconnect">Reconnect</button>' +
				'<button type="button" class="zib-btn zib-danger" id="zib-disconnect">Disconnect</button>' +
				'</div>' +
				'<p class="zib-note">Indexed mail is sealed to you. Nothing leaves that store except your own searches, ' +
				'your own questions to the assistant, an admin search you allow above, or an email you choose to attach to a job.</p>' +
				'<div class="zib-search">' +
				'<input type="search" class="zib-q" placeholder="Search your indexed mail…" autocomplete="off" />' +
				'<div class="zib-results"></div>' +
				'<div class="zib-reader zib-hide"></div>' +
				'</div>';

			var sel = card.querySelector('#zib-mode');
			if (sel) { sel.value = conn.index_mode || 'none'; }

			card.querySelector('#zib-reconnect').addEventListener('click', goConnect);

			if (sel) {
				sel.addEventListener('change', function () {
					api('/connection/mode', 'POST', { account_id: conn.id, mode: sel.value })
						.then(function (r) { if (r && r.connection) { render(r); } });
				});
			}

			card.querySelector('#zib-admin').addEventListener('change', function (e) {
				api('/connection/admin-access', 'POST', { account_id: conn.id, enabled: e.target.checked })
					.then(function (r) { if (r && r.connection) { render(r); } });
			});

			card.querySelector('#zib-disconnect').addEventListener('click', function () {
				if (!window.confirm('Disconnect your mailbox? Your indexed mail will be removed.')) { return; }
				api('/connection/disconnect', 'POST', { account_id: conn.id }).then(function () { load(); });
			});

			setupSearch();
		}

		// ── owner search + reader (P2) ──────────────────────────────
		function setupSearch() {
			var input   = card.querySelector('.zib-q');
			var results = card.querySelector('.zib-results');
			var reader  = card.querySelector('.zib-reader');
			if (!input || !results || !reader) { return; }
			var timer = null, lastQ = null;

			function fmtDate(s) {
				if (!s) { return ''; }
				var d = new Date(String(s).replace(' ', 'T') + 'Z');
				return isNaN(d.getTime()) ? String(s) : d.toLocaleDateString();
			}

			function showResults(list) {
				reader.classList.add('zib-hide');
				results.classList.remove('zib-hide');
				if (!list || !list.length) {
					results.innerHTML = '<p class="zib-empty">No matching mail.</p>';
					return;
				}
				var html = '';
				for (var i = 0; i < list.length; i++) {
					var r = list[i];
					html += '<button type="button" class="zib-hit" data-id="' + (r.id | 0) + '">' +
						'<span class="zib-hit-top"><span class="zib-hit-from">' + esc(r.from) + '</span>' +
						'<span class="zib-hit-date">' + esc(fmtDate(r.received_at)) + '</span></span>' +
						'<span class="zib-hit-subj">' + esc(r.subject || '(no subject)') + '</span>' +
						'<span class="zib-hit-snip">' + esc(r.snippet) + '</span></button>';
				}
				results.innerHTML = html;
				var hits = results.querySelectorAll('.zib-hit');
				for (var j = 0; j < hits.length; j++) {
					hits[j].addEventListener('click', function () { openMsg(this.getAttribute('data-id')); });
				}
			}

			function doSearch(q) {
				lastQ = q;
				api('/search?q=' + encodeURIComponent(q) + '&limit=30', 'GET').then(function (res) {
					if (q !== lastQ) { return; } // ignore stale
					showResults(res && res.results ? res.results : []);
				});
			}

			function openMsg(id) {
				api('/message/' + (id | 0), 'GET').then(function (res) {
					var m = res && res.message;
					if (!m) { return; }
					results.classList.add('zib-hide');
					reader.classList.remove('zib-hide');
					reader.innerHTML =
						'<button type="button" class="zib-btn zib-back">← Back</button>' +
						'<h4 class="zib-r-subj"></h4>' +
						'<div class="zib-r-meta"></div>' +
						'<pre class="zib-r-body"></pre>';
					reader.querySelector('.zib-r-subj').textContent = m.subject || '(no subject)';
					var meta = 'From: ' + m.from + '\n' + (m.to && m.to.length ? 'To: ' + m.to.join(', ') + '\n' : '') +
						(m.cc && m.cc.length ? 'Cc: ' + m.cc.join(', ') + '\n' : '') + (m.received_at ? m.received_at + ' UTC' : '');
					reader.querySelector('.zib-r-meta').textContent = meta;      // textContent — safe
					reader.querySelector('.zib-r-body').textContent = m.body_text; // textContent — no email HTML in the DOM
					reader.querySelector('.zib-back').addEventListener('click', function () {
						reader.classList.add('zib-hide');
						results.classList.remove('zib-hide');
					});
				});
			}

			input.addEventListener('input', function () {
				var q = input.value.trim();
				if (timer) { clearTimeout(timer); }
				timer = setTimeout(function () { doSearch(q); }, 300);
			});
			doSearch(''); // seed with most-recent
		}

		function load() {
			card.innerHTML = '<div class="zib-loading">Loading your email connection…</div>';
			api('/connection', 'GET').then(render).catch(function () {
				card.innerHTML = '<p class="zib-sub">Couldn’t load your email connection. Reload the page to try again.</p>';
			});
		}

		load();
	}

	function scan() {
		var cards = document.querySelectorAll('#zib-card, .zib-card');
		for (var i = 0; i < cards.length; i++) { initCard(cards[i]); }
	}

	// 1) Now (script may load after the card is already in the DOM).
	scan();
	// 2) On DOMContentLoaded (script in <head>/deferred before the card).
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', scan);
	}
	// 3) Late injection (dashboard hydrates widgets after load).
	try {
		var mo = new MutationObserver(function (muts) {
			for (var i = 0; i < muts.length; i++) {
				var added = muts[i].addedNodes;
				for (var j = 0; j < added.length; j++) {
					var n = added[j];
					if (!n || n.nodeType !== 1) { continue; }
					if (n.matches && n.matches('#zib-card, .zib-card')) { initCard(n); }
					if (n.querySelectorAll) {
						var inner = n.querySelectorAll('#zib-card, .zib-card');
						for (var k = 0; k < inner.length; k++) { initCard(inner[k]); }
					}
				}
			}
		});
		mo.observe(document.documentElement || document.body, { childList: true, subtree: true });
	} catch (e) {}

	// Clean the ?zib_connected=… flag the OAuth round-trip lands on (once).
	try {
		var q = new URLSearchParams(window.location.search);
		if (q.has('zib_connected') && window.history.replaceState) {
			q.delete('zib_connected');
			var s = q.toString();
			window.history.replaceState({}, '', window.location.pathname + (s ? '?' + s : ''));
		}
	} catch (e) {}
})();
