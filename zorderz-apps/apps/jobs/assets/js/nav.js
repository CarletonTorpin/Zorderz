/**
 * Zorderz Jobs - primary-nav integration.
 *
 * Injects a "Jobs" item into the theme's main nav (.bnav) right after "Apps",
 * mirroring how other apps inject nav items. Tapping it opens the
 * Jobs app via the theme's global openApp('jobs'). Also hides the legacy
 * bug-report trigger from the bar (moved out of the bottom nav per the Jobs
 * rebuild). Self-contained in the plugin so the theme needs no edit.
 *
 * Idempotent; re-tries because .bnav / other plugins' nav items can render late.
 *
 * @package Zorderz\Jobs
 * @since 1.2.0
 */
(function () {
	'use strict';

	var cfg = window.zjobNav || {};
	// Kiosk / no-access users never get the Jobs nav (INV-10; server still gates).
	if (cfg.canSeeJobs === false) { return; }

	function drawIcons() {
		if (window.lucide && typeof window.lucide.createIcons === 'function') {
			try { window.lucide.createIcons(); } catch (e) { /* non-fatal */ }
		}
	}

	function inject() {
		var bnav = document.querySelector('.bnav');
		if (!bnav) { return false; }
		if (bnav.querySelector('.ni-jobs')) { return true; } // already injected

		var apps = bnav.querySelector('.ni[data-view="sv-dash"]');

		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'ni ni-jobs';
		btn.setAttribute('aria-label', 'Jobs');
		btn.innerHTML = '<i data-lucide="clipboard-list"></i><span class="ni-label">Jobs</span>';
		btn.addEventListener('click', function (e) {
			e.preventDefault();
			// Don't let any generic .ni -> switchView handler also fire.
			e.stopImmediatePropagation();
			if (typeof window.openApp === 'function') {
				window.openApp('jobs');
			}
		});

		// Place right after the Apps item in the DOM (CSS order also enforces this).
		if (apps && apps.parentNode) {
			apps.parentNode.insertBefore(btn, apps.nextSibling);
		} else {
			bnav.appendChild(btn);
		}
		drawIcons();
		return true;
	}

	// Fire now + on the usual lifecycle events, plus a couple of delayed retries
	// (the bar and other plugins' nav items can render after us).
	if (!inject()) {
		document.addEventListener('DOMContentLoaded', inject);
	}
	document.addEventListener('zdz_widgets_rendered', inject);
	setTimeout(inject, 500);
	setTimeout(inject, 1500);
})();
