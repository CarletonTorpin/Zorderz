/**
 * Inbox — connection card (P0), hardened for NitroPack + late-injected DOM.
 *
 * Each #zib-card reads its own config from data-attributes (rest/nonce/start),
 * falling back to window.zibCfg. The card is initialized on load, on
 * DOMContentLoaded, and via a MutationObserver — so it works whether the
 * dashboard renders the widget server-side or injects it after this script has
 * already run. Talks only to zorderz/v1, owner-scoped server-side; no mail fetched.
 *
 * Built on the theme's kit of parts (window.ZDZ.ui). Every request goes through
 * ZDZ.ui.rest, so a lost signal, a timeout or a 502 page becomes a sentence a
 * person can read, shown where the "Couldn't …" text is; loaders are the kit's
 * breathing dots; the triage Undo is the kit's toast; Disconnect asks in a kit
 * sheet instead of window.confirm.
 */
(function () {
	'use strict';
	try { console.log('[ZIB] connections.js loaded'); } catch (e) {}

	/* The app needs the kit; without it, say so plainly instead of failing silently. There is
	   deliberately no private fetch fallback. */
	var NO_KIT = 'This app needs a newer Zorderz theme. Reload the app.';
	function kit() { return (window.ZDZ && window.ZDZ.ui && window.ZDZ.ui.rest) ? window.ZDZ.ui : null; }
	var waitingForKit = false;

	/* Why a request failed, in the person's words. A server "Not found." / "Action failed."
	   (kind 'app') adds nothing to our own sentence; a lost signal, a timeout or a server error does. */
	function why(err) {
		return (err && err.kind && err.kind !== 'app' && err.message) ? ' ' + err.message : '';
	}
	/* The failure reason ('reconnect', 'busy', …) from a WP_Error body, JSON or text. */
	function reasonOf(err) {
		var b = err && err.body;
		if (typeof b === 'string') { try { b = JSON.parse(b); } catch (e) { b = null; } }
		return (b && ((b.data && b.data.reason) || b.reason)) || '';
	}

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
		if (!kit()) {
			// The kit loads in the footer too; give it until the page has loaded before giving up.
			if (document.readyState !== 'complete') {
				if (!waitingForKit) { waitingForKit = true; window.addEventListener('load', scan); }
				return;
			}
			card.setAttribute('data-zib-inited', '1');
			card.innerHTML = '<p class="zib-sub zib-nokit" role="alert">' + esc(NO_KIT) + '</p>';
			return;
		}
		card.setAttribute('data-zib-inited', '1');
		var connCanSend = false; // v0.10.0: set from /connection.can_send — Send UI shows only when granted
		var connCanTriage = false; // v0.11.0: set from /connection.can_triage — triage UI shows only when granted

		/* through the kit: resolves with the JSON of a 2xx answer, rejects with a
		   ZDZ.ui.RequestError (readable .message; .body keeps the server's {code, data:{reason}}). The card's
		   own nonce is sent, as before. A GET is retried once after a lost signal or a 502-504; a POST never. */
		function api(path, method, body, opts) {
			var o = {
				method: method || 'GET',
				headers: { 'X-WP-Nonce': cfg.nonce || '' },
				body: body || undefined
			};
			// a send with Vault documents reads files and uploads them before it sends; it gets two minutes,
			// not the kit's 30 seconds.
			if (opts && opts.timeout) { o.timeout = opts.timeout; }
			return ZDZ.ui.rest(cfg.restUrl + path, o);
		}
		function retryBtn(id) {
			return ' <button type="button" class="zib-btn zib-retry" id="' + id + '">Try again</button>';
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
					'<p class="zib-sub">Connect your Microsoft 365 mailbox so you can read and reply to it here, ' +
					'privately. It can’t move or delete mail, and it only sends what you write and click Send on.</p>' +
					'<button type="button" class="zib-btn zib-primary" id="zib-connect">Connect Microsoft 365</button>';
				var c = card.querySelector('#zib-connect');
				if (c) { c.addEventListener('click', goConnect); }
				return;
			}

			// v0.9.35: connected → the MAIL VIEW. A compact top bar (mailbox + a ⚙ that reveals the
			// connection settings), then a drill-down mail surface: folders → message list → reader.
			connCanSend = !!conn.can_send; // v0.10.0: gate the compose/send UI on the granted scope
			connCanTriage = !!conn.can_triage; // v0.11.0: gate the triage UI on the granted scope
			var modeOpts = card.getAttribute('data-zib-modeopts') || '';
			card.innerHTML =
				'<div class="zib-mailtop">' +
					'<span class="zib-title">Email</span> ' + statusChip(conn.status) +
					'<span class="zib-mailbox" title="' + esc(conn.email_label || conn.upn || '') + '">' + esc(conn.email_label || conn.upn || '') + '</span>' +
					'<button type="button" class="zib-gear" id="zib-gear" title="Settings" aria-label="Settings">⚙</button>' +
				'</div>' +
				'<div class="zib-settings zib-hide" id="zib-settings">' +
					'<label class="zib-row"><span>What to index</span><select id="zib-mode">' + modeOpts + '</select></label>' +
					'<label class="zib-row zib-check"><input type="checkbox" id="zib-admin" ' +
					(conn.admin_search_enabled ? 'checked' : '') + '/> <span>Let an administrator search my indexed mail</span></label>' +
					'<div class="zib-actions">' +
					'<button type="button" class="zib-btn ' + (conn.status === 'reauth_needed' ? 'zib-primary' : '') + '" id="zib-reconnect">Reconnect</button>' +
					'<button type="button" class="zib-btn zib-danger" id="zib-disconnect">Disconnect</button>' +
					'</div>' +
					'<p class="zib-note">Indexed mail is sealed to you; nothing leaves that store except your own searches, ' +
					'your questions to the assistant, an admin search you allow, an email you attach to a job, or a reply/message ' +
					'you compose and click Send on.</p>' +
				'</div>' +
				'<div class="zib-mailview" id="zib-mailview">' + ZDZ.ui.loadingHtml('Loading your mail…') + '</div>';

			var sel = card.querySelector('#zib-mode');
			if (sel) { sel.value = conn.index_mode || 'none'; }
			var gear = card.querySelector('#zib-gear');
			var settings = card.querySelector('#zib-settings');
			if (gear && settings) { gear.addEventListener('click', function () { settings.classList.toggle('zib-hide'); }); }

			card.querySelector('#zib-reconnect').addEventListener('click', goConnect);
			if (sel) {
				// fire-and-forget: changing the index mode must NOT blow away the open mail view.
				// a failed save is no longer silent - the kit's toast says why, with Try again.
				var saveMode = function () {
					api('/connection/mode', 'POST', { account_id: conn.id, mode: sel.value })
						.catch(function (err) { ZDZ.ui.toastError(err, saveMode); });
				};
				sel.addEventListener('change', saveMode);
			}
			var adminBox = card.querySelector('#zib-admin');
			var saveAdmin = function () {
				api('/connection/admin-access', 'POST', { account_id: conn.id, enabled: adminBox.checked })
					.catch(function (err) { ZDZ.ui.toastError(err, saveAdmin); });
			};
			adminBox.addEventListener('change', saveAdmin);
			/* Disconnect asks in the kit's sheet (Back closes it) instead of
			   window.confirm; the button shows busy while it works, and a failure is written in the sheet. */
			card.querySelector('#zib-disconnect').addEventListener('click', function () {
				ZDZ.ui.sheet({
					title: 'Disconnect your mailbox?',
					body: '<p class="zib-sheet-p">' + esc('Your indexed mail will be removed.') + '</p>',
					actions: [
						{ label: 'Cancel' },
						{ label: 'Disconnect', kind: 'danger', onClick: function () {
							return api('/connection/disconnect', 'POST', { account_id: conn.id }).then(function () { load(); });
						} }
					]
				});
			});

			mountMail();
		}

		// ── Mail view (v0.9.35): folders → message list → reader ────
		function mountMail() {
			var view = card.querySelector('#zib-mailview');
			if (!view) { return; }
			var state = { folder: null, offset: 0, folders: [], density: loadDensity() };
			var LIM = 30;
			var readThisSession = {}; // ids auto-marked read on open (once per message per session)
			var movedThisSession = {}; // ids moved out of a folder — hidden despite index lag
			card.classList.add('zib-wide'); // two-pane width on desktop
			card.setAttribute('data-zib-density', state.density);
			var READ_EMPTY = '<div class="zib-read-empty"><span>Select a message to read</span>' +
				'<span class="zib-kbhint">Press <kbd>?</kbd> for keyboard shortcuts</span></div>';

			// ---- small helpers (presentation only) ----
			function loadDensity() { try { return localStorage.getItem('zibDensity') || 'comfortable'; } catch (e) { return 'comfortable'; } }
			function saveDensity(d) { try { localStorage.setItem('zibDensity', d); } catch (e) {} }
			function parseSender(raw) {
				raw = String(raw == null ? '' : raw).trim();
				var m = raw.match(/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/);
				if (m) { var nm = m[1].trim(); var em = m[2].trim(); return { name: nm || em, email: em }; }
				if (/^[^@\s]+@[^@\s]+$/.test(raw)) { return { name: raw, email: raw }; }
				return { name: raw || '(unknown)', email: '' };
			}
			function initials(name) {
				name = String(name || '').trim();
				if (!name) { return '?'; }
				if (name.indexOf('@') > -1 && name.indexOf(' ') < 0) { return name.slice(0, 2).toUpperCase(); }
				var p = name.split(/\s+/).filter(Boolean);
				var a = p[0] ? p[0][0] : ''; var b = p.length > 1 ? p[p.length - 1][0] : '';
				return ((a + b) || name[0]).toUpperCase();
			}
			function avatarColor(seed) {
				var h = 0; seed = String(seed || '?');
				for (var i = 0; i < seed.length; i++) { h = (h * 31 + seed.charCodeAt(i)) >>> 0; }
				var PAL = ['#4796F7', '#2CA6BD', '#2FA36B', '#D98A2B', '#E0655F', '#3E92E0', '#5FB39A', '#C9803A'];
				return PAL[h % PAL.length];
			}
			function fmtDateTime(s) {
				if (!s) { return ''; }
				var d = new Date(String(s).replace(' ', 'T') + 'Z');
				if (isNaN(d.getTime())) { return String(s); }
				return d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
			}
			function relTime(s) {
				if (!s) { return ''; }
				var d = new Date(String(s).replace(' ', 'T') + 'Z');
				if (isNaN(d.getTime())) { return String(s); }
				var diff = (Date.now() - d.getTime()) / 1000;
				if (diff < 60) { return 'now'; }
				if (diff < 3600) { return Math.floor(diff / 60) + 'm'; }
				if (diff < 86400) { return Math.floor(diff / 3600) + 'h'; }
				if (diff < 86400 * 7) { return d.toLocaleDateString(undefined, { weekday: 'short' }); }
				var sameYear = d.getFullYear() === new Date().getFullYear();
				return d.toLocaleDateString(undefined, sameYear ? { month: 'short', day: 'numeric' } : { month: 'short', day: 'numeric', year: 'numeric' });
			}
			function avatarHtml(sender, extraClass) {
				return '<span class="zib-av ' + (extraClass || '') + '" style="background:' + avatarColor(sender.email || sender.name) + '">' + esc(initials(sender.name)) + '</span>';
			}

			var panes, nav, read, folSel, qInput;

			// v0.13.0: flip unread bolding on one list row in place (nav persists across open/back).
			function markRowUnread(id, unread) {
				var row = nav ? nav.querySelector('.zib-hit[data-id="' + (id | 0) + '"]') : null;
				if (row) { row.classList.toggle('zib-unread', !!unread); }
			}

			// ---- shell: a horizontal toolbar (no rail — the app shell already has one) + two panes ----
			function renderShell() {
				var compose = connCanSend
					? '<button type="button" class="zib-tb-btn zib-tb-compose" id="zib-new">✎ Compose</button>'
					: '';
				view.innerHTML =
					'<div class="zib-toolbar">' +
						compose +
						'<label class="zib-tb-fold"><span class="zib-tb-lbl">Folder</span>' +
							'<select class="zib-folsel" id="zib-folsel" aria-label="Folder"><option>Loading…</option></select></label>' +
						'<div class="zib-tb-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.2-4.2"/></svg>' +
							'<input type="search" class="zib-q" id="zib-q" placeholder="Search all mail…" autocomplete="off" aria-label="Search all mail"></div>' +
						'<div class="zib-seg zib-densityseg" role="group" aria-label="List density">' +
							'<button type="button" data-d="comfortable" title="Comfortable">≡</button>' +
							'<button type="button" data-d="cozy" title="Cozy">☰</button>' +
							'<button type="button" data-d="compact" title="Compact">▤</button>' +
						'</div>' +
					'</div>' +
					'<div class="zib-panes"><div class="zib-nav"></div><div class="zib-read">' + READ_EMPTY + '</div></div>';

				panes = view.querySelector('.zib-panes');
				nav = view.querySelector('.zib-nav');
				read = view.querySelector('.zib-read');
				folSel = view.querySelector('#zib-folsel');
				qInput = view.querySelector('#zib-q');

				var nb = view.querySelector('#zib-new');
				if (nb) { nb.addEventListener('click', function () { openCompose({ mode: 'new' }); }); }

				// density segmented control
				var dseg = view.querySelector('.zib-densityseg');
				function paintDensity() {
					var bs = dseg.querySelectorAll('button');
					for (var i = 0; i < bs.length; i++) { bs[i].setAttribute('aria-pressed', bs[i].getAttribute('data-d') === state.density ? 'true' : 'false'); }
				}
				dseg.addEventListener('click', function (e) {
					var b = e.target.closest('button'); if (!b) { return; }
					state.density = b.getAttribute('data-d'); saveDensity(state.density);
					card.setAttribute('data-zib-density', state.density); paintDensity();
				});
				paintDensity();

				// search (debounced) — empty query returns to the current folder
				var t = null;
				qInput.addEventListener('input', function () {
					var v = qInput.value.trim();
					if (t) { clearTimeout(t); }
					t = setTimeout(function () {
						if (v) { openSearch(v); }
						// v0.17.1: clearing a search returns to the folder you were browsing. (Was broken:
						// openSearch had overwritten state.folder with a hash-less search state, so the old
						// `state.folder.hash != null` guard was false and the list got stuck on empty.)
						else if (state.baseFolder) { openFolder(state.baseFolder.hash, state.baseFolder.name); }
					}, 350);
				});

				folSel.addEventListener('change', function () {
					var opt = folSel.options[folSel.selectedIndex];
					if (qInput) { qInput.value = ''; }
					openFolder(folSel.value, opt ? opt.getAttribute('data-name') : '');
				});
			}

			// ---- folders → populate the toolbar switcher, open Inbox by default ----
			function loadFolders() {
				ZDZ.ui.loading(nav, 'Loading your mail…');
				api('/folders', 'GET').then(function (res) {
					var fs = (res && res.folders) ? res.folders : [];
					// No per-folder index yet (the all-folder sync is not on): fall back to the coarse
					// buckets ingest always fills, so the mail list is reachable from day one.
					if (!fs.length) {
						fs = [
							{ hash: 'inbox', name: 'Inbox', well_known: 'inbox', count: 0, coarse: true },
							{ hash: 'sent', name: 'Sent', well_known: 'sentitems', count: 0, coarse: true }
						];
					}
					state.folders = fs;
					// order: inbox, sent, then the rest (as returned)
					var order = { inbox: 0, sentitems: 1, drafts: 2, archive: 3, junkemail: 4, deleteditems: 5 };
					var sorted = fs.slice().sort(function (a, b) {
						var ao = (a.well_known && order[a.well_known] != null) ? order[a.well_known] : 50;
						var bo = (b.well_known && order[b.well_known] != null) ? order[b.well_known] : 50;
						return ao - bo;
					});
					var opts = '';
					for (var i = 0; i < sorted.length; i++) {
						var f = sorted[i];
						opts += '<option value="' + esc(f.hash) + '" data-name="' + esc(f.name) + '">' + esc(f.name) + (f.coarse ? '' : ' (' + (f.count | 0) + ')') + '</option>';
					}
					folSel.innerHTML = opts || '<option value="">No folders</option>';
					if (!sorted.length) { nav.innerHTML = '<p class="zib-empty">No indexed folders yet.</p>'; return; }
					var inbox = null;
					for (var j = 0; j < sorted.length; j++) { if (sorted[j].well_known === 'inbox') { inbox = sorted[j]; break; } }
					if (!inbox) { inbox = sorted[0]; }
					folSel.value = inbox.hash;
					openFolder(inbox.hash, inbox.name);
				}).catch(function (err) {
					// the kit's sentence says why (no signal, signed out, server trouble), with Try again.
					nav.innerHTML = '<p class="zib-empty zib-err" role="alert">' + esc('Couldn’t load folders.' + why(err)) + retryBtn('zib-fol-retry') + '</p>';
					var rb = nav.querySelector('#zib-fol-retry'); if (rb) { rb.addEventListener('click', loadFolders); }
				});
			}

			function openFolder(hash, name) { state.folder = { hash: hash, name: name, search: null }; state.baseFolder = { hash: hash, name: name }; state.offset = 0; showList(); }
			function openSearch(q) { state.folder = { hash: null, name: 'Search: ' + q, search: q }; state.offset = 0; showList(); }

			// ---- message list (paginated) ----
			function listUrl() {
				if (state.folder.search) { return '/search?q=' + encodeURIComponent(state.folder.search) + '&limit=' + LIM + '&offset=' + state.offset; }
				return '/browse?folder=' + encodeURIComponent(state.folder.hash || '') + '&limit=' + LIM + '&offset=' + state.offset;
			}
			/* On a phone the page is the only scroll (CSS) — nested scroll areas made accidental
			   endless scrolling easy — so the list keeps its place the way Messages does:
			   opening a message brings the reader to the top, and Back returns to the same spot
			   in the same list instead of reloading it from the first message. */
			var PHONE_Q = '(max-width: 880px)';
			function isPhone() { try { return window.matchMedia(PHONE_Q).matches; } catch (e) { return false; } }
			function pageScroller(el) {
				for (var p = el && el.parentElement; p; p = p.parentElement) {
					var oy = getComputedStyle(p).overflowY;
					if ((oy === 'auto' || oy === 'scroll') && p.scrollHeight > p.clientHeight + 1) { return p; }
				}
				return document.scrollingElement || document.documentElement;
			}
			var listScroll = null;   // { el, top } where the list was when a message opened
			function rememberListScroll() {
				if (!isPhone()) { listScroll = null; return; }
				var sc = pageScroller(view); listScroll = { el: sc, top: sc.scrollTop };
			}
			function readerToTop() {
				if (!isPhone()) { return; }
				try { view.scrollIntoView({ block: 'start' }); } catch (e) { /* non-fatal */ }
			}
			function returnToList() {
				if (isPhone() && panes && nav && nav.querySelector('.zib-results .zib-hit')) {
					state.openId = null; state.openMsg = null;
					panes.classList.remove('zib-reading');
					read.innerHTML = READ_EMPTY;
					if (listScroll && listScroll.el) { listScroll.el.scrollTop = listScroll.top; }
					return;
				}
				showList();
			}
			function showList() {
				state.offset = 0;
				state.openId = null; state.openMsg = null;
				if (panes) { panes.classList.remove('zib-reading'); }
				read.innerHTML = READ_EMPTY;
				nav.innerHTML = '<div class="zib-results" role="listbox" aria-label="Messages"></div><div class="zib-more"></div>';
				loadMore();
			}
			/* "Load more" stays where it is and shows busy (ZDZ.ui.busy) instead of being swapped
			   for a loading line; the first page shows the kit's loader. A failure says why, with Try again. */
			function loadMore(btn) {
				var results = nav.querySelector('.zib-results');
				var more = nav.querySelector('.zib-more');
				var useBtn = btn && btn.nodeType === 1 ? btn : null;
				if (useBtn && useBtn.getAttribute('aria-busy') === 'true') { return; } // a double tap adds the page once
				if (more && !useBtn) { ZDZ.ui.loading(more, 'Loading…'); }
				var url = listUrl();
				var req = useBtn ? ZDZ.ui.busy(useBtn, function () { return api(url, 'GET'); }) : api(url, 'GET');
				return req.then(function (res) {
					var list = (res && res.results) ? res.results : [];
					if (state.offset === 0 && !list.length) { results.innerHTML = '<p class="zib-empty">No mail here.</p>'; if (more) { more.innerHTML = ''; } return; }
					var html = '';
					for (var i = 0; i < list.length; i++) {
						var r = list[i];
						if (movedThisSession[r.id]) { continue; } // it left this folder — don't show the stale index row
						var sender = parseSender(r.from);
						var unread = r.unread && !readThisSession[r.id];
						html += '<button type="button" role="option" class="zib-hit' + (unread ? ' zib-unread' : '') + '" data-id="' + (r.id | 0) + '">' +
							'<span class="zib-udot" aria-hidden="true"></span>' +
							avatarHtml(sender, 'zib-hit-av') +
							'<span class="zib-hit-main">' +
								'<span class="zib-hit-top"><span class="zib-hit-from">' + esc(sender.name) + '</span>' +
								'<span class="zib-hit-date">' + esc(relTime(r.received_at)) + '</span></span>' +
								'<span class="zib-hit-subj">' + esc(r.subject || '(no subject)') + (r.has_attachments ? ' <span class="zib-clip" title="Has attachments">📎</span>' : '') + '</span>' +
								'<span class="zib-hit-snip">' + esc(r.snippet || '') + '</span>' +
							'</span></button>';
					}
					results.insertAdjacentHTML('beforeend', html);
					var hits = results.querySelectorAll('.zib-hit:not([data-wired])');
					for (var j = 0; j < hits.length; j++) {
						hits[j].setAttribute('data-wired', '1');
						hits[j].addEventListener('click', function () { selectRow(this); openMsg(this.getAttribute('data-id')); });
					}
					state.offset += list.length;
					if (more) {
						more.innerHTML = (list.length >= LIM) ? '<button type="button" class="zib-btn" id="zib-loadmore">Load more</button>' : '';
						var lm = nav.querySelector('#zib-loadmore');
						if (lm) { lm.addEventListener('click', function () { loadMore(lm); }); }
					}
				}).catch(function (err) {
					if (!more) { return; }
					var lead = state.offset === 0 ? 'Couldn’t load your mail.' : 'Couldn’t load more mail.';
					more.innerHTML = '<p class="zib-empty zib-err" role="alert">' + esc(lead + why(err)) + retryBtn('zib-more-retry') + '</p>';
					var rb = more.querySelector('#zib-more-retry');
					if (rb) { rb.addEventListener('click', function () { loadMore(rb); }); }
				});
			}
			function selectRow(el) {
				var prev = nav.querySelectorAll('.zib-hit[aria-selected="true"]');
				for (var i = 0; i < prev.length; i++) { prev[i].removeAttribute('aria-selected'); }
				if (el) { el.setAttribute('aria-selected', 'true'); }
			}

			// ══ v0.17.0: keyboard-driven, reversible triage (research: fast + undo, not confirm) ══
			// "engaged" gates shortcuts to when the user is actually working in THIS widget (it's one of
			// many on the app shell's page): entering by click/focus arms them; a click outside disarms.
			var engaged = false, pendingTriage = null;
			card.addEventListener('focusin', function () { engaged = true; });
			card.addEventListener('pointerdown', function () { engaged = true; });
			document.addEventListener('pointerdown', function (e) { if (!card.contains(e.target)) { engaged = false; } });

			function visRows() { return nav ? [].slice.call(nav.querySelectorAll('.zib-hit')).filter(function (el) { return el.style.display !== 'none'; }) : []; }
			function selectedRow() { return nav ? nav.querySelector('.zib-hit[aria-selected="true"]') : null; }
			function selectRowById(id) { var r = nav && nav.querySelector('.zib-hit[data-id="' + (id | 0) + '"]'); if (r) { selectRow(r); r.scrollIntoView({ block: 'nearest' }); } return r; }
			function moveFocus(dir) {
				var rows = visRows(); if (!rows.length) { return; }
				var cur = selectedRow(); var idx = cur ? rows.indexOf(cur) : -1;
				idx = (idx < 0) ? (dir > 0 ? 0 : rows.length - 1) : Math.max(0, Math.min(rows.length - 1, idx + dir));
				selectRow(rows[idx]);
				// v0.17.1: move DOM focus with the selection so the focus ring and the selection ring land on
				// the SAME row (previously the clicked row kept focus while selection moved → two rings).
				try { rows[idx].focus({ preventScroll: true }); } catch (e) { rows[idx].focus(); }
				rows[idx].scrollIntoView({ block: 'nearest' });
			}
			function nextRowId(id) {
				var rows = visRows();
				for (var i = 0; i < rows.length; i++) {
					if (rows[i].getAttribute('data-id') === String(id | 0)) { var n = rows[i + 1] || rows[i - 1]; return n ? n.getAttribute('data-id') : null; }
				}
				return null;
			}

			// ── Undo snackbar — one pending action at a time; a new one flushes (commits) the prior ──
			/* the Undo bar is the kit's toast (one action, the app's one toast
			   place), up for exactly the hold window; the move is sent when the window ends. */
			var snack = null;
			function hideSnack() { if (snack) { snack.close(); snack = null; } }
			function flushPending() { if (pendingTriage) { var p = pendingTriage; pendingTriage = null; clearTimeout(p.timer); p.commit(); hideSnack(); } }
			function showSnack(label, secs, onUndo) {
				hideSnack();
				snack = ZDZ.ui.toast(label, { action: 'Undo', onAction: onUndo, duration: secs * 1000 });
				return snack;
			}
			// Optimistically remove a message, auto-advance to the next, and hold the actual Graph move for
			// a few seconds behind a salient Undo (INV-WRITE stays owner-explicit; Undo is the mis-tap net).
			function triageWithUndo(curId, dest, verbPast) {
				if (!connCanTriage) { return; }
				flushPending();
				var row = nav && nav.querySelector('.zib-hit[data-id="' + (curId | 0) + '"]');
				var nId = nextRowId(curId);
				if (row) { row.style.display = 'none'; }
				if (nId) { selectRowById(nId); openMsg(nId); } else { showList(); }
				var secs = 5, committed = false;
				function commit() {
					if (committed) { return; } committed = true;
					api('/message/' + (curId | 0) + '/move', 'POST', { to: dest }).then(function (r) {
						if (r && r.ok) { movedThisSession[curId] = true; if (row) { row.remove(); } }
						else if (row) { row.style.display = ''; }
					}).catch(function (err) {
						// the message comes back to the list, and the kit's toast says why.
						if (row) { row.style.display = ''; }
						ZDZ.ui.toastError({ message: 'Couldn’t move that message.' + why(err) });
					});
				}
				var timer = null;
				showSnack(verbPast, secs, function undo() {
					clearTimeout(timer); pendingTriage = null; snack = null;
					if (row) { row.style.display = ''; }
					selectRowById(curId); openMsg(curId);
				});
				timer = setTimeout(function () { pendingTriage = null; commit(); hideSnack(); }, secs * 1000);
				pendingTriage = { curId: curId, timer: timer, commit: commit };
			}
			function triageMarkUnread(id, back) {
				if (!connCanTriage) { return; }
				api('/message/' + (id | 0) + '/mark-read', 'POST', { read: false }).then(function (r) {
					if (r && r.ok) { readThisSession[id] = false; markRowUnread(id, true); if (back) { returnToList(); } }
				}).catch(function (err) { ZDZ.ui.toastError({ message: 'Couldn’t mark it unread.' + why(err) }); });
			}

			// ── keyboard shortcuts (Gmail/Superhuman muscle memory), gated by "engaged" ──
			function kbHelp() {
				var existing = view.querySelector('#zib-kbhelp'); if (existing) { existing.remove(); return; }
				var rows = [['J / K', 'Next / previous'], ['Enter / O', 'Open message'], ['R / A / F', 'Reply / reply-all / forward'],
					['E', 'Archive'], ['#', 'Delete'], ['U', 'Mark unread'], ['C', 'Compose'], ['/', 'Search'], ['Esc', 'Back to list'], ['?', 'This help']];
				var li = rows.map(function (r) { return '<div class="zib-kb-row"><kbd>' + esc(r[0]) + '</kbd><span>' + esc(r[1]) + '</span></div>'; }).join('');
				var d = document.createElement('div'); d.id = 'zib-kbhelp'; d.className = 'zib-kbhelp';
				d.innerHTML = '<div class="zib-kbhelp-card"><div class="zib-kbhelp-h">Keyboard shortcuts</div>' + li +
					'<button type="button" class="zib-btn zib-kbhelp-x">Close</button></div>';
				d.addEventListener('click', function (e) { if (e.target === d || e.target.closest('.zib-kbhelp-x')) { d.remove(); } });
				view.appendChild(d);
			}
			document.addEventListener('keydown', function (e) {
				if (!engaged) { return; }
				var t = e.target;
				if (t && (/^(input|textarea|select)$/i.test(t.tagName) || t.isContentEditable)) { return; }
				if (e.metaKey || e.ctrlKey || e.altKey) { return; }
				// while a draft is open, only ? and Esc are shortcuts. Picking a Vault document
				// re-draws its row, focus falls to the page, and "e" or "#" would archive or delete the mail behind it.
				if (read && read.querySelector('.zib-c-body') && e.key !== '?' && e.key !== 'Escape') { return; }
				var reading = panes && panes.classList.contains('zib-reading');
				var oid = (state.openId != null && reading) ? state.openId : null;
				var k = e.key;
				if (k === 'j' || k === 'ArrowDown') { moveFocus(1); e.preventDefault(); }
				else if (k === 'k' || k === 'ArrowUp') { moveFocus(-1); e.preventDefault(); }
				else if (k === 'Enter' || k === 'o') { var s = selectedRow(); if (s) { openMsg(s.getAttribute('data-id')); e.preventDefault(); } }
				else if (k === 'c') { if (connCanSend) { openCompose({ mode: 'new' }); e.preventDefault(); } }
				else if (k === '/') { if (qInput) { qInput.focus(); e.preventDefault(); } }
				else if (k === '?') { kbHelp(); e.preventDefault(); }
				else if (k === 'Escape') {
					var h = view.querySelector('#zib-kbhelp');
					var menuOpen = view.querySelector('#zib-ovf-menu:not(.zib-hide)');
					var composing = read && read.querySelector('.zib-c-body');
					if (h) { h.remove(); e.preventDefault(); }
					else if (menuOpen) { /* the menu's own handler closes it — don't also navigate */ }
					else if (composing) { /* never discard a typed draft on Escape — use Cancel */ }
					else if (reading) { returnToList(); e.preventDefault(); }
				}
				else if (oid != null && k === 'e') { triageWithUndo(oid, 'archive', 'Archived'); e.preventDefault(); }
				else if (oid != null && (k === '#' || k === 'Delete')) { triageWithUndo(oid, 'deleteditems', 'Deleted'); e.preventDefault(); }
				else if (oid != null && k === 'u') { triageMarkUnread(oid, true); e.preventDefault(); }
				else if (oid != null && connCanSend && k === 'r') { openCompose({ mode: 'reply', id: oid, m: state.openMsg }); e.preventDefault(); }
				else if (oid != null && connCanSend && k === 'a') { openCompose({ mode: 'replyall', id: oid, m: state.openMsg }); e.preventDefault(); }
				else if (oid != null && connCanSend && k === 'f') { openCompose({ mode: 'forward', id: oid, m: state.openMsg }); e.preventDefault(); }
			});

			// ---- reader (sandboxed HTML, images off, fit-to-width) ----
			function frameDoc(bodyHtml, hideQuote) {
				return '<!doctype html><html><head><meta charset="utf-8">' +
					'<meta name="viewport" content="width=device-width,initial-scale=1">' +
					'<style>html{-webkit-text-size-adjust:100%;}' +
					'html,body{margin:0;padding:2px 2px 12px;background:#fff;color:#1c2333;' +
					'font:15px/1.55 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;' +
					'word-break:break-word;overflow-wrap:anywhere;}' +
					'img{max-width:100%!important;height:auto!important;}a{color:#2563eb;}' +
					/* v0.20.0: labelled placeholder for an embedded image we couldn't resolve (e.g. HEIC on a host without a decoder) */
					'.zib-img-x{display:inline-block;max-width:100%;padding:6px 10px;margin:2px 0;border:1px dashed #c7ccd6;border-radius:8px;color:#8a93a3;font-size:13px;background:#f6f7f9;}' +
					/* v0.18.0: set quoted reply history apart in HTML mail (Gmail/Outlook quote containers) */
					'blockquote,.gmail_quote,.gmail_quote_container{margin:0 0 12px;padding:2px 0 2px 14px;border-left:3px solid #c7ccd6;color:#5b6675;}' +
					/* v0.18.1: collapse the quoted reply history by default (parent re-renders to reveal). Target
					   only known quote containers so a blockquote in the NEW message is never hidden. */
					(hideQuote ? 'blockquote,.gmail_quote,.gmail_quote_container,.yahoo_quoted,.protonmail_quote,#divRplyFwdMsg,#appendonsend{display:none!important;}' : '') +
					'table{max-width:100%!important;width:auto!important;table-layout:auto!important;}' +
					'td,th{max-width:100%!important;}[width]{max-width:100%!important;}' +
					'*{max-width:100%!important;box-sizing:border-box;}' +
					/* v0.16.0: a thin, space-reserving scrollbar on the white sheet instead of a macOS overlay
					   bar floating over the email content (a fresh iframe document always reserves the width). */
					'html{scrollbar-width:thin;scrollbar-color:#c2c8d0 transparent;}' +
					'::-webkit-scrollbar{width:12px;height:12px;}' +
					'::-webkit-scrollbar-thumb{background:#c2c8d0;border-radius:8px;border:3px solid #fff;background-clip:padding-box;}' +
					'::-webkit-scrollbar-thumb:hover{background:#9aa2ad;}' +
					'::-webkit-scrollbar-track{background:#fff;}</style>' +
					'</head><body>' + bodyHtml + '</body></html>';
			}
			function withImages(html, cidMap, includeRemote) {
				// Reveal stashed images via the DOM — never by string-rewriting sanitized HTML,
				// which is the Roundcube CVE-2024-42009 anti-pattern (un-neutering / mutation bugs).
				// Two kinds of stashed <img data-zib-src>:
				//   • cid:… — an EMBEDDED part of this message. Resolved from cidMap (data: URIs the server
				//     returned from the owner-gated /inline read) and shown always; no network, no tracking.
				//     When cidMap is loaded but has no entry (e.g. HEIC on a host without a decoder), the
				//     image is replaced by a small "(not shown)" placeholder instead of a broken box.
				//   • everything else — a REMOTE image; revealed only when includeRemote (user opted in),
				//     and only to non-dangerous schemes (a belt over the server-side scheme pre-pass).
				// Parsing is inert (no fetch, no script) and the result still renders in sandbox="" .
				try {
					var doc = new DOMParser().parseFromString(String(html), 'text/html');
					var imgs = doc.querySelectorAll('img[data-zib-src]');
					var bad = /^\s*(?:javascript|vbscript|data|file|about|blob)\s*:/i;
					for (var i = 0; i < imgs.length; i++) {
						var img = imgs[i];
						var url = img.getAttribute('data-zib-src') || '';
						if (/^\s*cid:/i.test(url)) {
							var key = url.replace(/^\s*cid:/i, '').trim();
							img.removeAttribute('data-zib-src');
							if (cidMap && cidMap[key]) {
								img.setAttribute('src', cidMap[key]); // trusted, server-fetched bytes (data: URI)
							} else if (cidMap) {
								// Fetch done, but this part couldn't be shown — replace with a labelled placeholder.
								var span = doc.createElement('span');
								span.className = 'zib-img-x';
								span.textContent = '🖼 ' + (img.getAttribute('alt') || 'image') + ' (not shown)';
								if (img.parentNode) { img.parentNode.replaceChild(span, img); }
							} // else: still loading — leave srcless (blank) for this paint; a re-paint follows.
							continue;
						}
						// Remote image: leave it stashed (hidden) until the owner opts in via "Load images".
						if (!includeRemote) { continue; }
						img.removeAttribute('data-zib-src');
						if (url && '#' !== url && !bad.test(url)) { img.setAttribute('src', url); }
					}
					return doc.body ? doc.body.innerHTML : '';
				} catch (e) {
					return includeRemote ? String(html).replace(/\sdata-zib-src=/g, ' src=') : String(html);
				}
			}

			function renderBody(m) {
				var wrap = view.querySelector('.zib-r-bodywrap');
				if (m.body_html && m.body_html.length) {
					// v0.20.0: "Load images" is for REMOTE images only; embedded (cid:) images show without opt-in.
					var bar = m.has_remote_images
						? '<div class="zib-imgbar"><span>🚫 Images hidden for privacy</span> <button type="button" class="zib-btn zib-loadimg" id="zib-loadimg">Load images</button></div>'
						: '';
					// v0.18.1: does this HTML carry a quoted reply thread we can fold? (known client markers only)
					// A top-level <blockquote> in email is (almost) always the quoted reply — fold it too, not
					// just the named client containers. The "•••" toggle reveals it, so a rare new-message
					// blockquote is only a tap away.
					var hasInline = !!m.has_inline_images || /data-zib-src\s*=\s*["']cid:/i.test(m.body_html);
					var hasQuote = /<blockquote|gmail_quote|gmail_quote_container|yahoo_quoted|protonmail_quote|divRplyFwdMsg|appendonsend/i.test(m.body_html);
					var qtog = hasQuote ? '<button type="button" class="zib-quote-toggle zib-htmlq" id="zib-htmlq" title="Show quoted text" aria-expanded="false">•••</button>' : '';
					wrap.innerHTML = bar + qtog + '<div class="zib-r-framewrap"><iframe class="zib-r-frame" sandbox="" referrerpolicy="no-referrer" title="Email content"></iframe></div>';
					var frame = wrap.querySelector('.zib-r-frame');
					var imagesOn = false, quoteOn = false, cidMap = null;
					function paint() { frame.srcdoc = frameDoc(withImages(m.body_html, cidMap, imagesOn), hasQuote && !quoteOn); }
					paint(); // remote off; embedded (cid:) images resolve on the re-paint once /inline returns
					if (hasInline) {
						api('/message/' + (m.id | 0) + '/inline', 'GET').then(function (res) {
							cidMap = {}; // mark loaded even if empty, so an unresolved cid shows a placeholder, not a broken box
							var list = (res && res.ok && res.images) ? res.images : [];
							list.forEach(function (im) {
								if (im && im.cid && im.data_b64 && typeof im.content_type === 'string' && im.content_type.indexOf('image/') === 0) {
									cidMap[im.cid] = 'data:' + im.content_type + ';base64,' + im.data_b64;
								}
							});
							paint();
						}).catch(function () { cidMap = {}; paint(); });
					}
					var li = wrap.querySelector('#zib-loadimg');
					if (li) {
						li.addEventListener('click', function () {
							imagesOn = true; paint();
							var b = wrap.querySelector('.zib-imgbar');
							if (b && b.parentNode) { b.parentNode.removeChild(b); }
						});
					}
					var qt = wrap.querySelector('#zib-htmlq');
					if (qt) {
						qt.addEventListener('click', function () {
							quoteOn = !quoteOn;
							qt.setAttribute('aria-expanded', quoteOn ? 'true' : 'false');
							qt.title = quoteOn ? 'Hide quoted text' : 'Show quoted text';
							paint();
						});
					}
				} else {
					wrap.innerHTML = '<div class="zib-r-text"></div>';
					renderPlainText(wrap.querySelector('.zib-r-text'), m.body_text || '');
				}
			}

			// v0.18.0: readable plain-text body — paragraphs (blank-line separated), clickable links, and the
			// quoted reply history folded behind a "•••" toggle so the new message reads first. All built with
			// DOM text nodes + createElement, so the plain text stays plain (no HTML injection).
			function zibLinkify(text, into) {
				var re = /((?:https?:\/\/|www\.)[^\s<>()]+[^\s<>().,;:!?'"]|[^\s<>()@]+@[^\s<>()@]+\.[a-z]{2,})/gi;
				var last = 0, m;
				while ((m = re.exec(text))) {
					if (m.index > last) { into.appendChild(document.createTextNode(text.slice(last, m.index))); }
					var raw = m[0], isEmail = ( raw.indexOf('@') > -1 && raw.indexOf('/') < 0 );
					var a = document.createElement('a');
					a.href = isEmail ? ('mailto:' + raw) : ( /^https?:\/\//i.test(raw) ? raw : 'https://' + raw );
					a.textContent = raw; a.className = 'zib-r-link';
					if (!isEmail) { a.target = '_blank'; a.rel = 'noopener noreferrer nofollow'; }
					into.appendChild(a);
					last = m.index + raw.length;
				}
				if (last < text.length) { into.appendChild(document.createTextNode(text.slice(last))); }
			}
			function zibParas(text) {
				var frag = document.createDocumentFragment();
				var blocks = String(text).replace(/\n{3,}/g, '\n\n').split(/\n[ \t]*\n/);
				for (var i = 0; i < blocks.length; i++) {
					if (!blocks[i].replace(/\s/g, '')) { continue; }
					var p = document.createElement('p'); p.className = 'zib-r-p';
					zibLinkify(blocks[i], p);   // internal newlines preserved by white-space:pre-wrap in CSS
					frag.appendChild(p);
				}
				return frag;
			}
			function renderPlainText(host, text) {
				text = String(text || '').replace(/\r\n?/g, '\n');
				if (!text.trim()) { host.innerHTML = '<div class="zib-empty">No message body.</div>'; return; }
				var lines = text.split('\n'), qAt = -1;
				for (var i = 1; i < lines.length; i++) {
					var ln = lines[i].trim();
					if (/^on\b.+\bwrote:?$/i.test(ln) || /^-{2,}\s*original message\s*-{2,}$/i.test(ln) ||
						/^_{5,}$/.test(ln) || /^>/.test(ln) ||
						( /^from:\s*\S/i.test(ln) && /^\s*(sent|date|to|subject)\s*:/im.test(lines.slice(i, i + 5).join('\n')) )) {
						qAt = i; break;
					}
				}
				var head = (qAt >= 0) ? lines.slice(0, qAt).join('\n') : text;
				var quoted = (qAt >= 0) ? lines.slice(qAt).join('\n') : '';
				if (head.trim()) { host.appendChild(zibParas(head)); }
				if (quoted.trim()) {
					var toggle = document.createElement('button');
					toggle.type = 'button'; toggle.className = 'zib-quote-toggle';
					toggle.textContent = '•••'; toggle.title = 'Show quoted text'; toggle.setAttribute('aria-expanded', 'false');
					var q = document.createElement('div'); q.className = 'zib-quote zib-hide';
					q.appendChild(zibParas(quoted.replace(/^\s?>+\s?/gm, '')));
					toggle.addEventListener('click', function () {
						var hidden = q.classList.toggle('zib-hide');
						toggle.setAttribute('aria-expanded', hidden ? 'false' : 'true');
						toggle.title = hidden ? 'Show quoted text' : 'Hide quoted text';
					});
					host.appendChild(toggle); host.appendChild(q);
				}
			}

			function renderAttach(id) {
				var box = view.querySelector('.zib-r-attach');
				box.innerHTML = '<button type="button" class="zib-btn zib-attbtn" id="zib-attbtn">📎 View attachments</button>';
				var btn = box.querySelector('#zib-attbtn');
				// the button shows busy (ZDZ.ui.busy keeps its size; a second tap is not a second
				// request) instead of being disabled and relabeled; a failure is the kit's toast, with Try again.
				function loadAtts() {
					if (btn.getAttribute('aria-busy') === 'true') { return; }
					ZDZ.ui.busy(btn, function () { return api('/message/' + (id | 0) + '/attachments', 'GET'); }).then(function (res) {
						var imgs = (res && res.images) ? res.images : [];
						var others = (res && res.others) ? res.others : [];
						var host = document.createElement('div'); host.className = 'zib-atts';
						if (!imgs.length && !others.length) { host.innerHTML = '<div class="zib-empty">No previewable attachments.</div>'; }
						imgs.forEach(function (im) {
							var fig = document.createElement('figure'); fig.className = 'zib-att-fig';
							var image = document.createElement('img'); image.className = 'zib-att-img';
							image.alt = String(im.name || 'image'); image.title = 'Click to view full size';
							image.addEventListener('click', function () { lightbox(image.src, image.alt); });
							var cap = document.createElement('figcaption'); cap.className = 'zib-att-cap'; cap.textContent = String(im.name || '');
							fig.appendChild(image); fig.appendChild(cap); host.appendChild(fig);
							api('/message/' + (id | 0) + '/attachment?att=' + encodeURIComponent(String(im.att_id || '')), 'GET').then(function (d) {
								if (d && d.ok && d.data_b64 && typeof d.content_type === 'string' && d.content_type.indexOf('image/') === 0) {
									image.src = 'data:' + d.content_type + ';base64,' + d.data_b64;
								} else { image.remove(); cap.textContent = String(im.name || 'image') + ' — couldn’t load'; cap.className = 'zib-att-cap zib-att-err'; }
							}).catch(function () { image.remove(); cap.textContent = String(im.name || 'image') + ' — couldn’t load'; cap.className = 'zib-att-cap zib-att-err'; });
						});
						others.forEach(function (o) {
							var c = document.createElement('div'); c.className = 'zib-att-name';
							c.textContent = '📎 ' + String(o.name || 'attachment') + ' (not shown)'; host.appendChild(c);
						});
						box.innerHTML = ''; box.appendChild(host);
					}).catch(function (err) { ZDZ.ui.toastError({ message: 'Couldn’t load the attachments.' + why(err) }, loadAtts); });
				}
				btn.addEventListener('click', loadAtts);
			}

			function lightbox(src, alt) {
				if (!src) { return; }
				var ex = document.querySelector('.zib-img-overlay'); if (ex && ex.parentNode) { ex.parentNode.removeChild(ex); }
				var ov = document.createElement('div'); ov.className = 'zib-img-overlay';
				var big = document.createElement('img'); big.className = 'zib-img-overlay-img'; big.src = src; big.alt = String(alt || '');
				ov.appendChild(big);
				function close() { if (ov.parentNode) { ov.parentNode.removeChild(ov); } document.removeEventListener('keydown', onKey); }
				function onKey(e) { if (e.key === 'Escape' || e.keyCode === 27) { close(); } }
				ov.addEventListener('click', close); document.addEventListener('keydown', onKey);
				document.body.appendChild(ov);
			}

			function backToList() {
				return '<button type="button" class="zib-btn zib-back zib-rd-back" id="zib-tolist">← ' + esc(state.folder ? state.folder.name : 'Back') + '</button>';
			}

			function openMsg(id) {
				if (panes && !panes.classList.contains('zib-reading')) { rememberListScroll(); }
				if (panes) { panes.classList.add('zib-reading'); }
				readerToTop();
				ZDZ.ui.loading(read, 'Loading…');
				/* a message that will not open says why (the kit's sentence), offers Try again, and
				   keeps the way back to the list - on a phone the list is hidden while the reader shows. */
				function openFailed(err) {
					read.innerHTML = '<div class="zib-rd">' + backToList() +
						'<p class="zib-empty zib-err" role="alert">' + esc('Couldn’t open that message.' + why(err)) + retryBtn('zib-open-retry') + '</p></div>';
					var bk = read.querySelector('#zib-tolist'); if (bk) { bk.addEventListener('click', returnToList); }
					var rb = read.querySelector('#zib-open-retry'); if (rb) { rb.addEventListener('click', function () { openMsg(id); }); }
				}
				api('/message/' + (id | 0), 'GET').then(function (res) {
					var m = res && res.message;
					if (!m) { openFailed(null); return; }
					state.openId = (id | 0); state.openMsg = m;   // v0.17.0: for keyboard reader-actions
					var sender = parseSender(m.from);
					var actions = '';
					if (connCanSend) {
						actions +=
							'<button type="button" class="zib-btn zib-primary zib-rbtn" data-act="reply">↩ Reply</button>' +
							'<button type="button" class="zib-btn zib-rbtn" data-act="replyall" title="Reply all">↩↩</button>' +
							'<button type="button" class="zib-btn zib-rbtn" data-act="forward" title="Forward">➦</button>';
					}
					if (connCanTriage) {
						actions +=
							'<div class="zib-ovf"><button type="button" class="zib-btn zib-ovf-btn" id="zib-ovf-btn" aria-haspopup="true" aria-expanded="false" title="More actions">⋯</button>' +
							'<div class="zib-ovf-menu zib-hide" id="zib-ovf-menu"></div></div>';
					}
					read.innerHTML =
						'<div class="zib-rd">' +
							backToList() +
							'<h4 class="zib-r-subj"></h4>' +
							'<div class="zib-rd-head">' + avatarHtml(sender, 'zib-rd-av') +
								'<span class="zib-rd-id"><b class="zib-rd-name"></b><span class="zib-rd-to"> → you</span> ' +
								'<button type="button" class="zib-rd-exp" id="zib-rd-exp">details ▾</button></span>' +
								'<span class="zib-rd-when"></span>' +
							'</div>' +
							'<pre class="zib-r-meta zib-hide"></pre>' +
							'<div class="zib-rd-actions">' + actions + '</div>' +
							'<div class="zib-movepick zib-hide"></div><div class="zib-tri-status"></div>' +
							'<div class="zib-r-bodywrap"></div><div class="zib-r-attach"></div>' +
						'</div>';
					var back = read.querySelector('#zib-tolist'); if (back) { back.addEventListener('click', returnToList); }
					read.querySelector('.zib-r-subj').textContent = m.subject || '(no subject)';
					read.querySelector('.zib-rd-name').textContent = sender.name;
					read.querySelector('.zib-rd-when').textContent = m.received_at ? fmtDateTime(m.received_at) : '';
					var meta = 'From: ' + m.from + '\n' + (m.to && m.to.length ? 'To: ' + m.to.join(', ') + '\n' : '') +
						(m.cc && m.cc.length ? 'Cc: ' + m.cc.join(', ') + '\n' : '') + (m.received_at ? fmtDateTime(m.received_at) : '');
					read.querySelector('.zib-r-meta').textContent = meta; // textContent — headers are safe text
					var exp = read.querySelector('#zib-rd-exp');
					if (exp) { exp.addEventListener('click', function () { read.querySelector('.zib-r-meta').classList.toggle('zib-hide'); }); }
					var rbs = read.querySelectorAll('.zib-rbtn');
					for (var ri = 0; ri < rbs.length; ri++) {
						rbs[ri].addEventListener('click', function () { openCompose({ mode: this.getAttribute('data-act'), id: id, m: m }); });
					}
					renderBody(m);
					if (m.has_attachments) { renderAttach(id); }
					if (connCanTriage) { setupTriage(id); }
					// once more with the message in place - while it loaded, the page may have been too
					// short to bring the reader to the top (Email last on the dashboard).
					readerToTop();
				}).catch(openFailed);
			}

			// ---- triage (INV-WRITE: owner-explicit) — now in an overflow menu ----
			function setupTriage(id) {
				// auto-mark-read on open, once per message per session (the owner’s own act of opening)
				if (!readThisSession[id]) { readThisSession[id] = true; markRowUnread(id, false); api('/message/' + (id | 0) + '/mark-read', 'POST', { read: true }).catch(function () {}); }
				var ovfBtn = view.querySelector('#zib-ovf-btn');
				var menu = view.querySelector('#zib-ovf-menu');
				var pick = view.querySelector('.zib-movepick');
				var st = view.querySelector('.zib-tri-status');
				if (!ovfBtn || !menu) { return; }
				menu.innerHTML =
					'<button type="button" class="zib-ovf-item" data-tri="unread">✉ Mark unread</button>' +
					'<button type="button" class="zib-ovf-item" data-tri="archive">🗄 Archive</button>' +
					'<button type="button" class="zib-ovf-item" data-tri="junkemail">⚑ Spam</button>' +
					'<button type="button" class="zib-ovf-item" data-tri="move">📁 Move to…</button>' +
					'<div class="zib-ovf-sep"></div>' +
					'<button type="button" class="zib-ovf-item zib-ovf-danger" data-tri="deleteditems">🗑 Delete</button>';
				// v0.16.0: the menu sits above the app shell's sticky bars (z-index in CSS) and flips up
				// when the ⋯ is low in the viewport so it never opens off the bottom. Dismissers are attached
				// only while open and removed on close (no listener leak); scroll/resize are captured so a
				// scroll on ANY ancestor (the app shell's own scroller) closes the menu instead of letting it
				// strand behind the sticky app-switcher bar.
				function placeMenu() {
					var b = ovfBtn.getBoundingClientRect();
					menu.classList.toggle('zib-flip-up', (b.bottom + 300) > window.innerHeight);
				}
				function closeMenu() {
					if (menu.classList.contains('zib-hide')) { return; }
					menu.classList.add('zib-hide');
					ovfBtn.setAttribute('aria-expanded', 'false');
					document.removeEventListener('click', onDocClick, true);
					document.removeEventListener('keydown', onKeyClose, true);
					window.removeEventListener('scroll', closeMenu, true);
					window.removeEventListener('resize', closeMenu, true);
				}
				function onDocClick(e) { if (!e.target.closest('.zib-ovf')) { closeMenu(); } }
				function onKeyClose(e) { if (e.key === 'Escape' || e.keyCode === 27) { e.stopPropagation(); e.preventDefault(); closeMenu(); if (ovfBtn.focus) { ovfBtn.focus(); } } }
				function openMenu() {
					menu.classList.remove('zib-hide');
					ovfBtn.setAttribute('aria-expanded', 'true');
					placeMenu();
					setTimeout(function () {   // next tick, so THIS opening click doesn't immediately close it
						document.addEventListener('click', onDocClick, true);
						document.addEventListener('keydown', onKeyClose, true);
						window.addEventListener('scroll', closeMenu, true);
						window.addEventListener('resize', closeMenu, true);
					}, 0);
				}
				ovfBtn.addEventListener('click', function (e) {
					e.stopPropagation();
					if (menu.classList.contains('zib-hide')) { openMenu(); } else { closeMenu(); }
				});
				// v0.17.0: archive / spam / delete / move now go through triageWithUndo (optimistic remove +
				// auto-advance + a salient Undo window) instead of an immediate, irreversible move.
				function pickFolder() {
					closeMenu(); pick.classList.remove('zib-hide');
					ZDZ.ui.loading(pick, 'Loading folders…');
					api('/folders', 'GET').then(function (res) {
						var fs = (res && res.folders) ? res.folders : [];
						if (!fs.length) { pick.innerHTML = '<div class="zib-empty">No folders to move to.</div>'; return; }
						var opts = '';
						for (var i = 0; i < fs.length; i++) { opts += '<option value="' + esc(fs[i].hash) + '">' + esc(fs[i].name) + '</option>'; }
						pick.innerHTML = '<select class="zib-move-sel" aria-label="Move to folder">' + opts + '</select> <button type="button" class="zib-btn zib-tbtn" id="zib-move-go">Move here</button> <button type="button" class="zib-btn zib-linkbtn" id="zib-move-cancel">Cancel</button>';
						pick.querySelector('#zib-move-go').addEventListener('click', function () {
							var h = pick.querySelector('.zib-move-sel').value;
							if (h) { pick.classList.add('zib-hide'); triageWithUndo(id, h, 'Moved'); }
						});
						pick.querySelector('#zib-move-cancel').addEventListener('click', function () { pick.classList.add('zib-hide'); });
					}).catch(function (err) { pick.innerHTML = '<div class="zib-empty zib-err" role="alert">' + esc('Couldn’t load folders.' + why(err)) + '</div>'; });
				}
				var items = menu.querySelectorAll('.zib-ovf-item');
				for (var i = 0; i < items.length; i++) {
					items[i].addEventListener('click', function () {
						var act = this.getAttribute('data-tri');
						closeMenu();
						if (act === 'unread') { triageMarkUnread(id, false); }
						else if (act === 'move') { pickFolder(); }
						else if (act === 'junkemail') { triageWithUndo(id, 'junkemail', 'Marked spam'); }
						else if (act === 'deleteditems') { triageWithUndo(id, 'deleteditems', 'Deleted'); }
						else { triageWithUndo(id, 'archive', 'Archived'); }
					});
				}
			}

			// v0.19.0: turn a .zib-chips container (with a .zib-chip-input) into a recipient chip field.
			// Tokenizes on Enter / , / ; / blur / paste; each chip is format-validated (bad ones flagged);
			// Backspace on an empty input removes the last chip. value() re-assembles the comma-separated
			// string the send API already expects; invalid() counts malformed chips.
			function makeChips(hostEl, initial) {
				var input = hostEl.querySelector('.zib-chip-input');
				var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
				function normalize(tok) { tok = String(tok).trim().replace(/[;,]+$/, '').trim(); var mm = tok.match(/<([^>]+)>/); return mm ? mm[1].trim() : tok; }
				function addChip(tok) {
					var addr = normalize(tok); if (!addr) { return; }
					var bad = !EMAIL.test(addr);
					var chip = document.createElement('span');
					chip.className = 'zib-chip-tag' + (bad ? ' zib-chip-bad' : '');
					chip.setAttribute('data-addr', addr);
					if (bad) { chip.title = 'Not a valid email address'; }
					var t = document.createElement('span'); t.className = 'zib-chip-txt'; t.textContent = addr;
					var x = document.createElement('button'); x.type = 'button'; x.className = 'zib-chip-x'; x.setAttribute('aria-label', 'Remove ' + addr); x.textContent = '×';
					x.addEventListener('click', function () { if (chip.parentNode) { chip.parentNode.removeChild(chip); } input.focus(); });
					chip.appendChild(t); chip.appendChild(x);
					hostEl.insertBefore(chip, input);
				}
				function commit() { var v = input.value; if (!v || !v.trim()) { input.value = ''; return; } v.split(/[,;]+/).forEach(function (pp) { if (pp.trim()) { addChip(pp); } }); input.value = ''; }
				input.addEventListener('keydown', function (e) {
					if (e.key === 'Enter' || e.key === ',' || e.key === ';') { e.preventDefault(); commit(); }
					else if (e.key === 'Backspace' && !input.value) { var cs = hostEl.querySelectorAll('.zib-chip-tag'); if (cs.length) { cs[cs.length - 1].parentNode.removeChild(cs[cs.length - 1]); } }
				});
				input.addEventListener('blur', commit);
				input.addEventListener('paste', function () { setTimeout(commit, 0); });
				hostEl.addEventListener('click', function (e) { if (e.target === hostEl) { input.focus(); } });
				if (initial) { String(initial).split(/[,;]+/).forEach(function (pp) { if (pp.trim()) { addChip(pp); } }); }
				return {
					value: function () { commit(); return [].slice.call(hostEl.querySelectorAll('.zib-chip-tag')).map(function (c) { return c.getAttribute('data-addr'); }).join(', '); },
					invalid: function () { commit(); return hostEl.querySelectorAll('.zib-chip-tag.zib-chip-bad').length; }
				};
			}

			// ---- compose (reply / reply-all / forward / new) — INV-SEND: owner clicks Send ----
			function openCompose(opts) {
				// on a phone, compose is a page of its own like a message - the list
				// remembers its place and Cancel on a new email returns there (Messages' pattern).
				if (panes && !panes.classList.contains('zib-reading')) { rememberListScroll(); }
				if (panes) { panes.classList.add('zib-reading'); }
				readerToTop();
				var mode = opts.mode;
				var m = opts.m || {};
				var isReply = (mode === 'reply' || mode === 'replyall');
				var isForward = (mode === 'forward');
				var isNew = (mode === 'new');
				var title = isNew ? 'New email' : (mode === 'reply' ? 'Reply' : (mode === 'replyall' ? 'Reply all' : 'Forward'));
				var pf = opts.prefill || {};

				var ctx = '';
				if (isReply) {
					ctx = '<div class="zib-c-ctx">To: ' + esc(mode === 'replyall' ? (parseSender(m.from).name + ' + all recipients') : parseSender(m.from).name) +
						(function(){ var s=String(m.subject||''); if(!s) return ''; /* v0.18.0: don't stack Re: */ return '<br>' + esc(/^\s*re\s*:/i.test(s) ? s : 'Re: ' + s); })() + '</div>';
				} else if (isForward) {
					ctx = '<div class="zib-c-ctx">Forwarding: ' + esc(m.subject || '(no subject)') + '</div>';
				}

				var fields = '';
				if (isNew || isForward) { fields += '<div class="zib-c-row"><span>To</span><div class="zib-chips" id="zib-to-chips"><input type="text" class="zib-chip-input" placeholder="name@example.com, …" autocomplete="off" aria-label="To recipients"></div></div>'; }  /* v0.19.0: recipient chips */
				if (isNew) {
					fields += '<div class="zib-c-row"><span>Cc</span><div class="zib-chips" id="zib-cc-chips"><input type="text" class="zib-chip-input" placeholder="optional" autocomplete="off" aria-label="Cc recipients"></div></div>';
					fields += '<label class="zib-c-row"><span>Subject</span><input type="text" class="zib-c-subj" placeholder="Subject"></label>';
				}
				fields += '<textarea class="zib-c-body" rows="9" placeholder="' + (isForward ? 'Add a note (optional)…' : 'Write your message…') + '"></textarea>';
				/* Vault documents. Each is attached or sent as a link that stops working after
				   14 or 30 days; a Private document is a link only (the server decides the same - ZIB_Compose::plan). */
				fields += '<div class="zib-c-docs"><div class="zib-docs-list" id="zib-docs-list"></div>' +
					'<button type="button" class="zib-btn zib-c-vault" id="zib-c-vault" aria-expanded="false" aria-controls="zib-vpick">📎 Vault document</button>' +
					'<div class="zib-vpick zib-hide" id="zib-vpick"><input type="search" class="zib-vp-q" placeholder="Search the Vault by title or Context" aria-label="Search the Vault" autocomplete="off">' +
					'<div class="zib-vp-res" aria-live="polite"></div></div></div>';

				read.innerHTML =
					'<div class="zib-rd">' +
						'<div class="zib-mailbar"><button type="button" class="zib-btn zib-back" id="zib-c-cancel">← Cancel</button>' +
						'<span class="zib-mailbar-nm">' + esc(title) + '</span></div>' + ctx +
						'<div class="zib-compose">' + fields +
						'<div class="zib-c-actions"><button type="button" class="zib-btn zib-primary zib-c-send" id="zib-c-send">Send</button>' +
						'<span class="zib-c-status"></span></div></div>' +
					'</div>';

				// restore any prefilled values (used by Undo / a failed send)
				var toChips = null, ccChips = null;   // v0.19.0: recipient chips (seed from prefill)
					var toHost = read.querySelector('#zib-to-chips'); if (toHost) { toChips = makeChips(toHost, pf.to); }
					var ccHost = read.querySelector('#zib-cc-chips'); if (ccHost) { ccChips = makeChips(ccHost, pf.cc); }
				if (pf.subj && read.querySelector('.zib-c-subj')) { read.querySelector('.zib-c-subj').value = pf.subj; }
				if (pf.body && read.querySelector('.zib-c-body')) { read.querySelector('.zib-c-body').value = pf.body; }

				// the documents on this email (restored by Undo, a failed send, or the chat's handoff).
				var docs = [];
				(pf.docs || []).forEach(function (d) { addDoc(d); });
				function addDoc(d) {
					var id = parseInt(d && d.id, 10) || 0;
					if (!id || docs.length >= 5) { return false; }
					for (var i = 0; i < docs.length; i++) { if (docs[i].id === id) { return false; } }
					var priv = !!d.is_private;
					var how = (priv || d.how === 'link') ? 'link' : 'attach';
					docs.push({ id: id, title: String(d.title || 'Document'), is_private: priv, size: parseInt(d.size, 10) || 0,
						how: how, days: (parseInt(d.days, 10) === 30 ? 30 : 14) });
					return true;
				}
				var docsList = read.querySelector('#zib-docs-list');
				var maxTotal = 10485760; // the server's zib_attach_max_total, updated from each Vault search
				function renderDocs() {
					if (!docsList) { return; }
					var used = 0;
					docsList.innerHTML = docs.map(function (d, i) {
						var v = d.how === 'attach' ? 'attach' : ('link' + d.days);
						// Say what the server will do: past the size limit, Attach goes as a link.
						var asLink = false;
						if (d.how === 'attach') { if (d.size <= 0 || used + d.size > maxTotal) { asLink = true; } else { used += d.size; } }
						return '<div class="zib-doc" data-i="' + i + '">' +
							'<span class="zib-doc-t">📄 ' + esc(d.title) + (d.is_private ? ' <span class="zib-doc-priv">Private</span>' : '') + '</span>' +
							'<select class="zib-doc-how" aria-label="' + esc('How to send ' + d.title) + '">' +
								(d.is_private ? '' : '<option value="attach"' + (v === 'attach' ? ' selected' : '') + '>Attach</option>') +
								'<option value="link14"' + (v === 'link14' ? ' selected' : '') + '>Link · 14 days</option>' +
								'<option value="link30"' + (v === 'link30' ? ' selected' : '') + '>Link · 30 days</option>' +
							'</select>' +
							'<button type="button" class="zib-chip-x zib-doc-x" aria-label="' + esc('Remove ' + d.title) + '">×</button>' +
							(asLink ? '<span class="zib-doc-note">Too big to attach with the rest, so it goes as a ' + d.days + '-day link.</span>' : '') +
						'</div>';
					}).join('') + (docs.some(function (d) { return d.is_private; })
						? '<p class="zib-doc-note">A Private document goes as a link that stops working on the day shown in the email.</p>' : '');
				}
				if (docsList) {
					docsList.addEventListener('change', function (e) {
						var sel = e.target.closest ? e.target.closest('.zib-doc-how') : null; if (!sel) { return; }
						var d = docs[parseInt(sel.closest('.zib-doc').getAttribute('data-i'), 10)]; if (!d) { return; }
						if (sel.value === 'attach' && !d.is_private) { d.how = 'attach'; } else { d.how = 'link'; d.days = sel.value === 'link30' ? 30 : 14; }
						// the "too big" notes depend on every row's choice: draw again, keep focus on this choice
						var at = sel.closest('.zib-doc').getAttribute('data-i');
						renderDocs();
						var again = docsList.querySelector('.zib-doc[data-i="' + at + '"] .zib-doc-how'); if (again) { again.focus(); }
					});
					docsList.addEventListener('click', function (e) {
						var x = e.target.closest ? e.target.closest('.zib-doc-x') : null; if (!x) { return; }
						docs.splice(parseInt(x.closest('.zib-doc').getAttribute('data-i'), 10), 1);
						renderDocs();
						if (vBtn) { vBtn.focus(); } // never leave focus on the page
					});
				}
				renderDocs();
				var vBtn = read.querySelector('#zib-c-vault'), vPick = read.querySelector('#zib-vpick');
				var vQ = vPick ? vPick.querySelector('.zib-vp-q') : null, vRes = vPick ? vPick.querySelector('.zib-vp-res') : null;
				var vTimer = null, vSeq = 0, vItems = [];
				function vSize(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : (n > 0 ? Math.max(1, Math.round(n / 1024)) + ' KB' : ''); }
				function vSearch() {
					var q = (vQ.value || '').trim();
					if (q.length < 2) { vRes.innerHTML = ''; return; }
					var seq = ++vSeq;
					ZDZ.ui.loading(vRes, 'Searching the Vault…');
					api('/vault/find?q=' + encodeURIComponent(q), 'GET').then(function (r) {
						if (seq !== vSeq) { return; }
						if (r && r.max_total > 0) { maxTotal = r.max_total; }
						if (!r || r.vault === false) { vRes.innerHTML = '<p class="zib-vp-msg">The Knowledge Vault isn’t available.</p>'; return; }
						vItems = (r.items || []);
						if (!vItems.length) { vRes.innerHTML = '<p class="zib-vp-msg">No document you can open matches “' + esc(q) + '”.</p>'; return; }
						vRes.innerHTML = vItems.map(function (it, i) {
							return '<button type="button" class="zib-vp-item" data-i="' + i + '"><span class="zib-vp-t">' + esc(it.title || it.name || 'Document') + '</span>' +
								(it.is_private ? ' <span class="zib-doc-priv">Private</span>' : '') +
								'<span class="zib-vp-m">' + esc(vSize(it.size)) + '</span></button>';
						}).join('');
					}).catch(function (err) {
						if (seq !== vSeq) { return; }
						vRes.innerHTML = '<p class="zib-vp-msg">' + esc('Couldn’t search the Vault.' + (why(err) || ' Try again.')) + '</p>';
					});
				}
				if (vBtn && vPick) {
					vBtn.addEventListener('click', function () {
						var open = vPick.classList.toggle('zib-hide') === false;
						vBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
						if (open && vQ) { vQ.focus(); }
					});
					vQ.addEventListener('input', function () { clearTimeout(vTimer); vTimer = setTimeout(vSearch, 300); });
					vQ.addEventListener('keydown', function (e) {
						if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); vPick.classList.add('zib-hide'); vBtn.setAttribute('aria-expanded', 'false'); vBtn.focus(); }
					});
					vRes.addEventListener('click', function (e) {
						var b = e.target.closest ? e.target.closest('.zib-vp-item') : null; if (!b) { return; }
						var it = vItems[parseInt(b.getAttribute('data-i'), 10)]; if (!it) { return; }
						if (!addDoc(it)) { status.textContent = docs.length >= 5 ? 'Five documents is the most one email can carry.' : 'That document is already on this email.'; status.className = 'zib-c-status zib-c-err'; return; }
						renderDocs();
						vSeq++; clearTimeout(vTimer); // a search still on its way must not draw into the closed picker
						vPick.classList.add('zib-hide'); vBtn.setAttribute('aria-expanded', 'false'); vQ.value = ''; vRes.innerHTML = '';
						var sel = docsList && docsList.querySelector('.zib-doc[data-i="' + (docs.length - 1) + '"] .zib-doc-how');
						if (sel) { sel.focus(); } else { vBtn.focus(); } // never leave focus on the page
					});
				}

				var status = read.querySelector('.zib-c-status');
				readerToTop(); // again now the form is in place (see openMsg)
				read.querySelector('#zib-c-cancel').addEventListener('click', function () { if (isNew) { returnToList(); } else { openMsg(opts.id); } });

				status = read.querySelector('.zib-c-status');
				if (opts.error) { status.textContent = opts.error; status.className = 'zib-c-status zib-c-err'; }

				read.querySelector('#zib-c-send').addEventListener('click', function () {
					var body = (read.querySelector('.zib-c-body') || {}).value || '';
					var to = toChips ? toChips.value() : '';
					var cc = ccChips ? ccChips.value() : '';
					var subj = (read.querySelector('.zib-c-subj') || {}).value || '';
					if ((isNew || isForward) && !to.trim()) { status.textContent = 'Add at least one recipient.'; status.className = 'zib-c-status zib-c-err'; return; }
					if ((toChips && toChips.invalid()) || (ccChips && ccChips.invalid())) { status.textContent = 'Check the highlighted address(es).'; status.className = 'zib-c-status zib-c-err'; return; }
					if (isReply && !body.trim() && !docs.length) { status.textContent = 'Write a message first.'; status.className = 'zib-c-status zib-c-err'; return; }
					var sentDocs = docs.map(function (d) { return { id: d.id, how: d.how, days: d.days }; });
					var keptDocs = docs.slice();
					// INV-SEND stays explicit — but via a non-blocking Undo window, not a modal confirm.
					sendWithUndo({ mode: mode, id: opts.id, all: (mode === 'replyall'), to: to, cc: cc, subj: subj, body: body, docs: sentDocs },
						function restore(err) { openCompose({ mode: mode, id: opts.id, m: m, prefill: { to: to, cc: cc, subj: subj, body: body, docs: keptDocs }, error: err }); });
				});
			}

			// Optimistic send: collapse to a "Sending… / Undo" panel, fire after a short window.
			/* the panel's working sign is the kit's loader (its label counts
			   down), not a private spinner. A failed send goes back to the draft with the reason: a reason
			   the server names keeps its own words; anything else is "Couldn’t send." plus the kit's
			   sentence (no signal, signed out, server trouble). */
			function sendWithUndo(p, restore) {
				var SECS = 5, undone = false, fired = false, remain = SECS, tick = null, timer = null;
				read.innerHTML =
					'<div class="zib-rd"><div class="zib-sendpending">' +
						'<div class="zib-sp-load"></div>' +
						'<button type="button" class="zib-btn zib-linkbtn" id="zib-undo">Undo</button>' +
						'<button type="button" class="zib-btn zib-linkbtn" id="zib-sendnow">Send now</button>' +
					'</div></div>';
				var label = function (n) { return 'Sending in ' + n + ' s…'; };
				var ld = ZDZ.ui.loading(read.querySelector('.zib-sp-load'), label(SECS));
				tick = setInterval(function () { remain--; ld.label(label(Math.max(0, remain))); }, 1000);
				function cleanup() { clearInterval(tick); clearTimeout(timer); }
				function fire() {
					if (undone || fired) { return; }
					fired = true;
					cleanup();
					var pend = read.querySelector('.zib-sendpending');
					if (pend) { pend.innerHTML = '<div class="zib-sp-load"></div>'; ZDZ.ui.loading(pend.querySelector('.zib-sp-load'), 'Sending…'); }
					var req;
					// Vault documents ride along as docs:[{id, how, days}] - the server's one compose send.
					var hasDocs = !!(p.docs && p.docs.length);
					var withDocs = function (o) { if (hasDocs) { o.docs = p.docs; } return o; };
					var longOpt = hasDocs ? { timeout: 120000 } : null;
					if (p.mode === 'reply' || p.mode === 'replyall') { req = api('/message/' + (p.id | 0) + '/reply', 'POST', withDocs({ comment: p.body, all: p.all }), longOpt); }
					else if (p.mode === 'forward') { req = api('/message/' + (p.id | 0) + '/forward', 'POST', withDocs({ to: p.to, comment: p.body }), longOpt); }
					else { req = api('/send', 'POST', withDocs({ to: p.to, cc: p.cc, subject: p.subj, body: p.body }), longOpt); }
					req.then(function (res) {
						if (res && res.ok) {
							read.innerHTML = '<div class="zib-rd"><div class="zib-sent">✓ Sent</div>' +
								'<button type="button" class="zib-btn zib-back" id="zib-s-done">← Back to mail</button></div>';
							read.querySelector('#zib-s-done').addEventListener('click', showList);
						} else {
							restore(sendError(res && (res.reason || (res.data && res.data.reason)), null));
						}
					}).catch(function (err) {
						// With documents, a server error with no reason (a gateway's 504 page, the server running
						// out of time) or a signal lost after the request left may follow a delivery.
						var rs = reasonOf(err);
						if (hasDocs && !rs && err && (err.kind === 'server' || err.kind === 'offline' || err.kind === 'response' || err.kind === 'timeout')) { rs = 'unsure'; }
						restore(sendError(rs, err));
					});
				}
				timer = setTimeout(fire, SECS * 1000);
				read.querySelector('#zib-undo').addEventListener('click', function () { if (fired) { return; } undone = true; cleanup(); restore(''); });
				read.querySelector('#zib-sendnow').addEventListener('click', fire);
			}
			function sendError(reason, err) {
				// no answer in time, or the mail server failed on the very last step - it may have
				// gone. Never "try again" here: that is how a customer gets the email twice.
				if (reason === 'unsure' || (err && err.kind === 'timeout')) { return 'It may have gone. Check Sent before sending again.'; }
				if (reason === 'too-slow') { return 'That took too long to prepare, so nothing was sent. Send fewer documents, or send big ones as links.'; }
				if (reason === 'reconnect' || reason === 'auth') { return 'Sending isn’t enabled yet — open ⚙ and reconnect to grant send permission.'; }
				if (reason === 'no-recipients') { return 'No valid recipients.'; }
				if (reason === 'empty') { return 'Nothing to send.'; }
				if (reason === 'busy') { return 'Mail server is busy — try again in a moment.'; }
				if (reason === 'vault-missing') { return 'The Knowledge Vault isn’t available right now, so the documents couldn’t go.'; }
				if (reason === 'doc-not-found') { return 'A Vault document couldn’t be read. Remove it, add it again and send.'; }
				if (reason === 'doc-no-share') { return 'You can’t share one of those documents outside the business.'; }
				// A write refused for this mailbox (403 zib_write_denied, "Action failed.") is not a sign-in problem.
				if (err && err.kind === 'auth' && err.status === 403) { return 'Couldn’t send from this mailbox. Please try again, or ask an admin.'; }
				return 'Couldn’t send.' + (why(err) || ' Please try again.');
			}

			/* the chat's "Open in Email" hands over a new email to finish here (Bridge.loadApp('inbox',
			   {zibHandoff}) → zdz_app_launch). The server gives it once, to this person only; nothing is sent until Send. */
			card.__zibHandoff = function (token) {
				if (!connCanSend) { ZDZ.ui.toast('Sending isn’t enabled yet — open ⚙ and reconnect to grant send permission.'); return; }
				api('/compose/handoff/' + token, 'POST').then(function (r) {
					openCompose({ mode: 'new', prefill: { to: r.to || '', cc: r.cc || '', subj: r.subject || '', body: r.body || '', docs: r.docs || [] },
						error: r.docs_missing ? 'A document from the chat couldn’t be added here. Add it again with Vault document.' : '' });
				}).catch(function (err) { ZDZ.ui.toastError(err); });
			};

			renderShell();
			loadFolders();
			if (card.__zibPendingHandoff) { var pendTok = card.__zibPendingHandoff; card.__zibPendingHandoff = ''; card.__zibHandoff(pendTok); }
		}

		function load() {
			ZDZ.ui.loading(card, 'Loading your email connection…');
			api('/connection', 'GET').then(render).catch(function (err) {
				// say why (the kit's sentence) and offer Try again right here.
				card.innerHTML = '<p class="zib-sub zib-err" role="alert">' + esc('Couldn’t load your email connection.' + (why(err) || ' Reload the page to try again.')) + '</p>' +
					'<button type="button" class="zib-btn zib-retry" id="zib-conn-retry">Try again</button>';
				var rb = card.querySelector('#zib-conn-retry'); if (rb) { rb.addEventListener('click', load); }
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

	// "Open in Email" from the chat. The shell's launch event names this app and carries the token;
	// a card that is not ready yet keeps it until its mail view is up.
	document.addEventListener('zdz_app_launch', function (ev) {
		var d = ev && ev.detail;
		if (!d || d.appId !== 'inbox' || !d.options) { return; }
		var tok = String(d.options.zibHandoff || '');
		if (!/^[a-f0-9]{32}$/.test(tok)) { return; }
		var c = document.querySelector('#zib-card, .zib-card');
		if (!c) { return; }
		if (typeof c.__zibHandoff === 'function') { c.__zibHandoff(tok); } else { c.__zibPendingHandoff = tok; }
	});

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
