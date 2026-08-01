/**
 * TS Scheduler — Connected Calendars card (v1.6.0, Phase 0).
 *
 * Separate small bundle so the core calendar JS is untouched. Loads only when
 * the feature flag + provider config are live and the user can write (the
 * kiosk never gets this file). Talks to zorderz/v1/scheduler/connections* — all
 * owner-scoped server-side; nothing here is trusted for permissions.
 *
 * Rendering rule: every provider-supplied string (account label, calendar
 * name) goes through textContent — never innerHTML.
 */
(function () {
	'use strict';

	var D = window.zschData || {};
	var CC = D.conncal || {};
	if (!CC.enabled || !D.restUrl) { return; }

	function boot() {
		var root = document.getElementById('zsch-widget');
		var btn = document.getElementById('zsch-conncal-btn');
		var modal = document.getElementById('zsch-conncal-modal');
		if (!root || !btn || !modal) { return false; }
		if (btn.getAttribute('data-zsch-cc-wired') === '1') { return true; }
		btn.setAttribute('data-zsch-cc-wired', '1');
		wire(btn, modal);
		handleReturnFlag(modal);
		handleConnectDeepLink(modal);
		return true;
	}

	// The widget HTML can arrive after this script (theme renderWidgets race,
	// same as widget.js) — retry briefly.
	if (!boot()) {
		var polls = 0;
		var iv = setInterval(function () {
			if (boot() || ++polls > 40) { clearInterval(iv); }
		}, 250);
		document.addEventListener('zdz_widgets_rendered', boot);
	}

	// ── tiny helpers (self-contained; widget.js internals aren't exported) ──
	function el(tag, cls, txt) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (txt != null) { e.textContent = txt; }
		return e;
	}

	function api(path, opts) {
		opts = opts || {};
		return fetch(D.restUrl + path, {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': D.nonce },
			body: opts.body ? JSON.stringify(opts.body) : undefined
		})
		.then(function (r) { return r.json().catch(function () { return {}; }); })
		.catch(function () { return {}; });
	}

	function toast(msg, isErr) {
		var t = document.getElementById('zsch-toast');
		if (!t) { return; }
		if (t.parentNode !== document.body) { document.body.appendChild(t); }
		t.textContent = msg;
		t.classList.toggle('is-err', !!isErr);
		t.hidden = false;
		clearTimeout(t._t);
		t._t = setTimeout(function () { t.hidden = true; }, 3800);
	}

	// CROSS-COMPONENT CONTRACT — deep-link from the theme's Settings → App
	// Authorizations "Connect a Calendar" card. The theme deep-links with EXACTLY
	// ?zdz_connect_calendar=open (see the theme's app.js); we strip the param and
	// open the connect modal so that button routes straight into the existing
	// OAuth flow. The query var is theme-owned and must match verbatim.
	function handleConnectDeepLink(modal) {
		if (!/[?&]zdz_connect_calendar=open/.test(window.location.search)) { return; }
		try {
			var url = new URL(window.location.href);
			url.searchParams.delete('zdz_connect_calendar');
			window.history.replaceState({}, '', url.toString());
		} catch (e) { /* old browser: harmless leftover param */ }
		openModal(modal);
	}

	// ── OAuth return flag (?zsch_connected=…) → toast + open card ──
	function handleReturnFlag(modal) {
		var m = /[?&]zsch_connected=([a-z]+)/.exec(window.location.search);
		if (!m) { return; }
		try {
			var url = new URL(window.location.href);
			url.searchParams.delete('zsch_connected');
			window.history.replaceState({}, '', url.toString());
		} catch (e) { /* old browser: harmless leftover param */ }
		var flag = m[1];
		if (flag === 'ok') {
			toast('Calendar connected ✓ — pulling your busy times…');
			openModal(modal);
			// v1.7.0 — kick an immediate sync so busy times show within seconds
			// instead of waiting up to 5 min for the cron. Fire-and-forget;
			// owner-scoped + rate-gated server-side.
			api('/connections/sync', { method: 'POST' });
		} else if (flag === 'cancel') {
			toast('Connection cancelled', true);
		} else if (flag === 'rate') {
			toast('Too many connection attempts — try again in an hour', true);
		} else if (flag === 'login') {
			toast('Please sign in, then connect again', true);
		} else if (flag === 'err') {
			toast("Connection didn't complete — try again", true);
		}
	}

	// ── wiring ─────────────────────────────────────────────────────
	function wire(btn, modal) {
		btn.addEventListener('click', function () { openModal(modal); });

		var close = document.getElementById('zsch-conncal-close');
		var done = document.getElementById('zsch-conncal-done');
		if (close) { close.addEventListener('click', function () { modal.hidden = true; }); }
		if (done) { done.addEventListener('click', function () { modal.hidden = true; }); }

		var g = document.getElementById('zsch-conncal-google');
		var ms = document.getElementById('zsch-conncal-ms');
		if (g && CC.providers && CC.providers.google) {
			g.hidden = false;
			g.addEventListener('click', function () { goStart(CC.startGoogle); });
		}
		if (ms && CC.providers && CC.providers.microsoft) {
			ms.hidden = false;
			ms.addEventListener('click', function () { goStart(CC.startMicrosoft); });
		}
	}

	// Navigate to an OAuth start URL. Defensive decode of '&amp;' → '&': a JS
	// location assignment navigates to the string verbatim, so an HTML-encoded
	// URL (as wp_nonce_url used to emit) would corrupt the query params. v1.6.1
	// serves a clean URL from start_url(); this guard keeps it clean regardless.
	function goStart(u) {
		if (!u) { return; }
		window.location.href = String(u).replace(/&amp;/g, '&');
	}

	function openModal(modal) {
		modal.hidden = false;
		loadAccounts();
	}

	// ── data + render ──────────────────────────────────────────────
	function loadAccounts() {
		var list = document.getElementById('zsch-conncal-list');
		if (!list) { return; }
		list.textContent = '';
		list.appendChild(el('p', 'zsch-conncal-empty', 'Loading…'));

		api('/connections').then(function (res) {
			list.textContent = '';
			var accounts = (res && res.accounts) || [];
			if (!accounts.length) {
				list.appendChild(el('p', 'zsch-conncal-empty',
					'No calendars connected yet. Use a Connect button below — you’ll sign in with that account and pick which calendars count as busy time.'));
				return;
			}
			accounts.forEach(function (a) { list.appendChild(renderAccount(a)); });
		});
	}

	function renderAccount(a) {
		var row = el('div', 'zsch-conncal-acct');
		var head = el('div', 'zsch-conncal-acct-head');

		var ico = el('span', 'zsch-conncal-provider zsch-conncal-provider--' + a.provider,
			a.provider === 'google' ? 'G' : 'M');
		var label = el('span', 'zsch-conncal-label', a.email_label || '(account)');
		var chip = el('span', 'zsch-conncal-chip', a.status === 'ok' ? 'Connected ✓' : 'Reconnect needed');
		chip.classList.add(a.status === 'ok' ? 'is-ok' : 'is-warn');

		head.appendChild(ico);
		head.appendChild(label);
		head.appendChild(chip);

		var actions = el('span', 'zsch-conncal-actions');
		if (a.status !== 'ok') {
			var re = el('button', 'zsch-w-btn zsch-w-btn--ghost zsch-conncal-mini', 'Reconnect');
			re.type = 'button';
			re.addEventListener('click', function () {
				goStart((a.provider === 'google') ? CC.startGoogle : CC.startMicrosoft);
			});
			actions.appendChild(re);
		}
		var del = el('button', 'zsch-w-btn zsch-w-btn--ghost zsch-conncal-mini', 'Disconnect');
		del.type = 'button';
		del.addEventListener('click', function () {
			if (del.getAttribute('data-armed') !== '1') {
				del.setAttribute('data-armed', '1');
				del.textContent = 'Really disconnect?';
				del.classList.add('is-danger');
				setTimeout(function () {
					del.setAttribute('data-armed', '0');
					del.textContent = 'Disconnect';
					del.classList.remove('is-danger');
				}, 4000);
				return;
			}
			del.disabled = true;
			api('/connections/' + a.id, { method: 'DELETE' }).then(function (res) {
				if (res && res.success) { toast('Calendar disconnected'); }
				else { toast('Could not disconnect', true); }
				loadAccounts();
			});
		});
		actions.appendChild(del);
		head.appendChild(actions);
		row.appendChild(head);

		// Calendar picker: live provider list merged with enabled feeds.
		var cals = el('div', 'zsch-conncal-cals');
		cals.appendChild(el('p', 'zsch-conncal-empty', 'Loading calendars…'));
		row.appendChild(cals);

		api('/connections/' + a.id + '/calendars').then(function (res) {
			cals.textContent = '';
			if (!res || !res.success) {
				var msg = (res && res.error) || 'Could not load the calendar list.';
				cals.appendChild(el('p', 'zsch-conncal-empty', msg));
				return;
			}
			var enabled = {};
			(a.feeds || []).forEach(function (f) { enabled[f.external_cal_id] = f.id; });
			(res.calendars || []).forEach(function (c) {
				cals.appendChild(renderCalRow(a, c, enabled[c.id] || 0));
			});
			if (!(res.calendars || []).length) {
				cals.appendChild(el('p', 'zsch-conncal-empty', 'No calendars visible on this account.'));
			}
		});

		return row;
	}

	function renderCalRow(a, c, feedId) {
		var lab = el('label', 'zsch-conncal-cal');
		var cb = document.createElement('input');
		cb.type = 'checkbox';
		cb.checked = feedId > 0;
		var dot = el('span', 'zsch-conncal-dot');
		if (c.color) { dot.style.background = c.color; }
		var name = el('span', 'zsch-conncal-calname', c.name + (c.primary ? ' (primary)' : ''));
		lab.appendChild(cb);
		lab.appendChild(dot);
		lab.appendChild(name);

		cb.addEventListener('change', function () {
			cb.disabled = true;
			var body = cb.checked
				? { on: true, external_cal_id: c.id, name: c.name, color: c.color || '' }
				: { on: false, feed_id: feedId };
			api('/connections/' + a.id + '/feeds', { method: 'POST', body: body }).then(function (res) {
				cb.disabled = false;
				if (res && res.success) {
					if (cb.checked) {
						feedId = res.feed_id || 0;
						toast('Added as a conflict calendar');
						// v1.7.0 — pull the newly-enabled calendar right away.
						api('/connections/sync', { method: 'POST' });
					} else {
						feedId = 0;
						toast('Removed');
					}
				} else {
					cb.checked = !cb.checked; // revert
					toast((res && res.error) || 'Could not save', true);
				}
			});
		});
		return lab;
	}
})();
