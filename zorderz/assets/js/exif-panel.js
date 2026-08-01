/**
 * TS Media EXIF — front-end Details panel.
 *
 * A framework-agnostic, collapsed-by-default metadata panel for a photo.
 * Drop one anchor element into any photo viewer (Media app, camera "My Photos",
 * Receipts, etc.):
 *
 *   <div class="zdz-exif" data-media-id="123"></div>
 *
 * then call TSMediaExif.mount(rootEl) — or TSMediaExif.mountAll() to wire every
 * .zdz-exif on the page. The first time the user EXPANDS the panel, we fetch
 * /zorderz/v1/media/{id}/exif (which lazily resolves + caches the place name server
 * side). Nothing is fetched on render, so lists/galleries stay cheap.
 *
 * Tiers handled:
 *   rich        → summary + interpreted facts + "Show all metadata" (verbatim)
 *   normalized  → summary + the facts we have (time/location), no verbatim
 *   none        → "No metadata available for this photo."
 *
 * No external deps. Uses fetch + the WP REST nonce if present
 * (window.wpApiSettings or a data-nonce attribute).
 *
 * @since 2.21.0
 */
(function (global) {
	'use strict';

	var REST_BASE = (global.zdzData && global.zdzData.restBase)
		? String(global.zdzData.restBase).replace(/\/$/, '')
		: ((global.wpApiSettings && global.wpApiSettings.root)
			? global.wpApiSettings.root.replace(/\/$/, '') + '/zorderz/v1'
			: '/wp-json/zorderz/v1');

	function restNonce(root) {
		if (root && root.getAttribute('data-nonce')) return root.getAttribute('data-nonce');
		// Zorderz exposes a wp_rest nonce on the zdzData object (theme convention).
		if (global.zdzData && global.zdzData.nonce) return global.zdzData.nonce;
		if (global.wpApiSettings && global.wpApiSettings.nonce) return global.wpApiSettings.nonce;
		return '';
	}

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) n.className = cls;
		if (text != null) n.textContent = text;
		return n;
	}

	// Tiny inline icons (no icon-font dependency).
	var ICON = {
		chevron: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>',
		pin: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
		ext: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
		info: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
	};

	function escapeHtml(s) {
		var d = document.createElement('div');
		d.textContent = s == null ? '' : String(s);
		return d.innerHTML;
	}

	/**
	 * Mount a single .zdz-exif anchor.
	 */
	function mount(root) {
		if (!root || root.__tsExifMounted) return;
		root.__tsExifMounted = true;

		var mediaId = root.getAttribute('data-media-id');
		if (!mediaId) return;

		var state = { loaded: false, loading: false, open: false, verbatimOpen: false, data: null };

		// ── Header (always visible, clickable) ──
		var header = el('button', 'zdz-exif-header');
		header.type = 'button';
		header.setAttribute('aria-expanded', 'false');
		var hLeft = el('span', 'zdz-exif-header-left');
		hLeft.innerHTML = ICON.info + '<span class="zdz-exif-header-label">Details</span>';
		var hChevron = el('span', 'zdz-exif-chevron');
		hChevron.innerHTML = ICON.chevron;
		header.appendChild(hLeft);
		header.appendChild(hChevron);

		// ── Body (revealed on expand) ──
		var body = el('div', 'zdz-exif-body');
		body.hidden = true;

		root.appendChild(header);
		root.appendChild(body);

		header.addEventListener('click', function () {
			state.open = !state.open;
			header.setAttribute('aria-expanded', state.open ? 'true' : 'false');
			root.classList.toggle('zdz-exif-open', state.open);
			body.hidden = !state.open;
			if (state.open && !state.loaded && !state.loading) {
				fetchReport();
			}
		});

		function fetchReport() {
			state.loading = true;
			body.innerHTML = '';
			body.appendChild(loadingRow());

			var headers = { 'Accept': 'application/json' };
			var nonce = restNonce(root);
			if (nonce) headers['X-WP-Nonce'] = nonce;

			fetch(REST_BASE + '/media/' + encodeURIComponent(mediaId) + '/exif', {
				headers: headers,
				credentials: 'same-origin'
			})
				.then(function (r) {
					if (!r.ok) throw new Error('HTTP ' + r.status);
					return r.json();
				})
				.then(function (resp) {
					state.loaded = true;
					state.loading = false;
					state.data = resp && resp.report ? resp.report : null;
					render();
				})
				.catch(function (err) {
					state.loading = false;
					body.innerHTML = '';
					body.appendChild(errorRow(err));
				});
		}

		function render() {
			var rep = state.data;
			body.innerHTML = '';

			if (!rep || rep.tier === 'none') {
				body.appendChild(emptyRow());
				return;
			}

			// Summary line.
			if (rep.summary) {
				body.appendChild(el('div', 'zdz-exif-summary', rep.summary));
			}

			// Location block (coords + place + provenance + user-tap map link).
			if (rep.location) {
				body.appendChild(locationBlock(rep.location));
			}

			// Interpreted facts.
			if (rep.facts && rep.facts.length) {
				var dl = el('dl', 'zdz-exif-facts');
				rep.facts.forEach(function (f) {
					var dt = el('dt', 'zdz-exif-fact-label', f.label);
					var dd = el('dd', 'zdz-exif-fact-value', f.value);
					dl.appendChild(dt);
					dl.appendChild(dd);
				});
				body.appendChild(dl);
			}

			// Verbatim (only when a raw EXIF block exists).
			if (rep.has_exif && rep.verbatim && rep.verbatim.length) {
				body.appendChild(verbatimToggle(rep.verbatim));
			}
		}

		function locationBlock(loc) {
			var wrap = el('div', 'zdz-exif-loc');

			var line = el('div', 'zdz-exif-loc-line');
			var pin = el('span', 'zdz-exif-loc-pin');
			pin.innerHTML = ICON.pin;
			line.appendChild(pin);

			var txt = el('span', 'zdz-exif-loc-text');
			if (loc.place) {
				txt.innerHTML = '<strong>' + escapeHtml(loc.place) + '</strong><span class="zdz-exif-loc-coords">' + escapeHtml(loc.coord_label) + '</span>';
			} else {
				txt.innerHTML = '<strong>' + escapeHtml(loc.coord_label) + '</strong>';
			}
			line.appendChild(txt);
			wrap.appendChild(line);

			// Provenance chip: where the coordinate came from.
			var src = loc.geo_source === 'device_fallback'
				? 'Device location'
				: (loc.geo_source === 'exif' ? 'Photo GPS (EXIF)' : '');
			var chips = el('div', 'zdz-exif-loc-meta');
			if (src) {
				chips.appendChild(el('span', 'zdz-exif-chip', src));
			}
			// Place-name provenance (provider + when), if resolved.
			if (loc.place_full && loc.place_full.provider) {
				var prov = providerLabel(loc.place_full.provider);
				if (prov) chips.appendChild(el('span', 'zdz-exif-chip zdz-exif-chip-muted', prov));
			}
			if (chips.children.length) wrap.appendChild(chips);

			// User-initiated map link (server never phones home; only fires if tapped).
			if (loc.maps_url) {
				var a = el('a', 'zdz-exif-maps');
				a.href = loc.maps_url;
				a.target = '_blank';
				a.rel = 'noopener';
				a.innerHTML = 'Open in Maps ' + ICON.ext;
				wrap.appendChild(a);
			}

			return wrap;
		}

		function providerLabel(p) {
			if (p === 'offline-sdcounty') return 'Place: offline';
			if (p === 'filter') return 'Place: custom';
			return 'Place: ' + p;
		}

		function verbatimToggle(sections) {
			var box = el('div', 'zdz-exif-verbatim');

			var btn = el('button', 'zdz-exif-verbatim-btn');
			btn.type = 'button';
			btn.textContent = 'Show all metadata';
			btn.setAttribute('aria-expanded', 'false');

			var content = el('div', 'zdz-exif-verbatim-content');
			content.hidden = true;

			btn.addEventListener('click', function () {
				state.verbatimOpen = !state.verbatimOpen;
				btn.setAttribute('aria-expanded', state.verbatimOpen ? 'true' : 'false');
				btn.textContent = state.verbatimOpen ? 'Hide all metadata' : 'Show all metadata';
				content.hidden = !state.verbatimOpen;
				if (state.verbatimOpen && !content.__built) {
					buildVerbatim(content, sections);
					content.__built = true;
				}
			});

			box.appendChild(btn);
			box.appendChild(content);
			return box;
		}

		function buildVerbatim(container, sections) {
			sections.forEach(function (s) {
				container.appendChild(el('div', 'zdz-exif-vsection', s.section));
				var tbl = el('table', 'zdz-exif-vtable');
				var tb = el('tbody');
				s.rows.forEach(function (row) {
					var tr = el('tr');
					tr.appendChild(el('td', 'zdz-exif-vkey', row.key));
					tr.appendChild(el('td', 'zdz-exif-vval', row.value));
					tb.appendChild(tr);
				});
				tbl.appendChild(tb);
				container.appendChild(tbl);
			});
		}

		function loadingRow() {
			var d = el('div', 'zdz-exif-status');
			d.innerHTML = '<span class="zdz-exif-spinner"></span> Reading metadata…';
			return d;
		}
		function errorRow(err) {
			var d = el('div', 'zdz-exif-status zdz-exif-status-error');
			d.textContent = 'Couldn’t load metadata.';
			if (err && global.console) console.warn('TSMediaExif:', err.message);
			return d;
		}
		function emptyRow() {
			return el('div', 'zdz-exif-status', 'No metadata available for this photo.');
		}
	}

	function mountAll(scope) {
		var nodes = (scope || document).querySelectorAll('.zdz-exif[data-media-id]');
		Array.prototype.forEach.call(nodes, mount);
	}

	global.TSMediaExif = { mount: mount, mountAll: mountAll };

	// Auto-wire on DOM ready (idempotent; re-callable after dynamic inserts).
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { mountAll(); });
	} else {
		mountAll();
	}
})(typeof window !== 'undefined' ? window : this);
