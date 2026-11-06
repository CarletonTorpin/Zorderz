/**
 * Zorderz Quick-ID — deliberate-swipe digital business card.
 *
 * Open:  a LONG, deliberate left-edge pull-across (not a short edge tap or
 *        twitch) slides the card in. The card only COMMITS to open after the
 *        pull crosses most of the screen; a short, wrong-direction, or
 *        OS-interrupted pull snaps straight back closed and NEVER locks the
 *        page. Desktop: triple-click a blank spot in the far-left region.
 * Close: swipe the card left, tap the backdrop, the X button, Escape, or the
 *        gesture/hardware Back action (history integration).
 *
 * Anti-wedge guarantees (a half-open card must never lock up the app on a
 * tablet, where a system edge gesture can interrupt the pull):
 *   1. Only the CARD slides; the backdrop + the X button never move, so a
 *      close control is reachable whenever anything is on screen.
 *   2. Page scroll is locked ONLY while the card is fully, committedly open —
 *      never during a drag — so an abandoned pull can never leave a locked page.
 *   3. A watchdog force-settles a gesture the OS silently cancels (no touchend),
 *      and hiding/backgrounding the tab hard-resets any in-flight drag.
 *   There is no code path that leaves the card half-open or the page locked.
 *
 * No network calls. All card data is server-rendered.
 */
