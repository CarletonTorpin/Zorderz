/*
 * schedule-button.js — the platform Schedule-Job launch contract (client side).
 *
 * A launch CARRIES CONTEXT, NEVER AUTHORITY. Any surface adds ONE HTML attribute:
 *
 *     <button data-zdz-schedule data-ns="project" data-ref="42">Schedule</button>
 *
 * No per-button fetch/handler/enqueue is needed — this ONE document-level
 * delegated listener POSTs { ns, ref_id } to the scheduler intake, receives an
 * opaque single-user token + a sanitized prefill + free-slot suggestions, and
 * hands them to the editor. The ORIGIN never travels back to the browser: only the
 * token does. On save, the editor sends the token back (as `intake_token`) and the
 * server reads the origin from it — never from the request body.
 *
 * The widget wires the editor by exposing window.zschOpenEditor(bundle); if it is
 * not present yet, a `zsch:intake` DOM event is dispatched for it to consume. This
 * file NEVER trusts or renders the origin.
 */
(function () {
	'use strict';

	var CFG = window.zschSchedule || {};

	function postIntake(ns, ref) {
		if (!CFG.base) {
			return;
		}
		var headers = { 'Content-Type': 'application/json' };
		if (CFG.nonce) {
			headers['X-WP-Nonce'] = CFG.nonce;
		}
		fetch(CFG.base + '/intake', {
			method: 'POST',
			headers: headers,
			credentials: 'same-origin',
			body: JSON.stringify({ ns: ns, ref_id: ref })
		}).then(function (r) {
			return r.json();
		}).then(function (data) {
			if (!data || !data.ok) {
				document.dispatchEvent(new CustomEvent('zsch:intake-error', { detail: data || {} }));
				return;
			}
			if (data.refused) {
				// Already booked — the UI jumps to the existing appointment.
				document.dispatchEvent(new CustomEvent('zsch:intake-booked', { detail: data }));
				return;
			}
			// Hand the bundle to the editor (token stays with the editor, sent back
			// on save as intake_token so the server re-derives the origin).
			if (typeof window.zschOpenEditor === 'function') {
				window.zschOpenEditor(data);
			} else {
				document.dispatchEvent(new CustomEvent('zsch:intake', { detail: data }));
			}
		}).catch(function () {
			/* network hiccup — the button can be tapped again. */
		});
	}

	document.addEventListener('click', function (e) {
		var el = e.target && e.target.closest ? e.target.closest('[data-zdz-schedule]') : null;
		if (!el) {
			return;
		}
		var ns = el.getAttribute('data-ns') || '';
		var ref = el.getAttribute('data-ref') || '';
		if (!ns || !ref) {
			return;
		}
		e.preventDefault();
		postIntake(ns, ref);
	}, false);
})();
