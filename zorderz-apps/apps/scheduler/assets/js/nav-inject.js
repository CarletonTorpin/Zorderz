/**
 * TS Scheduler — bottom-nav tab injector.
 *
 * Adds a "Schedule" tab to the theme's bottom navigation (.bnav) on the SPA
 * shell, mirroring ts-internal-messaging's nav-inject. No-ops gracefully if the
 * shell isn't present (so it's safe to enqueue on any front-end page).
 */
(function () {
	'use strict';
	var data = window.zschNavData || {};
	if (!data.pageUrl) { return; }

	function inject() {
		var bnav = document.querySelector('.bnav');
		var main = document.getElementById('view-main');
		if (!bnav || !main) { return false; }
		if (document.getElementById('zsch-nav-tab')) { return true; }

		var a = document.createElement('a');
		a.id = 'zsch-nav-tab';
		a.className = 'bnav-item zsch-nav-item';
		a.href = data.pageUrl;
		a.setAttribute('aria-label', 'Schedule');
		a.innerHTML =
			'<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
			'<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/>' +
			'<line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>' +
			'<span class="bnav-label">Schedule</span>';
		bnav.appendChild(a);
		return true;
	}

	if (!inject()) {
		// SPA may render late — retry briefly, then give up.
		var tries = 0;
		var iv = setInterval(function () {
			if (inject() || ++tries > 20) { clearInterval(iv); }
		}, 400);
	}
})();
