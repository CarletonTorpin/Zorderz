/**
 * TSIM v1.0.17 — Team bottom-nav tab injector
 *
 * Runs on Zorderz theme front page (where #view-main and .bnav exist).
 * Mirrors the pattern TSA uses to inject its Chat tab — creates a sub-view
 * that hosts the messaging iframe and adds a "Team" button to the bottom nav.
 *
 * This script does NOT run inside the messaging widget itself (full-page
 * route at /?zim_page=1 has no .bnav). The enqueue guard keeps it to the
 * theme's main template.
 */

(function () {
	'use strict';

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

	function boot() {
		var viewMain = document.getElementById('view-main');
		var bnav = viewMain ? viewMain.querySelector('.bnav') : null;
		if (!viewMain || !bnav) return; // Not on the Zorderz front page

		// Don't inject twice if something reloads this script.
		if (document.getElementById('sv-team')) return;

		// ── 1. Create the Team sub-view ──
		var svTeam = document.createElement('div');
		svTeam.id = 'sv-team';
		svTeam.className = 'sub-view';
		svTeam.setAttribute('role', 'main');

		// Skeleton loading state — shown until the iframe fires its load event.
		// Without this, tapping Team for the first time shows a blank white/black
		// rectangle while PHP renders the page. The skeleton gives immediate
		// visual feedback that something is happening, matching the eventual
		// layout (sidebar list + conversation area).
		svTeam.innerHTML =
			'<div class="zim-team-skeleton" id="zim-team-skeleton" aria-hidden="true">' +
				'<div class="zim-team-skeleton__sidebar">' +
					'<div class="zim-team-skeleton__header"></div>' +
					'<div class="zim-team-skeleton__section-hdr"></div>' +
					'<div class="zim-team-skeleton__row"></div>' +
					'<div class="zim-team-skeleton__row"></div>' +
					'<div class="zim-team-skeleton__row"></div>' +
					'<div class="zim-team-skeleton__section-hdr"></div>' +
					'<div class="zim-team-skeleton__row"></div>' +
				'</div>' +
				'<div class="zim-team-skeleton__main">' +
					'<div class="zim-team-skeleton__spinner"></div>' +
					'<div class="zim-team-skeleton__label">Loading Team messaging…</div>' +
				'</div>' +
			'</div>' +
			'<iframe ' +
				'id="zim-team-tab-frame" ' +
				'src="about:blank" ' +
				'data-src="/?zim_page=1&zdz_embed=theme" ' +
				'loading="lazy" ' +
				'style="width:100%;height:100%;border:0;display:block;background:transparent;opacity:0;transition:opacity 0.18s ease;" ' +
				'title="Team messaging"></iframe>';

		// Insert before .bnav so the nav stays on top.
		viewMain.insertBefore(svTeam, bnav);

		// ── 2. Inject the Team nav button ──
		// Target position: after Chat (if present) or after Apps.
		var teamNi = document.createElement('button');
		teamNi.className = 'ni';
		teamNi.setAttribute('data-view', 'sv-team');
		teamNi.setAttribute('aria-label', 'Team');
		teamNi.id = 'ni-team';
		// Use messages-square icon to differentiate from the analytics app's single-bubble Chat.
		teamNi.innerHTML =
			'<i data-lucide="messages-square"></i>' +
			'<span class="ni-label">Team</span>' +
			'<span class="ni-badge" id="ni-team-badge" hidden></span>';

		// Find the Chat button if TSA injected it, otherwise use Apps.
		var chatBtn = bnav.querySelector('[data-view="sv-chat"]');
		var appsBtn = bnav.querySelector('[data-view="sv-dash"]');
		var anchor = chatBtn || appsBtn;

		if (anchor && anchor.nextSibling) {
			bnav.insertBefore(teamNi, anchor.nextSibling);
		} else {
			bnav.appendChild(teamNi);
		}

		// ── 3. Click handler — delegate to theme's switchView if available ──
		teamNi.addEventListener('click', function () {
			// Lazy-load the iframe src on first activation.
			var frame = document.getElementById('zim-team-tab-frame');
			var skeleton = document.getElementById('zim-team-skeleton');
			if (frame && frame.src === 'about:blank' && frame.dataset.src) {
				// Hide iframe opacity until it's actually loaded so the
				// skeleton shows through during network + paint.
				frame.style.opacity = '0';
				if (skeleton) skeleton.style.display = 'flex';

				// v1.0.16 — Single shared "reveal" path so both the load
				// event and the timeout fallback can't double-fire or fight
				// each other. Whichever fires first wins; the other is a no-op.
				var revealed = false;
				var fallbackTimer = null;
				function reveal(reason) {
					if (revealed) return;
					revealed = true;
					if (fallbackTimer) {
						clearTimeout(fallbackTimer);
						fallbackTimer = null;
					}
					// Small delay so the iframe's own JS has a tick to render
					// its sidebar before we swap visibility. Otherwise you see
					// a flash of the unstyled iframe.
					setTimeout(function () {
						frame.style.opacity = '1';
						if (skeleton) skeleton.style.display = 'none';
					}, 80);
					if (reason === 'timeout' && window.console) {
						console.warn('[TSIM] iframe load timeout; revealing anyway');
					}
				}

				// Wire the load handler before setting src.
				frame.addEventListener('load', function onLoad() {
					frame.removeEventListener('load', onLoad);
					reveal('load');
				});

				// v1.0.16 — Hard timeout fallback. If the iframe never fires
				// `load` (network stall, blocked request, redirect chain that
				// never settles), reveal anyway after 8s so the user isn't
				// stuck staring at the skeleton forever. The iframe will keep
				// loading in the background; if it eventually succeeds, great,
				// and if not the user sees whatever the browser rendered.
				fallbackTimer = setTimeout(function () { reveal('timeout'); }, 8000);

				frame.src = frame.dataset.src;
			}
			if (typeof window.switchView === 'function') {
				window.switchView('sv-team');
			}
		});

		// ── 4. Refresh Lucide icons so the new button renders its SVG ──
		if (typeof window.refreshIcons === 'function') {
			window.refreshIcons();
		} else if (window.lucide && typeof window.lucide.createIcons === 'function') {
			window.lucide.createIcons();
		}

		// ── 5. Unread badge polling — reuses existing admin-ajax endpoint ──
		startBadgePoll();
	}

	// Minimal unread count polling — only when nav is visible (not when another
	// sub-view is active, to avoid wasted work). Every 45s.
	//
	// v1.0.16 — wired up.
	// v1.0.17 — gated on Page Visibility. While the tab is hidden (background
	// or minimized) the interval is fully cleared so the browser's wake budget
	// isn't burnt on a badge nobody can see. On tab refocus we fire one
	// immediate fetch so the count is fresh by the time the user looks.
	// We don't need an on-screen check here the way the inline widget does
	// (the bottom-nav `#ni-team-badge` is always rendered while the SPA is
	// visible — it's not gated to a sub-view).
	var badgePollTimer = null;
	function startBadgePoll() {
		function start() {
			if (badgePollTimer) return;
			if (!document.hidden) fetchBadge();
			// v1.1.1 — 45s → 60s. The bottom-nav badge is a background convenience;
			// a slower cadence trims uncacheable admin-ajax load (part of the 502 /
			// low-cache-hit-ratio mitigation).
			badgePollTimer = setInterval(fetchBadge, 60000);
		}
		function stop() {
			if (badgePollTimer) { clearInterval(badgePollTimer); badgePollTimer = null; }
		}
		document.addEventListener('visibilitychange', function () {
			if (document.hidden) stop(); else start();
		});
		start();
	}
	function fetchBadge() {
		if (!window.zimNavData || !window.zimNavData.nonce) return;
		var url = window.zimNavData.ajaxUrl + '?action=zim_sidebar&nonce=' + encodeURIComponent(window.zimNavData.nonce);
		fetch(url, { credentials: 'same-origin' })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (j) {
				if (!j || !j.success || !j.data) return;
				var total = 0;
				(j.data.channels || []).forEach(function (c) { total += (c.unread | 0); });
				(j.data.dms || []).forEach(function (d) { total += (d.unread | 0); });
				setBadge(total);
			})
			.catch(function () { /* silent */ });
	}
	function setBadge(count) {
		var b = document.getElementById('ni-team-badge');
		if (!b) return;
		if (count > 0) {
			b.textContent = count > 99 ? '99+' : String(count);
			b.hidden = false;
			b.style.display = '';
		} else {
			b.hidden = true;
			b.style.display = 'none';
		}
	}
})();