(function () {
	'use strict';

	if (window.__zqidInit) { return; }
	window.__zqidInit = true;

	var overlay, card, closeBtn;
	var open = false;          // committed-open (page locked, modal, history state)
	var wakeLock = null;
	var pushedState = false;
	var drag = null;           // {mode:'open'|'close', x0, y0, t0, lastX, lastT, engaged, cancelled}
	var dragTimer = null;

	/* ---------------- sizing ---------------- */

	function fit() {
		if (!overlay || !card) { return; }
		var AR = 4 / 7;
		var pad = 28;
		var w = overlay.clientWidth - pad;
		var h = overlay.clientHeight - pad;
		if (w <= 0 || h <= 0) { return; }
		var ch = Math.min(h, w / AR);
		card.style.height = ch + 'px';
		card.style.width = (ch * AR) + 'px';
		card.style.fontSize = (ch / 17.5) + 'px';
	}

	/* ---------------- wake lock ---------------- */

	function acquireWakeLock() {
		if (!('wakeLock' in navigator)) { return; }
		try {
			navigator.wakeLock.request('screen').then(function (lock) {
				wakeLock = lock;
			}).catch(function () { /* not critical */ });
		} catch (e) { /* not critical */ }
	}

	function releaseWakeLock() {
		if (wakeLock) {
			try { wakeLock.release(); } catch (e) { /* ignore */ }
			wakeLock = null;
		}
	}

	/* ---------------- transform helpers ---------------- */
	/* The CARD slides; the OVERLAY (backdrop + pinned X) never transforms. */

	function cardTo(px, animate) {
		card.classList.toggle('zqid-anim', !!animate);
		card.style.transform = 'translateX(' + px + 'px)';
	}

	function setProgress(p) {
		overlay.style.setProperty('--zqid-p', String(Math.max(0, Math.min(1, p))));
	}

	function offsetClosed() {
		return -((overlay && overlay.clientWidth) || window.innerWidth) * 1.05;
	}

	/* ---------------- open / close ---------------- */

	function show() {
		// Commit to fully-open: lock scroll, go modal, push a history entry.
		if (open) { return; }
		open = true;
		overlay.classList.add('zqid-live');
		overlay.classList.add('zqid-open');
		overlay.setAttribute('aria-hidden', 'false');
		document.documentElement.classList.add('zqid-lock');
		fit();
		acquireWakeLock();
		if (!pushedState) {
			try { history.pushState({ zqid: 1 }, ''); pushedState = true; }
			catch (e) { pushedState = false; }
		}
	}

	function settleOpen() {
		show();
		setProgress(1);
		cardTo(0, true);
	}

	function settleClosed(viaHistory) {
		var wasOpen = open;
		open = false;
		cardTo(offsetClosed(), true);
		setProgress(0);
		overlay.classList.remove('zqid-open');
		overlay.setAttribute('aria-hidden', 'true');
		document.documentElement.classList.remove('zqid-lock');
		releaseWakeLock();
		afterCardAnim(function () {
			if (!open && !drag) { overlay.classList.remove('zqid-live'); }
		});
		if (wasOpen && pushedState && !viaHistory) {
			pushedState = false;
			try { history.back(); } catch (e) { /* ignore */ }
		} else if (viaHistory) {
			pushedState = false;
		}
	}

	/* Unconditional failsafe — can never leave a wedged / half-open / locked state. */
	function hardReset() {
		drag = null;
		clearDragTimer();
		open = false;
		if (overlay && card) {
			overlay.classList.remove('zqid-open');
			overlay.classList.remove('zqid-live');
			overlay.setAttribute('aria-hidden', 'true');
			card.classList.remove('zqid-anim');
			card.style.transform = 'translateX(' + offsetClosed() + 'px)';
			setProgress(0);
		}
		document.documentElement.classList.remove('zqid-lock');
		releaseWakeLock();
		pushedState = false;
	}

	function afterCardAnim(fn) {
		var done = false;
		function go() { if (done) { return; } done = true; card.removeEventListener('transitionend', go); fn(); }
		card.addEventListener('transitionend', go);
		setTimeout(go, 360);
	}

	window.addEventListener('popstate', function () {
		if (open) { settleClosed(true); }
	});

	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden') {
			// Never carry an in-flight gesture across a background/hide.
			if (drag) { hardReset(); } else { releaseWakeLock(); }
		} else if (open) {
			acquireWakeLock();
		}
	});
	window.addEventListener('pagehide', function () { if (drag) { hardReset(); } });

	/* ---------------- gesture engine (hardened) ---------------- */

	var EDGE = 24;                       // touch must START within 24px of the left edge
	var PREVIEW_DX = 42;                 // deliberate horizontal pull before the card even appears
	function commitDx() { return Math.max(220, window.innerWidth * 0.55); } // long pull to LOCK open
	function closeDx() { return Math.max(70, (overlay.clientWidth || window.innerWidth) * 0.28); }

	function clearDragTimer() { if (dragTimer) { clearTimeout(dragTimer); dragTimer = null; } }
	function armDragTimer() {
		clearDragTimer();
		// If the OS swallows the gesture (no touchend/cancel), force-settle.
		dragTimer = setTimeout(function () { if (drag) { endDrag(); } }, 900);
	}

	function velocity(d) {
		var dt = d.lastT - d.t0;
		return dt > 0 ? (d.lastX - d.x0) / dt : 0;
	}

	function beginDrag(mode, x, y) {
		drag = { mode: mode, x0: x, y0: y, t0: Date.now(), lastX: x, lastT: Date.now(), engaged: false, cancelled: false };
		armDragTimer();
	}

	function moveDrag(x, y) {
		if (!drag || drag.cancelled) { return false; }
		armDragTimer();
		var dx = x - drag.x0;
		var dy = y - drag.y0;
		drag.lastX = x;
		drag.lastT = Date.now();

		if (!drag.engaged) {
			// Vertical intent → hand back to the page for scrolling.
			if (Math.abs(dy) > 12 && Math.abs(dy) > Math.abs(dx) * 1.1) {
				drag.cancelled = true;
				return false;
			}
			var far = drag.mode === 'open' ? dx > PREVIEW_DX : dx < -16;
			var horiz = Math.abs(dx) > Math.abs(dy) * 1.3;
			if (!far || !horiz) { return false; }
			drag.engaged = true;
			if (drag.mode === 'open') { overlay.classList.add('zqid-live'); } // visible PREVIEW — not locked, not committed
		}

		var w = overlay.clientWidth || window.innerWidth;
		var pos = drag.mode === 'open' ? (-w + Math.max(0, dx)) : Math.min(0, dx);
		pos = Math.min(0, Math.max(-w * 1.05, pos));
		cardTo(pos, false);
		setProgress(1 - (Math.abs(pos) / w));
		return true;
	}

	function endDrag() {
		clearDragTimer();
		if (!drag) { return; }
		var d = drag;
		drag = null;
		if (!d.engaged || d.cancelled) {
			// Never committed anything; guarantee a clean closed state if we ever went live.
			if (!open) { settleClosed(); }
			return;
		}
		var w = overlay.clientWidth || window.innerWidth;
		var dx = d.lastX - d.x0;
		var v = velocity(d);
		if (d.mode === 'open') {
			if (dx >= commitDx() || (v > 0.6 && dx > w * 0.32)) { settleOpen(); }
			else { settleClosed(); }
		} else {
			if (dx <= -closeDx() || v < -0.6) { settleClosed(); }
			else { settleOpen(); }
		}
	}

	/* touch path — non-passive move only while a candidate gesture is alive */
	function onTouchMove(ev) {
		if (!drag) { return; }
		var t = ev.touches[0];
		if (!t) { return; }
		if (moveDrag(t.clientX, t.clientY)) {
			ev.preventDefault(); // we own this gesture — stop the scroll
		} else if (drag && drag.cancelled) {
			teardownTouch();
			drag = null;
			clearDragTimer();
		}
	}

	function onTouchEnd() { teardownTouch(); endDrag(); }

	function teardownTouch() {
		document.removeEventListener('touchmove', onTouchMove, { passive: false });
		document.removeEventListener('touchend', onTouchEnd);
		document.removeEventListener('touchcancel', onTouchEnd);
	}

	document.addEventListener('touchstart', function (ev) {
		if (ev.touches.length !== 1) { return; }
		var t = ev.touches[0];
		if (!t || isInteractive(ev.target)) { return; }
		if (!open && t.clientX <= EDGE) {
			beginDrag('open', t.clientX, t.clientY);
		} else if (open && overlay.contains(ev.target) && ev.target !== closeBtn) {
			beginDrag('close', t.clientX, t.clientY);
		} else {
			return;
		}
		document.addEventListener('touchmove', onTouchMove, { passive: false });
		document.addEventListener('touchend', onTouchEnd);
		document.addEventListener('touchcancel', onTouchEnd);
	}, { passive: true });

	/* mouse path (desktop testing) */
	document.addEventListener('mousedown', function (ev) {
		if (ev.button !== 0 || isInteractive(ev.target)) { return; }
		if (!open && ev.clientX <= EDGE) {
			beginDrag('open', ev.clientX, ev.clientY);
		} else if (open && overlay.contains(ev.target) && ev.target !== closeBtn) {
			beginDrag('close', ev.clientX, ev.clientY);
		} else {
			return;
		}
		var mm = function (e) { moveDrag(e.clientX, e.clientY); };
		var mu = function () {
			document.removeEventListener('mousemove', mm);
			document.removeEventListener('mouseup', mu);
			endDrag();
		};
		document.addEventListener('mousemove', mm);
		document.addEventListener('mouseup', mu);
	});

	/* triple-click open (desktop-friendly): three quick clicks on the same
	   non-interactive spot in the far-left region */
	var clicks = [];
	document.addEventListener('click', function (ev) {
		if (open) { return; }
		if (isInteractive(ev.target) ||
			ev.clientX > Math.max(280, window.innerWidth * 0.25)) {
			clicks = [];
			return;
		}
		var now = Date.now();
		clicks = clicks.filter(function (c) { return now - c.t < 1400; });
		if (clicks.length &&
			(Math.abs(ev.clientX - clicks[0].x) > 32 ||
			 Math.abs(ev.clientY - clicks[0].y) > 32)) {
			clicks = [];
		}
		clicks.push({ t: now, x: ev.clientX, y: ev.clientY });
		if (clicks.length >= 3) {
			clicks = [];
			settleOpen();
		}
	});

	function isInteractive(el) {
		while (el && el !== document.body) {
			var tag = (el.tagName || '').toLowerCase();
			if (tag === 'input' || tag === 'textarea' || tag === 'select' ||
				(tag === 'button' && el !== closeBtn) || el.isContentEditable) {
				return true;
			}
			el = el.parentNode;
		}
		return false;
	}

	/* ---------------- boot ---------------- */

	function boot() {
		overlay = document.getElementById('zqidOverlay');
		card = document.getElementById('zqidCard');
		closeBtn = document.getElementById('zqidClose');
		if (!overlay || !card || !closeBtn) { return; }

		hardReset(); // start from a guaranteed closed + unlocked state

		closeBtn.addEventListener('click', function () { if (open) { settleClosed(); } });

		// Tap the backdrop (outside the card) to close.
		overlay.addEventListener('click', function (ev) {
			if (open && ev.target === overlay) { settleClosed(); }
		});

		document.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape' && open) { settleClosed(); }
		});

		// A window blur (e.g. tapping a tel: link) settles an in-flight drag but
		// leaves a committed-open card open for when the user returns.
		window.addEventListener('blur', function () {
			if (drag) {
				var committed = open;
				drag = null;
				clearDragTimer();
				if (committed) { settleOpen(); } else { settleClosed(); }
			}
		});

		window.addEventListener('resize', function () {
			fit();
			if (!open && !drag) { card.style.transform = 'translateX(' + offsetClosed() + 'px)'; }
		});
		window.addEventListener('orientationchange', fit);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
