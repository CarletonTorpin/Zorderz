/**
 * Zorderz Quick-ID — swipe-from-left digital business card.
 *
 * Open:  touch/pointer drag starting in the left edge zone of the homepage
 *        slides the card overlay in, tracking the finger. On desktop,
 *        triple-click an empty spot in the left sidebar region (or drag
 *        from the left edge with the mouse).
 * Close: swipe left anywhere on the overlay, the X button, Escape,
 *        or the hardware/gesture Back action (history integration).
 * Extras: screen wake-lock while the card is shown; body scroll lock.
 *
 * No network calls. All card data is server-rendered.
 */
(function () {
	'use strict';

	if (window.__zqidInit) { return; }
	window.__zqidInit = true;

	var overlay, card, closeBtn;
	var open = false;
	var wakeLock = null;
	var pushedState = false;
	var AR = 4 / 7;

	/* ---------------- sizing ---------------- */

	function fit() {
		if (!overlay || !card) { return; }
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

	document.addEventListener('visibilitychange', function () {
		if (open && document.visibilityState === 'visible') {
			acquireWakeLock();
		}
	});

	/* ---------------- open / close ---------------- */

	function setTransform(px, animate) {
		overlay.classList.toggle('zqid-anim', !!animate);
		overlay.style.transform = 'translateX(' + px + 'px)';
	}

	function show() {
		if (open) { return; }
		open = true;
		overlay.classList.remove('zqid-closed');
		overlay.setAttribute('aria-hidden', 'false');
		document.documentElement.classList.add('zqid-lock');
		fit();
		acquireWakeLock();
		if (!pushedState) {
			try {
				history.pushState({ zqid: 1 }, '');
				pushedState = true;
			} catch (e) { pushedState = false; }
		}
	}

	function settleOpen() {
		show();
		setTransform(0, true);
	}

	function settleClosed(viaHistory) {
		var wasOpen = open;
		open = false;
		setTransform(-overlay.clientWidth * 1.02, true);
		overlay.setAttribute('aria-hidden', 'true');
		document.documentElement.classList.remove('zqid-lock');
		releaseWakeLock();
		if (wasOpen && pushedState && !viaHistory) {
			pushedState = false;
			try { history.back(); } catch (e) { /* ignore */ }
		} else if (viaHistory) {
			pushedState = false;
		}
	}

	window.addEventListener('popstate', function () {
		if (open) { settleClosed(true); }
	});

	/* ---------------- gesture engine ---------------- */

	var EDGE = function () { return Math.max(20, Math.min(40, window.innerWidth * 0.07)); };
	var drag = null; // {mode:'open'|'close', x0, y0, t0, lastX, lastT, engaged, cancelled}

	function velocity(d) {
		var dt = d.lastT - d.t0;
		return dt > 0 ? (d.lastX - d.x0) / dt : 0;
	}

	function beginDrag(mode, x, y) {
		drag = {
			mode: mode, x0: x, y0: y, t0: Date.now(),
			lastX: x, lastT: Date.now(), engaged: false, cancelled: false
		};
	}

	function moveDrag(x, y) {
		if (!drag || drag.cancelled) { return false; }
		var dx = x - drag.x0;
		var dy = y - drag.y0;
		drag.lastX = x;
		drag.lastT = Date.now();

		if (!drag.engaged) {
			if (Math.abs(dy) > 14 && Math.abs(dy) > Math.abs(dx) * 1.2) {
				// vertical intent — hand back to the page
				drag.cancelled = true;
				return false;
			}
			var horiz = drag.mode === 'open' ? dx > 10 : dx < -10;
			if (!horiz) { return false; }
			drag.engaged = true;
			if (drag.mode === 'open') { show(); }
		}

		var w = overlay.clientWidth;
		var pos = drag.mode === 'open' ? (-w + Math.max(0, dx)) : Math.min(0, dx);
		setTransform(Math.min(0, Math.max(-w * 1.02, pos)), false);
		return true;
	}

	function endDrag() {
		if (!drag) { return; }
		var d = drag;
		drag = null;
		if (!d.engaged || d.cancelled) { return; }
		var w = overlay.clientWidth;
		var dx = d.lastX - d.x0;
		var v = velocity(d);
		if (d.mode === 'open') {
			if (dx > w * 0.28 || v > 0.45) { settleOpen(); } else { settleClosed(); }
		} else {
			if (dx < -w * 0.22 || v < -0.45) { settleClosed(); } else { settleOpen(); }
		}
	}

	/* touch path (phones/tablets) — non-passive move only while a candidate
	   gesture is alive, so normal page scrolling stays untouched */
	function onTouchMove(ev) {
		if (!drag) { return; }
		var t = ev.touches[0];
		if (!t) { return; }
		if (moveDrag(t.clientX, t.clientY)) {
			ev.preventDefault(); // we own this gesture — stop the scroll
		} else if (drag && drag.cancelled) {
			teardownTouch();
		}
	}

	function onTouchEnd() {
		teardownTouch();
		endDrag();
	}

	function teardownTouch() {
		document.removeEventListener('touchmove', onTouchMove, { passive: false });
		document.removeEventListener('touchend', onTouchEnd);
		document.removeEventListener('touchcancel', onTouchEnd);
	}

	document.addEventListener('touchstart', function (ev) {
		var t = ev.touches[0];
		if (!t || ev.touches.length !== 1) { return; }
		if (isInteractive(ev.target)) { return; }
		if (!open && t.clientX <= EDGE()) {
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
		if (!open && ev.clientX <= EDGE()) {
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
	   non-interactive spot ("grey area") in the left sidebar region */
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
				tag === 'button' && el !== closeBtn || el.isContentEditable) {
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

		setTransform(-window.innerWidth * 1.02, false);

		closeBtn.addEventListener('click', function () {
			if (open) { settleClosed(); }
		});
		document.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape' && open) { settleClosed(); }
		});
		window.addEventListener('resize', function () {
			fit();
			if (!open) { setTransform(-window.innerWidth * 1.02, false); }
		});
		window.addEventListener('orientationchange', fit);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
