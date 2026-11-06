/*!
 * zdz-sealed-show.js - "Show code" for gate, lockbox, alarm, door and keypad codes kept in the theme's vault
 * (ZDZ_Sealed). An app that shows codes ships a copy; the first one loaded on a page wins (window.ZDZSealed), so one
 * click handler serves them all.
 *
 *   ZDZSealed.buttons(list)  list: [{id, kind, may_open, added}] from the app's own payload (never a code). Returns the
 *                            HTML of a "Show gate code" button per code this person may open now, or '' for none.
 *   A press posts to the theme's sealed/<id>/open route through the kit (ZDZ.ui.rest: the page's REST nonce, one
 *   timeout, a lost signal or a 401 said in words; a 403 shows the server's own sentence). The server decides (admins;
 *   the person on that work, on its day; never the kiosk) and logs every opening. The code shows for 30 seconds, then
 *   hides again. The kit is looked for at the press, so load order does not matter, and a page without it says so
 *   instead of trying.
 */
(function () {
	'use strict';
	if (window.ZDZSealed) { return; }

	var WORD = { gate: 'gate', lockbox: 'lockbox', alarm: 'alarm', door: 'door', keypad: 'keypad' };
	var SHOW_MS = 30000;

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function kit() {
		return (window.ZDZ && window.ZDZ.ui && typeof window.ZDZ.ui.rest === 'function') ? window.ZDZ.ui : null;
	}

	function buttons(list) {
		if (!Array.isArray(list)) { return ''; }
		var open = list.filter(function (x) { return x && x.may_open && (x.id | 0) > 0; });
		if (!open.length) { return ''; }
		return '<span class="zdz-sealed">' + open.map(function (x) {
			var w = WORD[x.kind] || 'gate';
			var when = x.added ? ' (added ' + esc(x.added) + ')' : '';
			return '<span class="zdz-sealed-item">' +
				'<button type="button" class="zdz-sealed-show" data-zdz-sealed="' + (x.id | 0) + '" aria-label="Show the ' + w + ' code' + when + '">Show ' + w + ' code</button>' +
				'<span class="zdz-sealed-code" role="status" aria-live="polite"></span>' +
				'</span>';
		}).join('') + '</span>';
	}

	function say(out, text, cls) {
		out.textContent = text;
		out.className = 'zdz-sealed-code' + (cls ? ' ' + cls : '');
	}

	function onClick(e) {
		var b = e.target && e.target.closest ? e.target.closest('[data-zdz-sealed]') : null;
		if (!b) { return; }
		e.preventDefault();
		e.stopPropagation(); // a card under the button never opens from this press
		if (b.getAttribute('aria-busy') === 'true') { return; }
		var out = b.nextElementSibling;
		var id = parseInt(b.getAttribute('data-zdz-sealed'), 10) || 0;
		var ui = kit();
		if (!ui) {
			say(out, 'The app has not finished loading. Reload it and try again.', 'is-error zdz-pair');
			return;
		}
		b.setAttribute('aria-busy', 'true');
		b.disabled = true;
		say(out, 'Opening…', '');
		ui.rest('zorderz/v1/sealed/' + id + '/open', { method: 'POST', headers: { 'Accept': 'application/json' } }).then(function (j) {
			if (j && typeof j.code === 'string') {
				say(out, j.code, 'is-open');
				b.hidden = true;
				setTimeout(function () { say(out, '', ''); b.hidden = false; }, SHOW_MS);
			} else {
				say(out, 'This code could not be opened.', 'is-error zdz-pair');
			}
		}, function (err) {
			// the kit's words (no signal, too slow, signed out), or on a 403 the server's own sentence
			say(out, (err && err.message) || 'This code could not be opened.', 'is-error zdz-pair');
		}).then(function () {
			b.removeAttribute('aria-busy');
			b.disabled = false;
		});
	}

	document.addEventListener('click', onClick, true);

	if (!document.getElementById('zdz-sealed-css')) {
		var st = document.createElement('style');
		st.id = 'zdz-sealed-css';
		st.textContent =
			'.zdz-sealed{display:flex;flex-wrap:wrap;gap:8px;margin:6px 0 0}' +
			'.zdz-sealed-item{display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap}' +
			'.zdz-sealed-show{min-height:44px;min-width:44px;padding:0 14px;border-radius:10px;border:1px solid var(--sys-border);' +
				'background:var(--sys-surface);color:var(--sys-text);font:inherit;font-weight:600;cursor:pointer}' +
			'.zdz-sealed-show:focus-visible{outline:2px solid var(--sys-brand);outline-offset:2px}' +
			'.zdz-sealed-show[disabled]{opacity:.7;cursor:progress}' +
			'.zdz-sealed-code{color:var(--sys-text-sec)}' +
			'.zdz-sealed-code.is-open{color:var(--sys-text);font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;' +
				'font-size:1.25em;font-weight:700;letter-spacing:.08em}' +
			'.zdz-sealed-code.is-error{background:var(--sys-tint-warning-bg);color:var(--sys-tint-warning-fg);padding:4px 8px;border-radius:8px}';
		(document.head || document.documentElement).appendChild(st);
	}

	window.ZDZSealed = { buttons: buttons, version: '1.0.0' };
})();
