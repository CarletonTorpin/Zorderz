/**
 * TS Internal Messaging — Widget (front-end)
 * v1.0.21
 *
 * Single-file vanilla JS. No framework, no build step. All class names
 * prefixed zim-w-* to avoid collision.
 *
 * LIFECYCLE (three-tier init, boot-once guard):
 *   1. Immediate when DOM ready + zimData localized
 *   2. zdz_widgets_rendered event (theme dispatches when slots mount)
 *   3. DOMContentLoaded fallback
 *
 * CONCURRENCY (Trap 1 — no websockets):
 *   At most 2 in-flight polls per tab: one sidebar tick, one active-convo
 *   tick. Each re-entry-guarded so a slow server doesn't cause stampede.
 *
 * SW RE-AUTH:
 *   SW posts 'zim-push-resubscribe' on pushsubscriptionchange; we
 *   re-subscribe with the current nonce.
 */

(function () {
	'use strict';

	// ── Boot ────────────────────────────────────────────────────
	var booted = false;
	function bootOnce() {
		if (booted) return;
		var root = document.getElementById('zim-widget');
		if (!root || typeof window.zimData === 'undefined') return;
		booted = true;
		try { new TSIMController(root); } catch (e) { console.error('[TSIM] boot failed', e); }
	}
	document.addEventListener('zdz_widgets_rendered', bootOnce);
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bootOnce);
	} else {
		bootOnce();
	}

	var data = window.zimData || {};

	// ── Utilities ───────────────────────────────────────────────
	function $(id) { return document.getElementById(id); }
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
		});
	}
	function fmtTime(iso) {
		if (!iso) return '';
		var d = new Date(iso);
		if (isNaN(d)) return '';
		var now = new Date();
		if (d.toDateString() === now.toDateString()) {
			return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
		}
		return d.getFullYear() === now.getFullYear()
			? d.toLocaleDateString([], { month: 'short', day: 'numeric' })
			: d.toLocaleDateString([], { year: 'numeric', month: 'short', day: 'numeric' });
	}
	function fmtSessionSep(iso) {
		if (!iso) return '';
		var d = new Date(iso);
		if (isNaN(d)) return '';
		var now = new Date();
		var time = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
		if (d.toDateString() === now.toDateString()) return 'Today ' + time;
		var yesterday = new Date(now); yesterday.setDate(now.getDate() - 1);
		if (d.toDateString() === yesterday.toDateString()) return 'Yesterday ' + time;
		if (d.getFullYear() === now.getFullYear()) {
			return d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' ' + time;
		}
		return d.toLocaleDateString([], { year: 'numeric', month: 'short', day: 'numeric' }) + ' ' + time;
	}

	/**
	 * Fullscreen image lightbox. Tap the overlay or close button to dismiss.
	 * Used when a user taps an image attachment in a chat message.
	 */
	function tsimShowLightbox(url, alt) {
		// Remove any existing lightbox first.
		var existing = document.getElementById('zim-lightbox');
		if (existing) existing.remove();

		var overlay = document.createElement('div');
		overlay.id = 'zim-lightbox';
		overlay.className = 'zim-lightbox';
		overlay.innerHTML =
			'<div class="zim-lightbox__backdrop"></div>' +
			'<div class="zim-lightbox__content">' +
				'<img src="' + url.replace(/"/g, '&quot;') + '" alt="' + (alt || '').replace(/"/g, '&quot;') + '">' +
			'</div>' +
			'<button class="zim-lightbox__close" type="button" aria-label="Close"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';

		overlay.querySelector('.zim-lightbox__backdrop').addEventListener('click', function () { overlay.remove(); });
		overlay.querySelector('.zim-lightbox__close').addEventListener('click', function () { overlay.remove(); });
		// Also close on Escape key.
		var escHandler = function (e) {
			if (e.key === 'Escape') { overlay.remove(); document.removeEventListener('keydown', escHandler); }
		};
		document.addEventListener('keydown', escHandler);

		document.body.appendChild(overlay);
	}

	function fmtBytes(n) {
		if (n < 1024) return n + ' B';
		if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
		return (n / 1048576).toFixed(1) + ' MB';
	}
	function b64urlToUint8(b64) {
		var pad = '='.repeat((4 - b64.length % 4) % 4);
		var raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
		var out = new Uint8Array(raw.length);
		for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
		return out;
	}
	function ab2b64url(buf) {
		var bytes = new Uint8Array(buf);
		var bin = '';
		for (var i = 0; i < bytes.byteLength; i++) bin += String.fromCharCode(bytes[i]);
		return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}

	function ajax(action, params, opts) {
		opts = opts || {};
		var method = opts.method || 'GET';
		var url = data.ajaxUrl;
		var body = null;
		var all = Object.assign({ action: action, nonce: data.nonce }, params || {});

		if (method === 'GET') {
			var usp = new URLSearchParams();
			Object.keys(all).forEach(function (k) {
				var v = all[k]; if (v == null) return;
				if (Array.isArray(v)) v.forEach(function (i) { usp.append(k + '[]', i); });
				else usp.append(k, v);
			});
			url += '?' + usp.toString();
		} else if (opts.formData instanceof FormData) {
			body = opts.formData;
			body.set('action', action); body.set('nonce', data.nonce);
		} else {
			body = new FormData();
			Object.keys(all).forEach(function (k) {
				var v = all[k]; if (v == null) return;
				if (Array.isArray(v)) v.forEach(function (i) { body.append(k + '[]', i); });
				else body.append(k, v);
			});
		}
		return fetch(url, { method: method, credentials: 'same-origin', body: body })
			.then(function (r) { return r.json().then(function (j) { return { status: r.status, json: j }; }); });
	}

	// Markdown-lite with DOMPurify sanitization. Reuses marked.js if present.
	// v1.0.21: Added italic support (_text_ and *text*), double-underscore bold,
	// and friendly display text for this site URLs.
	// v1.0.24: TSA v1.18.1 sends pre-rendered HTML (bodyDiv.innerHTML instead of
	// .textContent). Detect it and skip the markdown-lite pipeline — the regexes
	// will mangle HTML (underscores in href URLs, nested <a> tags from the URL
	// autolinker, asterisks in attribute values triggering italic). The DOMPurify
	// pass still runs so there's no XSS risk.
	function renderBody(raw) {
		var src = String(raw || '');

		// v1.0.24: If the message body already contains HTML tags, it was
		// pre-rendered by TSA v1.18.1+ or another plugin. Skip all text→HTML
		// transforms (markdown, @mentions, #NNNNN chips, vault slug conversion)
		// because those regexes assume plain text input and will produce broken
		// output when run against existing HTML (nested <a> tags, matches inside
		// attribute values, etc.). Go straight to DOMPurify.
		if (/<(a |a>|strong|em|ul|ol|li|br|p[ >\/])/i.test(src)) {
			if (window.DOMPurify && typeof window.DOMPurify.sanitize === 'function') {
				return window.DOMPurify.sanitize(src, {
					ADD_ATTR: ['target', 'rel', 'data-zim-ref', 'data-zim-state', 'data-zim-vault', 'data-zim-vault-slug'],
				});
			}
			return src;
		}

		var html;
		if (window.marked && typeof window.marked.parse === 'function') {
			try { html = window.marked.parse(src, { breaks: true, gfm: true }); }
			catch (e) { html = esc(src).replace(/\n/g, '<br>'); }
		} else {
			html = esc(src)
				.replace(/\n/g, '<br>')
				.replace(/`([^`\n]+)`/g, function (_, m) { return '<code>' + m + '</code>'; })
				// Bold: **text** and __text__
				.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
				.replace(/__([^_\n]+)__/g, '<strong>$1</strong>')
				// Italic: _text_ and *text* (bounded by whitespace/punctuation)
				.replace(/(^|[\s>])_([^_\n]+?)_([\s<.,;:!?)]|$)/gm, '$1<em>$2</em>$3')
				.replace(/(^|[\s>])\*([^*\n]+?)\*([\s<.,;:!?)]|$)/gm, '$1<em>$2</em>$3')
				// URLs → links with friendly display for this site
				.replace(/(https?:\/\/[^\s<]+)/g, function (m) {
					var display = m;
					try {
						var u = new URL(m);
						if (u.hostname.indexOf('zorderz') >= 0) {
							display = u.pathname.replace(/^\//, '') || u.hostname;
						}
					} catch(ignore) {}
					return '<a href="' + m + '" target="_blank" rel="noopener noreferrer">' + display + '</a>';
				});
		}
		// @mentions
		html = html.replace(/(^|[\s>])@([a-z0-9._-]+)/gi, function (m, pre, login) {
			return pre + '<span class="zim-w-mention">@' + esc(login) + '</span>';
		});
		// #NNNNN → preview chip placeholder
		html = html.replace(/(^|[^\w#])#(\d{3,8})(?!\d)/g, function (m, pre, num) {
			return pre + '<a href="#" class="zim-w-preview-chip is-loading" data-zim-ref="' + esc(num) + '" data-zim-state="pending">' +
				'<span class="zim-w-preview-number">#' + esc(num) + '</span>' +
				'<span class="zim-w-preview-meta">loading…</span></a>';
		});
		// v1.0.20: [VAULT-{id}] citations → vault preview chip
		html = html.replace(/\[VAULT-(\d{1,6})\]/gi, function (m, id) {
			return '<a href="#" class="zim-w-preview-chip zim-w-vault-chip is-loading" data-zim-vault="' + esc(id) + '" data-zim-state="pending">' +
				'<span class="zim-w-preview-number">📄 VAULT-' + esc(id) + '</span>' +
				'<span class="zim-w-preview-meta">loading…</span></a>';
		});
		// v1.0.20: your site's /vault/{slug} or /vault/{slug} URLs → vault preview chip
		// Host-agnostic: match a /vault/{slug} path with or without an origin.
		// This previously pinned one company's production hostname, so the chip
		// only rendered on that install.
		html = html.replace(/((?:https?:\/\/[^\s\/]+)?\/vault\/([a-z0-9][a-z0-9\-]{1,198}))/gi, function (m, fullUrl, slug) {
			return '<a href="#" class="zim-w-preview-chip zim-w-vault-chip is-loading" data-zim-vault-slug="' + esc(slug) + '" data-zim-state="pending">' +
				'<span class="zim-w-preview-number">📄 ' + esc(slug) + '</span>' +
				'<span class="zim-w-preview-meta">loading…</span></a>';
		});
		if (window.DOMPurify && typeof window.DOMPurify.sanitize === 'function') {
			html = window.DOMPurify.sanitize(html, {
				ADD_ATTR: ['target', 'rel', 'data-zim-ref', 'data-zim-state', 'data-zim-vault', 'data-zim-vault-slug'],
			});
		}
		return html;
	}

	// ── Efficiency gates (v1.0.17) ──────────────────────────────
	//
	// The widget previously polled `zim_sidebar` and `zim_poll` every 3s
	// for as long as the controller was alive — regardless of whether the
	// browser tab was even in the foreground or the widget was on-screen.
	// On the dashboard, the inline widget lives inside `#sv-dash`; when the
	// user navigates to Settings, Chat, Team, etc., the theme hides
	// `#sv-dash` via `display: none`, but the polling didn't notice. Same
	// when the widget was simply scrolled past on the same view.
	//
	// Two cheap gates fix that without server-side surgery:
	//
	//   1. Page Visibility API. When `document.hidden` is true (background
	//      tab, minimized window), `clearInterval` the timers entirely.
	//      When the tab returns to foreground, fire one immediate
	//      "catch-up" tick and resume the interval.
	//
	//   2. Per-tick on-screen check. Even with the tab in the foreground,
	//      skip the network call if the widget root has zero bounding rect
	//      (display:none ancestor) or sits entirely outside the viewport
	//      (scrolled past). The setInterval keeps firing — `getBoundingClientRect`
	//      costs microseconds — but no admin-ajax request goes out.
	//
	// True long-polling is the documented v1.2+ design and is a separate,
	// bigger change; this pass is purely client-side.

	function elementOnScreen(el) {
		if (!el) return true; // missing root → don't suppress polling
		var r = el.getBoundingClientRect();
		// Zero-area rect means the element (or an ancestor) is display:none
		// or otherwise unrendered. Includes the sub-view-not-active case
		// once the theme's `.sub-view:not(.active) { display:none !important }`
		// rule applies, since that propagates a zero rect to descendants.
		if (r.width <= 0 || r.height <= 0) return false;
		var vh = window.innerHeight || document.documentElement.clientHeight || 0;
		// Overlaps the viewport vertically. Horizontal overlap isn't checked
		// because nothing in the SPA shifts the widget out horizontally.
		return r.bottom > 0 && r.top < vh;
	}

	// Wraps a tick function as a pause/resumable poller. Owns the timer
	// state internally; callers get { start, stop, destroy } and a
	// visibilitychange subscription. The tick itself is responsible for
	// any per-call gating (e.g., elementOnScreen) — this helper handles
	// the tab-level gate only.
	//
	// `destroy()` is the one to use when a poller is being permanently
	// replaced (e.g., switching active conversation creates a new main
	// poller); `stop()` just pauses the timer but keeps the listener
	// registered for future restarts.
	// v1.1.1 — shared poll backoff. When the origin is struggling (HTTP 5xx or a
	// network failure on a poll), keep BACKING OFF instead of hammering it every
	// few seconds — the old fixed 3s cadence turned an overloaded origin into a
	// feedback loop (more polls → more PHP worker pressure → more 502s). The
	// factor is shared across BOTH pollers so the whole widget eases off together,
	// then snaps back to the base cadence on the first success.
	var tsimPollFailures = 0;
	function tsimNotePollResult(ok) {
		tsimPollFailures = ok ? 0 : Math.min(tsimPollFailures + 1, 5);
	}
	// A response is a "failure" for backoff purposes on a 5xx (origin/gateway
	// error) or a thrown/rejected fetch. 4xx (auth/nonce) is NOT a transient
	// origin problem, so it doesn't trigger backoff.
	function tsimPollOkFromStatus(status) {
		return !(status >= 500);
	}
	function tsimPollDelay(baseMs) {
		// base, ×2, ×4, ×8, ×16, capped — e.g. 10s → 20 → 40 → 80 → 120 (cap).
		var d = baseMs * Math.pow(2, tsimPollFailures);
		return Math.min(d, 120000);
	}

	// Wraps a tick as a pause/resumable, self-rescheduling poller with backoff.
	// Uses setTimeout (not setInterval) so each interval can adapt to the current
	// backoff factor. Same public interface as before: { start, stop, destroy }.
	function pollable(tick, intervalMs) {
		var timer = null;
		var destroyed = false;
		function schedule() {
			if (destroyed) return;
			timer = setTimeout(function () {
				timer = null;
				if (destroyed) return;
				if (!document.hidden) {
					try { tick(); } catch (e) { /* swallow — keep the loop healthy */ }
				}
				schedule(); // re-arm with the (possibly backed-off) delay
			}, tsimPollDelay(intervalMs));
		}
		function start() {
			if (timer || destroyed) return;
			// Immediate catch-up tick (unless backgrounded), then schedule.
			if (!document.hidden) {
				try { tick(); } catch (e) { /* swallow */ }
			}
			schedule();
		}
		function stop() {
			if (timer) { clearTimeout(timer); timer = null; }
		}
		function onVisibility() {
			if (destroyed) return;
			if (document.hidden) stop(); else start();
		}
		function destroy() {
			destroyed = true;
			stop();
			document.removeEventListener('visibilitychange', onVisibility);
		}
		document.addEventListener('visibilitychange', onVisibility);
		return { start: start, stop: stop, destroy: destroy };
	}

	// ── Controller ──────────────────────────────────────────────
	function TSIMController(root) {
		this.root = root;
		this.el = {
			messages: $('zim-w-messages'),
			mainTitle: $('zim-w-main-title'),
			composer: $('zim-w-composer'),
			readonlyNote: $('zim-w-readonly-note'),
			input: $('zim-w-input'),
			sendBtn: $('zim-w-send-btn'),
			attachBtn: $('zim-w-attach-btn'),
			fileInput: $('zim-w-file-input'),
			attachChips: $('zim-w-attach-chips'),
			mentionPop: $('zim-w-mention-pop'),
			channels: $('zim-w-channels'),
			dms: $('zim-w-dms'),
			sidebarToggle: $('zim-w-sidebar-toggle'),
			newChannelBtn: $('zim-w-new-channel'),
			newDmBtn: $('zim-w-new-dm'),
			settingsBtn: $('zim-w-settings-btn'),
			settings: $('zim-w-settings'),
			settingsClose: $('zim-w-settings-close'),
			quietSave: $('zim-w-quiet-save'),
			quietStart: $('zim-w-quiet-start'),
			quietEnd: $('zim-w-quiet-end'),
			channelModal: $('zim-w-channel-modal'),
			channelClose: $('zim-w-channel-close'),
			channelCreate: $('zim-w-channel-create'),
			channelSlug: $('zim-w-channel-slug'),
			channelDesc: $('zim-w-channel-desc'),
			channelPrivate: $('zim-w-channel-private'),
			dmModal: $('zim-w-dm-modal'),
			dmClose: $('zim-w-dm-close'),
			dmSearch: $('zim-w-dm-search'),
			dmCandidates: $('zim-w-dm-candidates'),
			searchBtn: $('zim-w-search-btn'),
			searchBar: $('zim-w-search-bar'),
			searchInput: $('zim-w-search-input'),
			pushBtn: $('zim-w-push-btn'),
			previewPanel: $('zim-w-preview-panel'),
			previewClose: $('zim-w-preview-close'),
			previewTitle: $('zim-w-preview-title'),
			previewBody: $('zim-w-preview-body'),
		};

		this.active = null;
		this.lastSeenId = 0;
		this.renderedIds = {};
		this.sidebarFetching = false;
		this.mainFetching = false;
		this.pendingAttachments = [];
		this.mention = { open: false, start: -1, candidates: [], active: 0 };
		this.previewObserver = null;
		this.previewCache = {};
		this.draftsKey = 'zim_drafts_' + (data.userId | 0);
		this.pendingDeepLink = (data.deepLinkConvoId | 0) || 0;
		this.searchMode = false;
		this.selectMode = false;   // v1.0.21: bulk-delete select mode
		this.selectedIds = {};     // v1.0.21: { messageId: true }
		this.embedMode = (data.embedMode || '') === 'tsa' || (data.embedMode || '') === 'theme';

		this.wireEvents();
		this.setupPreviewObserver();
		this.listenForSWMessages();
		this.listenForParentMessages();
		this.loadDrafts();
		this.initPullToRefresh(); // v1.0.21: Pull-down gesture to soft-refresh
		this.initSwipeToDelete(); // v1.0.21: Swipe-left to delete messages
		this.startSidebarPoll();

		// Initial view: show the list (matches iMessage's opening screen).
		// Applies to both embed and standalone modes. At wide viewports the
		// CSS ignores this class, so both panes render side-by-side.
		this.showListView();
	}

	// ── Event wiring ────────────────────────────────────────────
	TSIMController.prototype.wireEvents = function () {
		var self = this;
		this.el.sendBtn && this.el.sendBtn.addEventListener('click', function () { self.sendMessage(); });
		if (this.el.input) {
			this.el.input.addEventListener('input', function () { self.onInput(); });
			this.el.input.addEventListener('keydown', function (e) { self.onKeydown(e); });
			this.el.input.addEventListener('blur', function () {
				setTimeout(function () { self.closeMention(); }, 120);
			});
		}
		this.el.attachBtn && this.el.attachBtn.addEventListener('click', function () {
			self.el.fileInput && self.el.fileInput.click();
		});
		this.el.fileInput && this.el.fileInput.addEventListener('change', function () { self.onFilePicked(); });

		this.el.sidebarToggle && this.el.sidebarToggle.addEventListener('click', function () {
			self.root.classList.toggle('zim-w-sidebar-open');
		});

		// Back button — only visible in embed-mode narrow view.
		// Returns from the open conversation to the list.
		this.el.backBtn = document.getElementById('zim-w-back-btn');
		this.el.backBtn && this.el.backBtn.addEventListener('click', function () {
			self.showListView();
		});

		this.el.settingsBtn && this.el.settingsBtn.addEventListener('click', function () { self.showOverlay(self.el.settings); });
		this.el.settingsClose && this.el.settingsClose.addEventListener('click', function () { self.hideOverlay(self.el.settings); });
		this.el.quietSave && this.el.quietSave.addEventListener('click', function () { self.saveQuietHours(); });

		this.el.newChannelBtn && this.el.newChannelBtn.addEventListener('click', function () { self.showOverlay(self.el.channelModal); });
		this.el.channelClose && this.el.channelClose.addEventListener('click', function () { self.hideOverlay(self.el.channelModal); });
		this.el.channelCreate && this.el.channelCreate.addEventListener('click', function () { self.createChannel(); });

		this.el.newDmBtn && this.el.newDmBtn.addEventListener('click', function () { self.openNewDm(); });
		this.el.dmClose && this.el.dmClose.addEventListener('click', function () { self.hideOverlay(self.el.dmModal); });
		if (this.el.dmSearch) {
			var dmTimer = null;
			this.el.dmSearch.addEventListener('input', function () {
				clearTimeout(dmTimer);
				dmTimer = setTimeout(function () { self.searchDmCandidates(); }, 200);
			});
		}

		this.el.searchBtn && this.el.searchBtn.addEventListener('click', function () { self.toggleSearch(); });
		if (this.el.searchInput) {
			var searchTimer = null;
			this.el.searchInput.addEventListener('input', function () {
				clearTimeout(searchTimer);
				searchTimer = setTimeout(function () { self.runSearch(); }, 250);
			});
		}

		this.el.pushBtn && this.el.pushBtn.addEventListener('click', function () { self.promptPush(); });
		this.el.previewClose && this.el.previewClose.addEventListener('click', function () { self.hideOverlay(self.el.previewPanel); });

		// v1.0.21: Inject "Select" button into the main header actions
		var mainActions = this.root.querySelector('.zim-w-main-actions');
		if (mainActions) {
			var selectBtn = document.createElement('button');
			selectBtn.className = 'zim-w-hdr-btn zim-w-select-btn';
			selectBtn.type = 'button';
			selectBtn.textContent = 'Select';
			selectBtn.title = 'Select messages to delete';
			selectBtn.style.display = 'none'; // hidden until a convo is open
			mainActions.insertBefore(selectBtn, mainActions.firstChild);
			selectBtn.addEventListener('click', function () { self.toggleSelectMode(); });
			this.el.selectBtn = selectBtn;
		}

		// v1.0.21: Message tap in select mode toggles selection
		this.el.messages && this.el.messages.addEventListener('click', function (e) {
			if (!self.selectMode) return;
			var msg = e.target.closest('.zim-w-msg');
			if (!msg) return;
			e.preventDefault();
			e.stopPropagation();
			var mid = parseInt(msg.getAttribute('data-id'), 10);
			if (mid) self.toggleMessageSelect(mid);
		});

		// Preview chip + message-action clicks (delegated)
		this.el.messages && this.el.messages.addEventListener('click', function (e) {
			// v1.0.24: Regular <a> links in message bodies (from pre-rendered
			// HTML via TSA v1.18.1+) should navigate naturally. Don't intercept
			// them — they're already valid hyperlinks with href, target, rel.
			// Only vault/FreshBooks chips (which have .zim-w-preview-chip) get
			// special handling below.
			var plainLink = e.target.closest && e.target.closest('a:not(.zim-w-preview-chip)');
			if (plainLink && plainLink.href && plainLink.href !== '#') return;

			var chip = e.target.closest && e.target.closest('.zim-w-preview-chip');
			if (chip) {
				// v1.0.21: vault chips with a resolved URL navigate directly on
				// title click. The ⓘ info icon opens the metadata panel instead.
				// FreshBooks #NNNNN chips still open the preview panel as before.
				var vaultId   = chip.getAttribute('data-zim-vault');
				var vaultSlug = chip.getAttribute('data-zim-vault-slug');
				if (vaultId || vaultSlug) {
					// If the user clicked the info icon, open the panel
					var isInfoBtn = e.target.closest && e.target.closest('.zim-w-vault-info');
					if (isInfoBtn) {
						e.preventDefault();
						self.openVaultPreviewPanel(vaultId, vaultSlug);
					} else if (chip.href && chip.href !== '#' && chip.href !== window.location.href) {
						// URL resolved — let the browser navigate (href + target=_blank)
						return; // Don't preventDefault — let anchor navigate
					} else {
						// URL not yet resolved — fall back to panel
						e.preventDefault();
						self.openVaultPreviewPanel(vaultId, vaultSlug);
					}
				} else {
					e.preventDefault();
					self.openPreviewPanel(chip.getAttribute('data-zim-ref'));
				}
				return;
			}
			var action = e.target.closest && e.target.closest('[data-zim-action]');
			if (action) {
				e.preventDefault();
				var mEl = action.closest('.zim-w-msg');
				if (!mEl) return;
				var mid = parseInt(mEl.getAttribute('data-id'), 10);
				var op = action.getAttribute('data-zim-action');
				if (op === 'edit')   self.beginEdit(mid, mEl);
				if (op === 'delete') self.confirmDelete(mid);
			}
		});

		// Scroll-to-load older history
		this.el.messages && this.el.messages.addEventListener('scroll', function () {
			// v1.0.18 — increased threshold from 60 to 150 for easier
			// scroll-back triggering on iPad / touch devices where momentum
			// scrolling makes precise positioning difficult.
			if (self.el.messages.scrollTop < 150 && self.active && !self.mainFetching) {
				self.loadOlder();
			}
		});

		// Focus-aware read marking
		window.addEventListener('focus', function () { self.markRead(); });
		document.addEventListener('visibilitychange', function () {
			if (document.visibilityState === 'visible') self.markRead();
		});

		// v1.0.18 — iPad keyboard recovery.
		// On iPad (especially in iframe context), the software keyboard can
		// dismiss when the user scrolls the message list or taps outside the
		// textarea, and then refuses to reappear on subsequent taps. This is
		// a longstanding iOS WebKit bug with focusable elements inside iframes.
		//
		// Fix: when the user taps anywhere in the composer area (not just the
		// textarea itself), force-refocus the textarea. Also handle the case
		// where the textarea has `document.activeElement` but iOS has pulled
		// the keyboard away — a second programmatic focus() call forces it back.
		if (this.el.composer) {
			this.el.composer.addEventListener('touchstart', function (e) {
				// Don't steal focus from buttons (send, attach).
				if (e.target.closest('button') || e.target.closest('input[type="file"]')) return;
				if (self.el.input && self.active) {
					// Slight delay lets iOS finish its touch processing.
					setTimeout(function () {
						if (document.activeElement !== self.el.input) {
							self.el.input.focus();
						}
					}, 50);
				}
			}, { passive: true });
		}

		// Also watch for viewport resize (keyboard show/hide on iOS).
		// When the visual viewport shrinks (keyboard up) then grows back
		// (keyboard dismissed by iOS gesture), scroll the messages container
		// to keep the bottom in view. This prevents the "messages jumped up
		// and I lost my place" disorientation.
		if (window.visualViewport) {
			var lastVH = window.visualViewport.height;
			window.visualViewport.addEventListener('resize', function () {
				var newVH = window.visualViewport.height;
				var diff = newVH - lastVH;
				lastVH = newVH;
				// Keyboard just dismissed (viewport grew) — re-scroll to bottom
				// if we were near the bottom before.
				if (diff > 100 && self.isNearBottom()) {
					setTimeout(function () { self.scrollToBottom(); }, 60);
				}
			});
		}
	};

	/* ═══════════════════════════════════════════════════════════════════
	 * v1.0.21: PULL-TO-REFRESH
	 *
	 * Consistent with TSA v1.13.7/v1.13.8 implementation. On iOS the
	 * browser chrome disappears when scrolling, leaving no way to trigger
	 * a page refresh. This adds a pull-down gesture at the top of the
	 * messages area that soft-refreshes the sidebar + active conversation.
	 * ═══════════════════════════════════════════════════════════════════ */
	TSIMController.prototype.initPullToRefresh = function () {
		var self = this;
		var msgArea = this.el.messages;
		if (!msgArea) return;

		var startY = 0;
		var pulling = false;
		var indicator = null;
		var threshold = 45; // matches TSA v1.13.8 reduced threshold

		msgArea.addEventListener('touchstart', function (e) {
			// Only activate when scrolled to top
			if (msgArea.scrollTop <= 0) {
				startY = e.touches[0].clientY;
				pulling = true;
			}
		}, { passive: true });

		msgArea.addEventListener('touchmove', function (e) {
			if (!pulling) return;
			var deltaY = e.touches[0].clientY - startY;

			if (deltaY > 10 && msgArea.scrollTop <= 0) {
				// Show pull indicator
				if (!indicator) {
					indicator = document.createElement('div');
					indicator.className = 'zim-w-pull-indicator';
					indicator.textContent = '\u2193 Pull to refresh';
					msgArea.parentNode.insertBefore(indicator, msgArea);
				}

				var progress = Math.min(deltaY / threshold, 1);
				indicator.style.height = Math.min(deltaY * 0.5, 44) + 'px';
				indicator.style.opacity = progress;
				indicator.textContent = progress >= 1 ? '\u21BB Release to refresh' : '\u2193 Pull to refresh';
			}
		}, { passive: true });

		msgArea.addEventListener('touchend', function () {
			if (!pulling) return;
			pulling = false;

			if (indicator) {
				var wasReady = indicator.textContent.indexOf('Release') !== -1;
				indicator.remove();
				indicator = null;

				if (wasReady) {
					self.softRefresh();
				}
			}
		}, { passive: true });
	};

	/**
	 * v1.0.21: Soft refresh — reload sidebar + active conversation data
	 * in-place without navigating away. Matches TSA v1.13.8 pattern.
	 */
	TSIMController.prototype.softRefresh = function () {
		var self = this;

		// Reload the sidebar
		this.sidebarFetching = false; // Reset re-entry lock
		ajax('zim_sidebar').then(function (r) {
			self.sidebarFetching = false;
			if (r.json && r.json.success) self.renderSidebar(r.json.data);
		}).catch(function () { self.sidebarFetching = false; });

		// If viewing a conversation, reload its messages
		if (this.active) {
			this.mainFetching = false; // Reset re-entry lock
			ajax('zim_poll', { conversation_id: this.active.id, since: this.lastSeenId }).then(function (r) {
				self.mainFetching = false;
				if (!r.json || !r.json.success) return;
				var msgs = r.json.data.messages || [];
				if (!msgs.length) return;
				msgs.forEach(function (m) { self.appendMessage(m); });
				self.lastSeenId = r.json.data.latest_id || self.lastSeenId;
				self.scrollToBottom();
				self.markRead();
			}).catch(function () { self.mainFetching = false; });
		}
	};

	TSIMController.prototype.showOverlay = function (el) {
		if (el) { el.hidden = false; el.style.display = ''; }
	};
	TSIMController.prototype.hideOverlay = function (el) {
		if (el) { el.hidden = true; el.style.display = 'none'; }
	};

	/**
	 * iMessage-style view state toggles.
	 *
	 * Applies to BOTH embed mode and standalone dashboard-tile mode at
	 * narrow viewport widths. At wide viewports, the CSS renders both
	 * panes side-by-side regardless of which class is set — these become
	 * no-ops visually but still track state for responsive transitions.
	 *
	 * State model: mutually-exclusive classes on the root element.
	 *   .zim-w--view-list  → show sidebar, hide main
	 *   .zim-w--view-convo → show main, hide sidebar
	 */
	TSIMController.prototype.showConvoView = function () {
		this.root.classList.remove('zim-w--view-list');
		this.root.classList.add('zim-w--view-convo');
	};
	TSIMController.prototype.showListView = function () {
		this.root.classList.remove('zim-w--view-convo');
		this.root.classList.add('zim-w--view-list');
	};

	// ── Sidebar polling ─────────────────────────────────────────
	TSIMController.prototype.startSidebarPoll = function () {
		var self = this;
		var tick = function () {
			if (self.sidebarFetching) return;
			// v1.0.17 — skip the network call when the widget is off-screen.
			// Includes "user is on a different sub-view" (theme hides #sv-dash
			// via display:none) and "user scrolled past the widget" cases.
			if (!elementOnScreen(self.root)) return;
			self.sidebarFetching = true;
			ajax('zim_sidebar').then(function (r) {
				self.sidebarFetching = false;
				tsimNotePollResult(tsimPollOkFromStatus(r.status)); // v1.1.1 backoff signal
				if (r.status === 404) { self.hideAll(); return; }
				if (r.json && r.json.success) self.renderSidebar(r.json.data);
			}).catch(function () { self.sidebarFetching = false; tsimNotePollResult(false); });
		};
		// v1.1.1 — base 10s (server-provided, filterable), hard floor 8s; backoff
		// stretches this further while the origin is failing.
		this.sidebarPoll = pollable(tick, Math.max(8000, data.pollIntervalMs || 10000));
		this.sidebarPoll.start();
	};

	TSIMController.prototype.hideAll = function () {
		// v1.0.17 — fully destroy the pollable wrappers (clears their
		// internal timers AND removes the visibilitychange listeners they
		// each registered). `stop()` would only do the former, leaving the
		// listeners attached to a torn-down controller; on the next
		// visibilitychange they'd try to restart the polls.
		if (this.sidebarPoll) { this.sidebarPoll.destroy(); this.sidebarPoll = null; }
		if (this.mainPoll)    { this.mainPoll.destroy();    this.mainPoll    = null; }
		this.root.innerHTML = '';
	};

	TSIMController.prototype.renderSidebar = function (payload) {
		var self = this;
		var channels = payload.channels || [];
		var dms      = payload.dms || [];

		this.el.channels.innerHTML = '';
		channels.forEach(function (c) {
			var li = document.createElement('li');
			li.setAttribute('data-kind', 'channel');
			li.setAttribute('data-id', String(c.id));
			if (self.active && self.active.id === c.id) li.classList.add('zim-w-active');
			var label = document.createElement('span');
			label.className = 'zim-w-label';
			label.textContent = c.name || ('#' + (c.slug || ''));
			li.appendChild(label);
			if (c.is_private) {
				var lock = document.createElement('span');
				lock.className = 'zim-w-lock'; lock.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
				li.appendChild(lock);
			}
			if (c.unread > 0) {
				var b = document.createElement('span');
				b.className = 'zim-w-badge'; b.textContent = String(c.unread);
				li.appendChild(b);
			}
			li.addEventListener('click', function () {
				self.selectConversation({ kind: 'channel', id: c.id, name: label.textContent });
			});
			self.el.channels.appendChild(li);
		});

		this.el.dms.innerHTML = '';
		dms.forEach(function (d) {
			var li = document.createElement('li');
			li.setAttribute('data-kind', 'dm');
			li.setAttribute('data-id', String(d.id));
			if (self.active && self.active.id === d.id) li.classList.add('zim-w-active');
			// v1.0.23: self-DM entries get a distinct icon and CSS class
			if (d.is_self) li.classList.add('zim-w-self-dm');
			var label = document.createElement('span');
			label.className = 'zim-w-label';
			label.textContent = d.is_self ? '📝 ' + (d.other_name || 'Notes to Self') : (d.other_name || '(user)');
			li.appendChild(label);
			if (d.unread > 0) {
				var b = document.createElement('span');
				b.className = 'zim-w-badge'; b.textContent = String(d.unread);
				li.appendChild(b);
			}
			li.addEventListener('click', function () {
				self.selectConversation({ kind: 'dm', id: d.id, name: d.other_name || 'DM' });
			});
			self.el.dms.appendChild(li);
		});

		if (this.pendingDeepLink > 0) {
			var id = this.pendingDeepLink;
			this.pendingDeepLink = 0;
			var match = null;
			channels.forEach(function (c) { if (c.id === id) match = { kind: 'channel', id: c.id, name: c.name }; });
			if (!match) dms.forEach(function (d) { if (d.id === id) match = { kind: 'dm', id: d.id, name: d.other_name }; });
			if (match) this.selectConversation(match);
		}

		// In embed mode, signal the parent with total unread count so the analytics app's
		// badge stays in sync without a separate polling loop.
		if (this.embedMode && window.parent !== window) {
			var total = 0;
			channels.forEach(function (c) { total += (c.unread | 0); });
			dms.forEach(function (d) { total += (d.unread | 0); });
			try {
				window.parent.postMessage({ type: 'zim-embed-badge', unread: total }, window.location.origin);
			} catch (e) { /* cross-origin guard */ }
		}
	};

	// ── Conversation selection ──────────────────────────────────
	TSIMController.prototype.selectConversation = function (conv) {
		var self = this;
		// v1.0.18 — clear any pending loading timeout from a previous selection.
		if (this._loadingTimeout) { clearTimeout(this._loadingTimeout); this._loadingTimeout = null; }
		if (this.active && this.active.id === conv.id) {
			this.root.classList.remove('zim-w-sidebar-open');
			this.showConvoView();
			return;
		}
		this.saveDraft();
		this.active = conv;
		this.lastSeenId = 0;
		this.renderedIds = {};
		this._lastRenderedTs = 0;
		this.el.mainTitle.textContent = conv.name;
		// v1.0.24 — Reveal the composer for normal users. For read-only roles
		// (the shared kiosk) the composer footer is not in the DOM at all; we
		// reveal the read-only notice in its place instead. Guard both accesses
		// so a missing element never throws and breaks conversation opening.
		if (this.el.composer) {
			this.el.composer.hidden = false; this.el.composer.style.display = '';
		}
		if (this.el.readonlyNote) {
			this.el.readonlyNote.hidden = false; this.el.readonlyNote.style.display = '';
		}
		// v1.0.21: Show select button, reset select mode
		if (this.el.selectBtn) this.el.selectBtn.style.display = '';
		if (this.selectMode) this.toggleSelectMode();
		this.el.messages.innerHTML =
			'<div class="zim-w-loading">' +
				'<div class="zim-w-loading__spinner" aria-hidden="true"></div>' +
				'<div class="zim-w-loading__label">Loading messages…</div>' +
			'</div>';
		this.root.classList.remove('zim-w-sidebar-open');
		this.showConvoView();
		if (this.el.searchBar) { this.el.searchBar.hidden = true; this.el.searchBar.style.display = 'none'; this.searchMode = false; }
		this.pendingAttachments = [];
		this.renderAttachChips();

		this.root.querySelectorAll('.zim-w-list li').forEach(function (li) {
			li.classList.toggle('zim-w-active', parseInt(li.getAttribute('data-id'), 10) === conv.id);
		});

		// v1.0.17 — fully destroy the previous main poller before creating
		// a new one for the just-selected conversation. `destroy()` (vs
		// `stop()`) also removes the visibilitychange listener; otherwise
		// switching conversations N times would leak N listeners that all
		// race to start their (already-cleared) timers on tab refocus.
		if (this.mainPoll) { this.mainPoll.destroy(); this.mainPoll = null; }

		ajax('zim_fetch_before', { conversation_id: conv.id, before: 0 }).then(function (r) {
			if (!r.json || !r.json.success) {
				self.el.messages.innerHTML =
					'<div class="zim-w-empty">Could not load messages. Tap to retry.</div>';
				self.el.messages.querySelector('.zim-w-empty').style.cursor = 'pointer';
				self.el.messages.querySelector('.zim-w-empty').addEventListener('click', function () {
					self.selectConversation(conv);
				});
				return;
			}
			var msgs = r.json.data.messages || [];
			self.el.messages.innerHTML = '';
			msgs.forEach(function (m) { self.appendMessage(m); });
			self.lastSeenId = msgs.length ? msgs[msgs.length - 1].id : 0;
			self.scrollToBottom();
			self.markRead();
			self.startMainPoll();
			// v1.0.19: Skip restoreDraft when an embed auto-send is pending.
			// restoreDraft would overwrite the body that was just pre-filled
			// by scheduleEmbedAutoSend, causing the @-mention text to vanish.
			if (!self.pendingEmbedAutoSend) {
				self.restoreDraft();
			}
			self.el.input && self.el.input.focus();
		}).catch(function () {
			self.el.messages.innerHTML =
				'<div class="zim-w-empty">Connection issue. Tap to retry.</div>';
			self.el.messages.querySelector('.zim-w-empty').style.cursor = 'pointer';
			self.el.messages.querySelector('.zim-w-empty').addEventListener('click', function () {
				self.selectConversation(conv);
			});
		});

		// v1.0.18 — loading timeout fallback. If the fetch takes > 12s (slow
		// network, stalled connection), swap the spinner for a retry prompt so
		// the user isn't left staring at "Loading messages..." forever.
		this._loadingTimeout = setTimeout(function () {
			if (self.el.messages && self.el.messages.querySelector('.zim-w-loading')) {
				self.el.messages.innerHTML =
					'<div class="zim-w-empty">Still loading — tap to retry.</div>';
				self.el.messages.querySelector('.zim-w-empty').style.cursor = 'pointer';
				self.el.messages.querySelector('.zim-w-empty').addEventListener('click', function () {
					self.selectConversation(conv);
				});
			}
		}, 12000);
	};

	TSIMController.prototype.startMainPoll = function () {
		var self = this;
		var tick = function () {
			if (!self.active || self.mainFetching) return;
			// v1.0.17 — same on-screen gate as the sidebar poll. If the
			// widget isn't visible, no point fetching new messages for a
			// conversation the user can't see right now; we'll catch up
			// when they come back.
			if (!elementOnScreen(self.root)) return;
			self.mainFetching = true;
			ajax('zim_poll', { conversation_id: self.active.id, since: self.lastSeenId }).then(function (r) {
				self.mainFetching = false;
				tsimNotePollResult(tsimPollOkFromStatus(r.status)); // v1.1.1 backoff signal
				if (!r.json || !r.json.success) return;
				var msgs = r.json.data.messages || [];
				if (!msgs.length) return;
				var wasAtBottom = self.isNearBottom();
				msgs.forEach(function (m) { self.appendMessage(m); });
				self.lastSeenId = r.json.data.latest_id || self.lastSeenId;
				if (wasAtBottom) { self.scrollToBottom(); self.markRead(); }
			}).catch(function () { self.mainFetching = false; tsimNotePollResult(false); });
		};
		// v1.1.1 — base 10s (server-provided, filterable), hard floor 8s; backoff
		// stretches this further while the origin is failing.
		this.mainPoll = pollable(tick, Math.max(8000, data.pollIntervalMs || 10000));
		this.mainPoll.start();
	};

	TSIMController.prototype.loadOlder = function () {
		var self = this;
		if (!this.active) return;
		var oldestEl = this.el.messages.querySelector('.zim-w-msg');
		var oldestId = oldestEl ? parseInt(oldestEl.getAttribute('data-id'), 10) : 0;
		if (!oldestId) return;
		this.mainFetching = true;

		// v1.0.18 — visual feedback while loading older messages.
		var loader = document.createElement('div');
		loader.className = 'zim-w-scroll-loader';
		loader.innerHTML = '<div class="zim-w-loading__spinner" aria-hidden="true"></div>';
		this.el.messages.insertBefore(loader, this.el.messages.firstChild);

		var oldH = this.el.messages.scrollHeight;
		ajax('zim_fetch_before', { conversation_id: this.active.id, before: oldestId }).then(function (r) {
			self.mainFetching = false;
			if (loader.parentNode) loader.remove();
			if (!r.json || !r.json.success) return;
			var msgs = r.json.data.messages || [];
			if (!msgs.length) {
				// No more messages — show end-of-history marker.
				var endMark = self.el.messages.querySelector('.zim-w-end-of-history');
				if (!endMark) {
					endMark = document.createElement('div');
					endMark.className = 'zim-w-end-of-history';
					endMark.textContent = 'Beginning of conversation';
					self.el.messages.insertBefore(endMark, self.el.messages.firstChild);
				}
				return;
			}
			for (var i = msgs.length - 1; i >= 0; i--) self.prependMessage(msgs[i]);
			self.el.messages.scrollTop = self.el.messages.scrollHeight - oldH;
		}).catch(function () {
			self.mainFetching = false;
			if (loader.parentNode) loader.remove();
		});
	};

	TSIMController.prototype.isNearBottom = function () {
		var el = this.el.messages;
		return el.scrollHeight - el.scrollTop - el.clientHeight < 80;
	};
	TSIMController.prototype.scrollToBottom = function () {
		this.el.messages.scrollTop = this.el.messages.scrollHeight;
	};

	TSIMController.prototype.markRead = function () {
		if (!this.active || !this.lastSeenId) return;
		ajax('zim_mark_read', { conversation_id: this.active.id, message_id: this.lastSeenId }, { method: 'POST' });
	};

	// ── Message render ──────────────────────────────────────────
	/**
	 * Inject a time-gap separator if the new message is more than 30 minutes
	 * after the previously-rendered message. This gives visual "session"
	 * definition, matching how iMessage groups messages by conversation
	 * session rather than showing every timestamp.
	 */
	TSIMController.prototype.maybeInsertTimeSeparator = function (m, position) {
		var gapMinutes = 30;
		var prevTs = this._lastRenderedTs || 0;
		var thisTs = m && m.created_at ? new Date(m.created_at).getTime() : 0;
		if (!thisTs) return;
		// For 'append' (bottom), compare against the last appended timestamp.
		// For 'prepend' (top, older), we can't reliably infer sessions without
		// a full pass — skip in that case; the append path will set the anchor.
		if (position === 'append' && prevTs && (thisTs - prevTs) > gapMinutes * 60 * 1000) {
			var sep = document.createElement('div');
			sep.className = 'zim-w-time-sep';
			sep.textContent = fmtSessionSep(m.created_at);
			this.el.messages.appendChild(sep);
		}
		if (position === 'append') {
			this._lastRenderedTs = thisTs;
		}
	};

	TSIMController.prototype.appendMessage = function (m) {
		if (this.renderedIds[m.id]) { this.updateMessage(m); return; }
		this.renderedIds[m.id] = true;
		this.maybeInsertTimeSeparator(m, 'append');
		var node = this.renderMessageNode(m);
		this.el.messages.appendChild(node);
		this.observePreviewsIn(node);
	};
	TSIMController.prototype.prependMessage = function (m) {
		if (this.renderedIds[m.id]) return;
		this.renderedIds[m.id] = true;
		var node = this.renderMessageNode(m);
		this.el.messages.insertBefore(node, this.el.messages.firstChild);
		this.observePreviewsIn(node);
	};
	TSIMController.prototype.updateMessage = function (m) {
		var existing = this.el.messages.querySelector('[data-id="' + m.id + '"]');
		if (!existing) return;
		var fresh = this.renderMessageNode(m);
		existing.parentNode.replaceChild(fresh, existing);
		this.observePreviewsIn(fresh);
	};

TSIMController.prototype.renderMessageNode = function (m) {
		var isMine = !!(m.author && parseInt(m.author.id, 10) === parseInt(data.userId, 10));
		var node = document.createElement('div');
		// v1.0.20: In channels, all messages look left-aligned (Slack-style) so
		// everyone's messages are visually consistent with avatar + name.
		// In DMs, keep iMessage-style (mine = right, theirs = left).
		if (isChannel) {
			node.className = 'zim-w-msg zim-w-msg--theirs zim-w-msg--channel' + (isMine ? ' zim-w-msg--channel-mine' : '');
		} else {
			node.className = 'zim-w-msg' + (isMine ? ' zim-w-msg--mine' : ' zim-w-msg--theirs');
		}
		node.setAttribute('data-id', String(m.id));

		var authorName = (m.author && m.author.name) || 'Unknown';
		var isChannel = !!(this.active && this.active.kind === 'channel');

		// v1.0.20: In channels, show avatar + name on ALL messages (including yours).
		// This ensures "we always know who said what" in group conversations.
		// In DMs, keep iMessage-style (avatar/name only on other person's messages).
		var showIdentity = isChannel ? true : !isMine;

		// Avatar
		if (showIdentity && !m.deleted) {
			var initials = authorName.split(/\s+/).slice(0, 2).map(function (w) { return (w[0] || '').toUpperCase(); }).join('') || '?';
			var avatar = document.createElement('div');
			avatar.className = 'zim-w-msg-avatar';
			avatar.textContent = initials;
			node.appendChild(avatar);
		}

		var bubble = document.createElement('div');
		bubble.className = 'zim-w-msg-bubble';

		// Author name
		if (showIdentity && !m.deleted) {
			var nameEl = document.createElement('div');
			nameEl.className = 'zim-w-msg-name';
			nameEl.textContent = authorName;
			bubble.appendChild(nameEl);
		}

		// Message body
		var text = document.createElement('div');
		text.className = 'zim-w-msg-text';
		if (m.deleted) {
			text.classList.add('zim-w-msg-deleted');
			text.textContent = m.deleted_placeholder || '[deleted]';
		} else {
			text.innerHTML = renderBody(m.body);
		}
		bubble.appendChild(text);

		// Attachments inside the bubble
		if (m.attachments && m.attachments.length) {
			var atts = document.createElement('div');
			atts.className = 'zim-w-atts';
			m.attachments.forEach(function (a) {
				if (a.mime && a.mime.indexOf('image/') === 0) {
					var wrap = document.createElement('div');
					wrap.className = 'zim-w-att-image';
					var img = document.createElement('img');
					img.loading = 'lazy'; img.src = a.url; img.alt = a.name || '';
					// Tap to view full-size in a lightbox overlay.
					img.style.cursor = 'pointer';
					img.addEventListener('click', function (e) {
						e.stopPropagation();
						tsimShowLightbox(a.url, a.name || '');
					});
					wrap.appendChild(img);
					atts.appendChild(wrap);
				} else {
					var fileLink = document.createElement('a');
					fileLink.className = 'zim-w-att-file';
					fileLink.href = a.url; fileLink.target = '_blank'; fileLink.rel = 'noopener noreferrer';
					fileLink.innerHTML =
						'<span class="zim-w-att-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></span>' +
						'<span class="zim-w-att-name">' + esc(a.name || 'file') + '</span>' +
						'<span class="zim-w-att-size">' + esc(fmtBytes(a.size || 0)) + '</span>';
					atts.appendChild(fileLink);
				}
			});
			bubble.appendChild(atts);
		}

		node.appendChild(bubble);

		// Timestamp + actions — below the bubble, subtle
		var meta = document.createElement('div');
		meta.className = 'zim-w-msg-meta';
		// v1.0.20: In channels, always show author name in meta line for clarity
		var metaPrefix = (isChannel && !m.deleted) ? esc(authorName) + ' · ' : '';
		meta.innerHTML = '<span>' + metaPrefix + esc(fmtTime(m.created_at)) + '</span>' +
			(m.edited_at ? ' <span class="zim-w-edited">(edited)</span>' : '');

		if (!m.deleted) {
			var canEdit = !!(isMine && m.can_edit_until && new Date(m.can_edit_until).getTime() > Date.now());
			var canDelete = !!(isMine || data.isAdmin);
			if (canEdit || canDelete) {
				var actions = document.createElement('span');
				actions.className = 'zim-w-msg-actions';
				if (canEdit)   actions.innerHTML += '<button type="button" data-zim-action="edit">edit</button>';
				// v1.0.20: Show "admin delete" label when deleting someone else's message
				if (canDelete) {
					var deleteLabel = (isMine) ? 'delete' : 'admin delete';
					actions.innerHTML += '<button type="button" data-zim-action="delete">' + deleteLabel + '</button>';
				}
				meta.appendChild(actions);
			}
		}
		node.appendChild(meta);

		return node;
	};

	// ── Composer: typing, @-autocomplete, send ──────────────────
	TSIMController.prototype.onInput = function () {
		this.autoGrow();
		this.updateSendEnabled();
		this.maybeOpenMention();
		this.saveDraft();
	};
	TSIMController.prototype.autoGrow = function () {
		var el = this.el.input; if (!el) return;
		el.style.height = 'auto';
		el.style.height = Math.min(160, el.scrollHeight) + 'px';
	};
	TSIMController.prototype.updateSendEnabled = function () {
		if (!this.el.sendBtn || !this.el.input) return;
		var hasText = this.el.input.value.trim().length > 0;
		var hasAtt = this.pendingAttachments.length > 0;
		var uploading = (this._uploadsInFlight || 0) > 0;
		// Disable send if: (a) nothing to send, or (b) an upload is still
		// in progress. This prevents the race condition where the user taps
		// Send while a file is still uploading, which would send the message
		// without the attachment.
		this.el.sendBtn.disabled = uploading || !(hasText || hasAtt);
	};
	TSIMController.prototype.onKeydown = function (e) {
		if (this.mention.open) {
			if (e.key === 'ArrowDown') { e.preventDefault(); this.moveMention(1); return; }
			if (e.key === 'ArrowUp')   { e.preventDefault(); this.moveMention(-1); return; }
			if (e.key === 'Escape')    { this.closeMention(); return; }
			if (e.key === 'Enter' || e.key === 'Tab') {
				if (this.mention.candidates.length) { e.preventDefault(); this.acceptMention(); return; }
			}
		}
		if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.sendMessage(); }
	};

	TSIMController.prototype.maybeOpenMention = function () {
		var el = this.el.input; if (!el) return;
		var pos = el.selectionStart || 0;
		var before = el.value.substring(0, pos);
		var m = before.match(/(?:^|\s)@([a-z0-9._-]*)$/i);
		if (!m) { this.closeMention(); return; }
		var self = this;
		var frag = m[1] || '';
		this.mention.open = true;
		this.mention.start = pos - (m[0].length - (m[0].startsWith('@') ? 0 : 1));
		// Query conversation-scoped candidates.
		if (!this.active) { this.closeMention(); return; }
		ajax('zim_autocomplete_users', { conversation_id: this.active.id, q: frag }).then(function (r) {
			if (!self.mention.open) return;
			if (!r.json || !r.json.success) { self.closeMention(); return; }
			self.mention.candidates = r.json.data.candidates || [];
			self.mention.active = 0;
			self.renderMentionPop();
		});
	};
	TSIMController.prototype.renderMentionPop = function () {
		var pop = this.el.mentionPop; if (!pop) return;
		var self = this;
		if (!this.mention.candidates.length) { pop.hidden = true; pop.style.display = 'none'; return; }
		pop.innerHTML = '';
		this.mention.candidates.forEach(function (c, i) {
			var item = document.createElement('div');
			item.className = 'zim-w-mention-pop-item' + (i === self.mention.active ? ' is-active' : '');
			item.innerHTML = esc(c.name) + '<span class="zim-w-mention-login">@' + esc(c.login) + '</span>';
			item.addEventListener('mousedown', function (e) { e.preventDefault(); self.mention.active = i; self.acceptMention(); });
			pop.appendChild(item);
		});
		pop.hidden = false; pop.style.display = '';
	};
	TSIMController.prototype.moveMention = function (dir) {
		var n = this.mention.candidates.length;
		if (!n) return;
		this.mention.active = (this.mention.active + dir + n) % n;
		this.renderMentionPop();
	};
	TSIMController.prototype.acceptMention = function () {
		var el = this.el.input;
		var cand = this.mention.candidates[this.mention.active];
		if (!cand || !el) { this.closeMention(); return; }
		var before = el.value.substring(0, el.selectionStart || 0);
		var after = el.value.substring(el.selectionStart || 0);
		var replaced = before.replace(/@([a-z0-9._-]*)$/i, '@' + cand.login + ' ');
		el.value = replaced + after;
		var newPos = replaced.length;
		el.setSelectionRange(newPos, newPos);
		this.closeMention();
		this.updateSendEnabled();
		this.saveDraft();
	};
	TSIMController.prototype.closeMention = function () {
		this.mention.open = false;
		this.mention.candidates = [];
		if (this.el.mentionPop) { this.el.mentionPop.hidden = true; this.el.mentionPop.style.display = 'none'; }
	};

	TSIMController.prototype.sendMessage = function () {
		var self = this;
		// v1.0.24 — Read-only roles (the shared kiosk) can never send. The
		// composer isn't rendered for them, but guard here too in case any code
		// path (e.g. an embed auto-send instruction) reaches this. The server
		// refuses regardless; this avoids a pointless request and a null deref.
		if (data.isReadOnly || !this.el.sendBtn || !this.el.input) return;
		if (!this.active) return;
		var body = this.el.input ? this.el.input.value.trim() : '';
		var atts = this.pendingAttachments.map(function (a) { return a.id; });
		if (!body && !atts.length) return;
		this.el.sendBtn.disabled = true;
		ajax('zim_post', {
			conversation_id: this.active.id,
			body: body,
			attachment_ids: atts,
		}, { method: 'POST' }).then(function (r) {
			if (r.json && r.json.success) {
				self.el.input.value = '';
				self.autoGrow();
				self.pendingAttachments = [];
				self.renderAttachChips();
				self.updateSendEnabled();
				self.clearDraft();
				// The poll tick will fetch it shortly; optimistic append for snappier UX.
				var msgs = r.json.data && r.json.data.messages ? r.json.data.messages : [];
				msgs.forEach(function (m) { self.appendMessage(m); });
				self.lastSeenId = (r.json.data && r.json.data.message_id) || self.lastSeenId;
				self.scrollToBottom();
				self.markRead();
			} else if (r.json && r.json.data && r.json.data.message) {
				alert(r.json.data.message);
				self.updateSendEnabled();
			} else {
				self.updateSendEnabled();
			}
		}).catch(function () { self.updateSendEnabled(); });
	};

	// ── Edit / delete ──────────────────────────────────────────
	TSIMController.prototype.beginEdit = function (mid, mEl) {
		var self = this;
		var textEl = mEl.querySelector('.zim-w-msg-text'); if (!textEl) return;
		// Fetch the raw body from the rendered body_raw data — we stash it on the element at render time.
		// Simpler: prompt with the current text content. Markdown source is lost, but MEP acceptable.
		var current = textEl.textContent || '';
		var next = window.prompt('Edit message (within 5 minutes):', current);
		if (next == null) return;
		next = next.trim();
		if (!next) return;
		ajax('zim_edit', { message_id: mid, body: next }, { method: 'POST' }).then(function (r) {
			if (!r.json || !r.json.success) {
				alert((r.json && r.json.data && r.json.data.message) || 'Edit failed.');
				return;
			}
			// Poll will pick up the edit on the next tick; force an immediate fetch.
			self.lastSeenId = Math.max(0, mid - 1);
		});
	};
	TSIMController.prototype.confirmDelete = function (mid) {
		var self = this;
		if (!window.confirm('Delete this message? It will be replaced with "[deleted]".')) return;
		ajax('zim_delete', { message_id: mid }, { method: 'POST' }).then(function (r) {
			if (!r.json || !r.json.success) {
				alert((r.json && r.json.data && r.json.data.message) || 'Delete failed.');
				return;
			}
			self.lastSeenId = Math.max(0, mid - 1);
		});
	};

	/* ═══════════════════════════════════════════════════════════════════
	 * v1.0.21: SELECT MODE — Bulk-delete multiple messages
	 *
	 * On desktop: "Select" button in header → checkboxes appear on each
	 * message → floating bar shows "Delete N selected".
	 * On mobile: same flow, but also supports swipe-left-to-delete on
	 * individual messages for quick single-message removal.
	 * ═══════════════════════════════════════════════════════════════════ */

	TSIMController.prototype.toggleSelectMode = function () {
		this.selectMode = !this.selectMode;
		this.selectedIds = {};
		this.root.classList.toggle('zim-w--select-mode', this.selectMode);
		// Update button label
		var btn = this.root.querySelector('.zim-w-select-btn');
		if (btn) btn.textContent = this.selectMode ? 'Cancel' : 'Select';
		// Remove/add floating bar
		this.updateBulkBar();
		// Toggle checkboxes on existing messages
		this.root.querySelectorAll('.zim-w-msg').forEach(function (msg) {
			var existing = msg.querySelector('.zim-w-select-cb');
			if (existing) existing.remove();
		});
	};

	TSIMController.prototype.toggleMessageSelect = function (mid) {
		if (this.selectedIds[mid]) {
			delete this.selectedIds[mid];
		} else {
			this.selectedIds[mid] = true;
		}
		// Update visual state
		var el = this.el.messages.querySelector('[data-id="' + mid + '"]');
		if (el) el.classList.toggle('zim-w-msg--selected', !!this.selectedIds[mid]);
		this.updateBulkBar();
	};

	TSIMController.prototype.updateBulkBar = function () {
		var existing = this.root.querySelector('.zim-w-bulk-bar');
		var count = Object.keys(this.selectedIds).length;

		if (!this.selectMode || count === 0) {
			if (existing) existing.remove();
			return;
		}

		if (!existing) {
			existing = document.createElement('div');
			existing.className = 'zim-w-bulk-bar';
			this.root.appendChild(existing);
			var self = this;
			existing.addEventListener('click', function () { self.bulkDelete(); });
		}
		existing.innerHTML =
			'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
			' Delete ' + count + ' message' + (count > 1 ? 's' : '');
	};

	TSIMController.prototype.bulkDelete = function () {
		var ids = Object.keys(this.selectedIds);
		if (!ids.length) return;
		if (!window.confirm('Delete ' + ids.length + ' message' + (ids.length > 1 ? 's' : '') + '? This cannot be undone.')) return;

		var self = this;
		ajax('zim_bulk_delete', { message_ids: ids.join(',') }, { method: 'POST' }).then(function (r) {
			if (r.json && r.json.success) {
				var count = (r.json.data && r.json.data.count) || ids.length;
				self.toggleSelectMode();
				// Force refresh to show [deleted] placeholders
				if (self.active) {
					self.lastSeenId = 0;
					self.renderedIds = {};
					self.selectConversation(self.active);
				}
			} else {
				alert('Some messages could not be deleted.');
			}
		});
	};

	/* ═══════════════════════════════════════════════════════════════════
	 * v1.0.21: SWIPE-TO-DELETE (touch devices)
	 *
	 * Swipe a message left to reveal a red "Delete" action. Release past
	 * the threshold to confirm-delete. Swipe back or tap elsewhere to cancel.
	 * Only on messages the user can delete (own or admin).
	 * ═══════════════════════════════════════════════════════════════════ */

	TSIMController.prototype.initSwipeToDelete = function () {
		var self = this;
		var msgArea = this.el.messages;
		if (!msgArea) return;

		var activeSwipe = null; // { el, startX, startY, swiping }

		msgArea.addEventListener('touchstart', function (e) {
			if (self.selectMode) return; // select mode uses taps, not swipes
			var msg = e.target.closest('.zim-w-msg');
			if (!msg) return;
			// Only swipeable if it has a delete action (canDelete was true)
			if (msg.querySelector('.zim-w-msg-deleted')) return;
			var mid = parseInt(msg.getAttribute('data-id'), 10);
			var isMine = msg.classList.contains('zim-w-msg--mine') || msg.classList.contains('zim-w-msg--channel-mine');
			if (!isMine && !data.isAdmin) return;

			// Don't activate on links, buttons, images
			var tag = (e.target.tagName || '').toLowerCase();
			if (tag === 'a' || tag === 'button' || tag === 'img' || e.target.closest('a, button')) return;

			activeSwipe = {
				el: msg,
				mid: mid,
				startX: e.touches[0].clientX,
				startY: e.touches[0].clientY,
				swiping: false,
				dx: 0,
			};
		}, { passive: true });

		msgArea.addEventListener('touchmove', function (e) {
			if (!activeSwipe) return;
			var dx = e.touches[0].clientX - activeSwipe.startX;
			var dy = Math.abs(e.touches[0].clientY - activeSwipe.startY);

			// If more vertical than horizontal, abort (scrolling)
			if (!activeSwipe.swiping && dy > Math.abs(dx)) { activeSwipe = null; return; }

			// Only swipe left (dx < 0)
			if (dx > 5) { activeSwipe = null; return; }

			if (dx < -10) {
				activeSwipe.swiping = true;
				activeSwipe.dx = dx;
				var shift = Math.max(dx, -120);
				activeSwipe.el.style.transform = 'translateX(' + shift + 'px)';
				activeSwipe.el.style.transition = 'none';

				// Show/update the red delete zone behind
				var zone = activeSwipe.el.querySelector('.zim-w-swipe-zone');
				if (!zone) {
					zone = document.createElement('div');
					zone.className = 'zim-w-swipe-zone';
					zone.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg> Delete';
					activeSwipe.el.style.position = 'relative';
					activeSwipe.el.appendChild(zone);
				}
				zone.style.width = Math.min(Math.abs(shift), 120) + 'px';
				zone.classList.toggle('zim-w-swipe-ready', Math.abs(dx) > 80);
			}
		}, { passive: true });

		msgArea.addEventListener('touchend', function () {
			if (!activeSwipe) return;
			var s = activeSwipe;
			activeSwipe = null;

			if (!s.swiping) return;

			var zone = s.el.querySelector('.zim-w-swipe-zone');

			if (Math.abs(s.dx) > 80) {
				// Past threshold — delete
				s.el.style.transition = 'transform 0.2s ease';
				s.el.style.transform = 'translateX(-120px)';
				setTimeout(function () {
					self.confirmDelete(s.mid);
					s.el.style.transition = 'transform 0.25s ease';
					s.el.style.transform = '';
					if (zone) zone.remove();
				}, 200);
			} else {
				// Snap back
				s.el.style.transition = 'transform 0.2s ease';
				s.el.style.transform = '';
				setTimeout(function () { if (zone) zone.remove(); }, 200);
			}
		}, { passive: true });
	};

	// ── Attachments ────────────────────────────────────────────

	/**
	 * Track in-flight uploads. While _uploadsInFlight > 0, the Send
	 * button is disabled. This prevents the race condition where the
	 * user taps Send before an upload completes — otherwise the message
	 * goes out without the attachment.
	 */
	TSIMController.prototype.onFilePicked = function () {
		var self = this;
		var f = this.el.fileInput.files && this.el.fileInput.files[0];
		this.el.fileInput.value = '';
		if (!f) return;
		if (f.size > (data.maxUploadBytes || 5242880)) {
			alert('File too large (max ' + fmtBytes(data.maxUploadBytes || 5242880) + ').');
			return;
		}

		// Create a "pending" placeholder chip immediately so the user
		// sees feedback while the upload runs. This is what iMessage does
		// when you pick a photo — it shows a thumbnail with a progress
		// ring, not an empty composer.
		var placeholderId = '_up_' + Date.now();
		var isImage = f.type && f.type.indexOf('image/') === 0;
		var previewUrl = '';
		if (isImage && window.URL && window.URL.createObjectURL) {
			try { previewUrl = URL.createObjectURL(f); } catch (e) { /* no preview */ }
		}
		var placeholder = {
			id: placeholderId,
			name: f.name,
			url: previewUrl,
			mime: f.type || '',
			size: f.size,
			_uploading: true,
		};
		this.pendingAttachments.push(placeholder);
		this._uploadsInFlight = (this._uploadsInFlight || 0) + 1;
		this.renderAttachChips();
		this.updateSendEnabled();

		var fd = new FormData();
		fd.append('attachment', f);
		ajax('zim_upload', {}, { method: 'POST', formData: fd }).then(function (r) {
			self._uploadsInFlight = Math.max(0, (self._uploadsInFlight || 1) - 1);
			if (!r.json || !r.json.success) {
				// Upload failed — remove the placeholder chip.
				self.pendingAttachments = self.pendingAttachments.filter(function (a) {
					return a.id !== placeholderId;
				});
				self.renderAttachChips();
				self.updateSendEnabled();
				alert((r.json && r.json.data && r.json.data.message) || 'Upload failed.');
				return;
			}
			var d = r.json.data;
			// Replace the placeholder with the real attachment data.
			for (var i = 0; i < self.pendingAttachments.length; i++) {
				if (self.pendingAttachments[i].id === placeholderId) {
					// Revoke the local preview URL to free memory.
					if (previewUrl) {
						try { URL.revokeObjectURL(previewUrl); } catch (e) {}
					}
					// IMPORTANT: use `d.id` (the zim_attachments table ID), NOT
					// `d.attachment_id` (the WP posts ID). The send handler passes
					// these IDs to bind_to_message() which queries `a.id` in
					// wp_zim_attachments. Using the wrong ID means the binding
					// SQL never matches and the attachment stays orphaned.
					self.pendingAttachments[i] = {
						id: d.id,
						name: d.filename || d.name || 'file',
						url: d.url || '',
						mime: d.mime || '',
						size: d.size || 0,
						_uploading: false,
					};
					break;
				}
			}
			self.renderAttachChips();
			self.updateSendEnabled();
		}).catch(function () {
			self._uploadsInFlight = Math.max(0, (self._uploadsInFlight || 1) - 1);
			self.pendingAttachments = self.pendingAttachments.filter(function (a) {
				return a.id !== placeholderId;
			});
			self.renderAttachChips();
			self.updateSendEnabled();
			alert('Upload failed — please try again.');
		});
	};

	TSIMController.prototype.renderAttachChips = function () {
		if (!this.el.attachChips) return;
		var self = this;
		this.el.attachChips.innerHTML = '';
		this.pendingAttachments.forEach(function (a, i) {
			var chip = document.createElement('span');
			chip.className = 'zim-w-attach-chip' + (a._uploading ? ' zim-w-attach-chip--uploading' : '');

			// Image thumbnail preview — show the image itself, not just the filename.
			var isImage = a.mime && a.mime.indexOf('image/') === 0;
			if (isImage && a.url) {
				var thumb = document.createElement('img');
				thumb.className = 'zim-w-attach-chip__thumb';
				thumb.src = a.url;
				thumb.alt = '';
				chip.appendChild(thumb);
			}

			var label = document.createElement('span');
			label.className = 'zim-w-attach-chip__label';
			if (a._uploading) {
				label.innerHTML = '<span class="zim-w-attach-chip__spinner"></span> Uploading…';
			} else {
				label.textContent = esc(a.name) + ' (' + esc(fmtBytes(a.size)) + ')';
			}
			chip.appendChild(label);

			if (!a._uploading) {
				var x = document.createElement('button');
				x.type = 'button'; x.textContent = '×'; x.title = 'Remove';
				x.addEventListener('click', function () {
					self.pendingAttachments.splice(i, 1);
					self.renderAttachChips();
					self.updateSendEnabled();
				});
				chip.appendChild(x);
			}
			self.el.attachChips.appendChild(chip);
		});
	};

	// ── Drafts (per-conversation, in sessionStorage) ───────────
	TSIMController.prototype.loadDrafts = function () {
		try { this.drafts = JSON.parse(sessionStorage.getItem(this.draftsKey) || '{}') || {}; }
		catch (e) { this.drafts = {}; }
	};
	TSIMController.prototype.saveDraft = function () {
		if (!this.active || !this.el.input) return;
		this.drafts[this.active.id] = this.el.input.value;
		try { sessionStorage.setItem(this.draftsKey, JSON.stringify(this.drafts)); } catch (e) {}
	};
	TSIMController.prototype.restoreDraft = function () {
		if (!this.active || !this.el.input) return;
		var draft = this.drafts[this.active.id] || '';
		this.el.input.value = draft;
		this.autoGrow();
		this.updateSendEnabled();
	};
	TSIMController.prototype.clearDraft = function () {
		if (!this.active) return;
		delete this.drafts[this.active.id];
		try { sessionStorage.setItem(this.draftsKey, JSON.stringify(this.drafts)); } catch (e) {}
	};

	// ── Search ─────────────────────────────────────────────────
	TSIMController.prototype.toggleSearch = function () {
		if (!this.el.searchBar) return;
		this.searchMode = !this.searchMode;
		this.el.searchBar.hidden = !this.searchMode; this.el.searchBar.style.display = this.searchMode ? '' : 'none';
		if (this.searchMode) { this.el.searchInput.focus(); this.el.searchInput.value = ''; }
		else { this.runSearch(); } // empty = reloads regular view
	};
	TSIMController.prototype.runSearch = function () {
		var self = this;
		if (!this.active) return;
		var q = this.el.searchInput.value.trim();
		if (!this.searchMode || !q) {
			// Reset to normal view
			this.renderedIds = {};
			this.el.messages.innerHTML = '';
			this.lastSeenId = 0;
			ajax('zim_fetch_before', { conversation_id: this.active.id, before: 0 }).then(function (r) {
				if (!r.json || !r.json.success) return;
				var msgs = r.json.data.messages || [];
				msgs.forEach(function (m) { self.appendMessage(m); });
				self.lastSeenId = msgs.length ? msgs[msgs.length - 1].id : 0;
				self.scrollToBottom();
			});
			return;
		}
		ajax('zim_search', { conversation_id: this.active.id, q: q }).then(function (r) {
			if (!r.json || !r.json.success) return;
			var hits = r.json.data.hits || [];
			self.renderedIds = {};
			self.el.messages.innerHTML = '';
			if (!hits.length) {
				self.el.messages.innerHTML = '<div class="zim-w-empty">No results in this conversation.</div>';
				return;
			}
			// Search returns newest-first; flip for natural reading order.
			for (var i = hits.length - 1; i >= 0; i--) self.appendMessage(hits[i]);
		});
	};

	// ── Preview cards ──────────────────────────────────────────
	TSIMController.prototype.setupPreviewObserver = function () {
		if (!('IntersectionObserver' in window)) return;
		var self = this;
		this.previewObserver = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (!entry.isIntersecting) return;
				var chip = entry.target;
				if (chip.getAttribute('data-zim-state') !== 'pending') {
					self.previewObserver.unobserve(chip); return;
				}
				chip.setAttribute('data-zim-state', 'loading');
				self.previewObserver.unobserve(chip);
				// v1.0.20: Route vault chips to vault loader
				var vaultId   = chip.getAttribute('data-zim-vault');
				var vaultSlug = chip.getAttribute('data-zim-vault-slug');
				if (vaultId || vaultSlug) {
					self.loadVaultPreviewInto(chip, vaultId, vaultSlug);
				} else {
					var ref = chip.getAttribute('data-zim-ref');
					self.loadPreviewInto(chip, ref);
				}
			});
		}, { root: this.el.messages, rootMargin: '100px' });
	};
	TSIMController.prototype.observePreviewsIn = function (scope) {
		if (!this.previewObserver) return;
		var chips = scope.querySelectorAll('.zim-w-preview-chip[data-zim-state="pending"]');
		chips.forEach(function (c) { this.previewObserver.observe(c); }, this);
	};
	TSIMController.prototype.loadPreviewInto = function (chip, ref) {
		var self = this;
		if (this.previewCache[ref]) { this.applyPreview(chip, this.previewCache[ref]); return; }
		ajax('zim_preview_ref', { number: ref }).then(function (r) {
			if (!r.json || !r.json.success) return;
			var card = r.json.data;
			self.previewCache[ref] = card;
			document.querySelectorAll('.zim-w-preview-chip[data-zim-ref="' + ref + '"]').forEach(function (c) {
				self.applyPreview(c, card);
			});
		});
	};
	TSIMController.prototype.applyPreview = function (chip, card) {
		chip.classList.remove('is-loading');
		chip.setAttribute('data-zim-state', 'loaded');
		chip.href = card.url || '#';
		chip.target = '_blank';
		chip.rel = 'noopener noreferrer';
		var meta = chip.querySelector('.zim-w-preview-meta');
		if (meta) {
			if (card.unavailable || card.source === 'fallback') {
				meta.textContent = 'open in FreshBooks';
			} else {
				var parts = [];
				if (card.kind) parts.push(card.kind);
				if (card.customer_name) parts.push(card.customer_name);
				if (card.total != null) parts.push((card.currency || '$') + card.total);
				if (card.status) parts.push(card.status);
				meta.textContent = parts.join(' · ');
			}
		}
	};
	TSIMController.prototype.openPreviewPanel = function (ref) {
		var self = this;
		this.showOverlay(this.el.previewPanel);
		this.el.previewTitle.textContent = '#' + ref;
		this.el.previewBody.innerHTML = '<div class="zim-w-empty">Loading…</div>';
		var render = function (card) {
			if (card.unavailable || card.source === 'fallback') {
				self.el.previewBody.innerHTML =
					'<p>Preview unavailable. <a href="' + esc(card.url || '#') + '" target="_blank" rel="noopener">Open in FreshBooks</a>.</p>';
				return;
			}
			self.el.previewBody.innerHTML =
				'<dl>' +
				'<dt>Kind</dt><dd>' + esc(card.kind || '') + '</dd>' +
				'<dt>Customer</dt><dd>' + esc(card.customer_name || '—') + '</dd>' +
				'<dt>Total</dt><dd>' + esc((card.currency || '') + ' ' + (card.total != null ? card.total : '—')) + '</dd>' +
				'<dt>Status</dt><dd>' + esc(card.status || '—') + '</dd>' +
				'</dl>' +
				'<p><a href="' + esc(card.url || '#') + '" target="_blank" rel="noopener">Open in FreshBooks</a></p>';
		};
		if (this.previewCache[ref]) { render(this.previewCache[ref]); return; }
		ajax('zim_preview_ref', { number: ref }).then(function (r) {
			if (!r.json || !r.json.success) return;
			self.previewCache[ref] = r.json.data;
			render(r.json.data);
		});
	};

	// ── v1.0.20: Knowledge Vault preview cards ─────────────────
	TSIMController.prototype.loadVaultPreviewInto = function (chip, vaultId, vaultSlug) {
		var self = this;
		var cacheKey = 'vault-' + (vaultId || vaultSlug);
		if (this.previewCache[cacheKey]) { this.applyVaultPreview(chip, this.previewCache[cacheKey]); return; }
		var params = vaultId ? { id: vaultId } : { slug: vaultSlug };
		ajax('zim_preview_vault', params).then(function (r) {
			if (!r.json || !r.json.success) return;
			var card = r.json.data;
			self.previewCache[cacheKey] = card;
			// Update all matching vault chips (same doc referenced multiple times)
			var selector = vaultId
				? '.zim-w-vault-chip[data-zim-vault="' + vaultId + '"]'
				: '.zim-w-vault-chip[data-zim-vault-slug="' + vaultSlug + '"]';
			document.querySelectorAll(selector).forEach(function (c) {
				self.applyVaultPreview(c, card);
			});
		});
	};
	TSIMController.prototype.applyVaultPreview = function (chip, card) {
		chip.classList.remove('is-loading');
		chip.setAttribute('data-zim-state', 'loaded');
		// Store resolved ID on slug-based chips for the detail panel
		if (card.id && !chip.getAttribute('data-zim-vault')) {
			chip.setAttribute('data-zim-vault', card.id);
		}
		var numEl = chip.querySelector('.zim-w-preview-number');
		if (numEl && card.title && !card.unavailable) {
			numEl.textContent = '📄 ' + card.title;
		}
		var meta = chip.querySelector('.zim-w-preview-meta');
		if (meta) {
			if (card.unavailable) {
				meta.textContent = 'document unavailable';
			} else {
				var parts = [];
				if (card.document_type) parts.push(card.document_type);
				if (card.uploader_name) parts.push('by ' + card.uploader_name);
				if (card.synopsis) parts.push(card.synopsis.length > 60 ? card.synopsis.substring(0, 57) + '…' : card.synopsis);
				meta.textContent = parts.join(' · ') || 'vault document';
			}
		}
		// v1.0.21: Set href so clicking the chip title navigates directly to the
		// vault page (opens in browser, no download). Reduced from 4 clicks to 1.
		if (card.url) {
			chip.href = card.url;
			chip.target = '_blank';
			chip.rel = 'noopener noreferrer';
		}
		// v1.0.21: Append an info icon (ⓘ) for users who want the metadata
		// panel. The icon is a secondary action — clicking it opens the panel
		// while clicking the title navigates directly.
		if (!chip.querySelector('.zim-w-vault-info') && !card.unavailable) {
			var infoBtn = document.createElement('span');
			infoBtn.className = 'zim-w-vault-info';
			infoBtn.setAttribute('role', 'button');
			infoBtn.setAttribute('aria-label', 'View details');
			infoBtn.setAttribute('title', 'View details');
			infoBtn.textContent = 'ⓘ';
			chip.appendChild(infoBtn);
		}
	};
	TSIMController.prototype.openVaultPreviewPanel = function (vaultId, vaultSlug) {
		var self = this;
		this.showOverlay(this.el.previewPanel);
		this.el.previewTitle.textContent = '📄 Vault Document';
		this.el.previewBody.innerHTML = '<div class="zim-w-empty">Loading…</div>';
		var render = function (card) {
			if (card.unavailable) {
				self.el.previewBody.innerHTML =
					'<p>This vault document is unavailable or has been removed.</p>';
				return;
			}
			self.el.previewTitle.textContent = '📄 ' + esc(card.title || 'Untitled');
			self.el.previewBody.innerHTML =
				'<dl>' +
				'<dt>Type</dt><dd>' + esc(card.document_type || 'general') + '</dd>' +
				'<dt>Synopsis</dt><dd>' + esc(card.synopsis || '—') + '</dd>' +
				(card.uploader_name ? '<dt>Uploaded by</dt><dd>' + esc(card.uploader_name) + '</dd>' : '') +
				(card.uploaded_at ? '<dt>Date</dt><dd>' + esc(card.uploaded_at.split(' ')[0] || '') + '</dd>' : '') +
				'</dl>' +
				(card.url ? '<p><a href="' + esc(card.url) + '" target="_blank" rel="noopener">Open in Vault</a></p>' : '');
		};
		var cacheKey = 'vault-' + (vaultId || vaultSlug);
		if (this.previewCache[cacheKey]) { render(this.previewCache[cacheKey]); return; }
		var params = vaultId ? { id: vaultId } : { slug: vaultSlug };
		ajax('zim_preview_vault', params).then(function (r) {
			if (!r.json || !r.json.success) return;
			self.previewCache[cacheKey] = r.json.data;
			render(r.json.data);
		});
	};

	// ── Channel create / New DM ────────────────────────────────
	TSIMController.prototype.createChannel = function () {
		var self = this;
		var slug = (this.el.channelSlug.value || '').trim();
		var desc = (this.el.channelDesc.value || '').trim();
		var isPrivate = this.el.channelPrivate.checked ? 1 : 0;
		if (!slug) { alert('Slug is required.'); return; }
		ajax('zim_channel_create', { slug: slug, description: desc, is_private: isPrivate }, { method: 'POST' }).then(function (r) {
			if (!r.json || !r.json.success) {
				alert((r.json && r.json.data && r.json.data.message) || 'Channel create failed.');
				return;
			}
			self.hideOverlay(self.el.channelModal);
			self.el.channelSlug.value = '';
			self.el.channelDesc.value = '';
			self.el.channelPrivate.checked = false;
			// Force sidebar refresh
			self.sidebarFetching = false;
			ajax('zim_sidebar').then(function (r2) {
				if (r2.json && r2.json.success) {
					self.renderSidebar(r2.json.data);
					self.selectConversation({ kind: 'channel', id: r.json.data.conversation_id, name: '#' + slug });
				}
			});
		});
	};
	TSIMController.prototype.openNewDm = function () {
		// v1.0.24 — Read-only roles cannot start DMs. The affordance is hidden
		// for them; guard the method too so nothing can open the picker.
		if (data.isReadOnly) return;
		this.showOverlay(this.el.dmModal);
		if (this.el.dmSearch) { this.el.dmSearch.value = ''; this.el.dmSearch.focus(); }
		if (this.el.dmCandidates) this.el.dmCandidates.innerHTML = '';
		this.searchDmCandidates();
	};
	TSIMController.prototype.searchDmCandidates = function () {
		var self = this;
		var q = this.el.dmSearch ? this.el.dmSearch.value : '';
		ajax('zim_user_search', { q: q }).then(function (r) {
			if (!r.json || !r.json.success) return;
			var users = r.json.data.users || [];
			self.el.dmCandidates.innerHTML = '';
			users.forEach(function (u) {
				var li = document.createElement('li');
				li.innerHTML =
					'<span class="zim-w-label">' + esc(u.name) +
					'<span class="zim-w-mention-login">@' + esc(u.login) + '</span></span>';
				li.addEventListener('click', function () {
					ajax('zim_dm_open', { user_id: u.user_id }, { method: 'POST' }).then(function (r2) {
						if (!r2.json || !r2.json.success) return;
						self.hideOverlay(self.el.dmModal);
						self.sidebarFetching = false;
						ajax('zim_sidebar').then(function (r3) {
							if (r3.json && r3.json.success) self.renderSidebar(r3.json.data);
							self.selectConversation({ kind: 'dm', id: r2.json.data.conversation_id, name: u.name });
						});
					});
				});
				self.el.dmCandidates.appendChild(li);
			});
		});
	};

	// ── Settings: quiet hours ───────────────────────────────────
	TSIMController.prototype.saveQuietHours = function () {
		var start = this.el.quietStart.value;
		var end = this.el.quietEnd.value;
		ajax('zim_set_quiet_hours', { start: start, end: end }, { method: 'POST' }).then(function (r) {
			if (!r.json || !r.json.success) {
				alert((r.json && r.json.data && r.json.data.message) || 'Could not save quiet hours.');
			}
		});
	};

	// ── Push subscription ───────────────────────────────────────
	TSIMController.prototype.promptPush = function () {
		var self = this;
		if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
			alert('Push notifications are not supported by this browser.');
			return;
		}
		if (!data.vapidPublicKey) {
			alert('Push is not configured on the server.');
			return;
		}
		navigator.serviceWorker.register(data.swUrl).then(function (reg) {
			return Notification.requestPermission().then(function (perm) {
				if (perm !== 'granted') {
					alert('Notification permission denied.');
					return;
				}
				return reg.pushManager.getSubscription().then(function (existing) {
					if (existing) return existing;
					return reg.pushManager.subscribe({
						userVisibleOnly: true,
						applicationServerKey: b64urlToUint8(data.vapidPublicKey),
					});
				}).then(function (sub) {
					if (!sub) return;
					var json = sub.toJSON();
					return ajax('zim_push_subscribe', {
						endpoint: sub.endpoint,
						p256dh: json.keys && json.keys.p256dh,
						auth:   json.keys && json.keys.auth,
					}, { method: 'POST' });
				}).then(function () {
					alert('Notifications enabled.');
				});
			});
		}).catch(function (err) {
			console.error('[TSIM] push setup failed', err);
			alert('Could not enable notifications.');
		});
	};

	TSIMController.prototype.listenForSWMessages = function () {
		var self = this;
		if (!('serviceWorker' in navigator)) return;
		navigator.serviceWorker.addEventListener('message', function (event) {
			if (!event.data) return;
			if (event.data.type === 'zim-push-resubscribe') {
				self.promptPush();
			} else if (event.data.type === 'zim-open-conversation') {
				var cid = event.data.conversation_id | 0;
				if (cid > 0) {
					// Force a sidebar reload then select.
					ajax('zim_sidebar').then(function (r) {
						if (r.json && r.json.success) {
							self.renderSidebar(r.json.data);
							var m = null;
							(r.json.data.channels || []).forEach(function (c) { if (c.id === cid) m = { kind:'channel', id:c.id, name:c.name }; });
							(r.json.data.dms || []).forEach(function (d)     { if (d.id === cid) m = { kind:'dm',      id:d.id, name:d.other_name }; });
							if (m) self.selectConversation(m);
						}
					});
				}
			}
		});
	};

	/**
	 * Listen for postMessages from the parent window (TSA embed).
	 *
	 * Only runs when we're inside an iframe — `window.parent !== window`.
	 * On boot, announces readiness so the parent can flush any queued
	 * DM-route requests. Then handles two message types:
	 *
	 *   zim-embed-dm-with  — open/create a DM with user_id, optionally
	 *                          send `body` as the first message.
	 *   zim-embed-close-req — parent asks us to self-close (rare; here
	 *                          for symmetry with outbound close messages).
	 *
	 * All postMessage targetOrigin checks use the parent's origin (same
	 * as ours since we only embed on same-origin), but we also verify
	 * event.source matches window.parent for defense-in-depth.
	 */
	TSIMController.prototype.listenForParentMessages = function () {
		var self = this;
		if (window.parent === window) return; // not embedded — nothing to do

		// Announce readiness to the parent so it can flush queued DM routes.
		try {
			window.parent.postMessage({ type: 'zim-embed-ready' }, window.location.origin);
		} catch (e) { /* parent might not accept — harmless */ }

		window.addEventListener('message', function (ev) {
			if (!ev || !ev.data || typeof ev.data !== 'object') return;
			if (ev.source !== window.parent) return;
			var msg = ev.data;

			if (msg.type === 'zim-embed-dm-with') {
				self.acceptDmRoute(msg);
			}
			// v1.0.20: Share text from TSA vault share button.
			// Pre-fills the current conversation's composer with the shared text.
			if (msg.type === 'zim-embed-share-text' && msg.body) {
				var ta = document.querySelector('.zim-w-compose-input, #zim-w-compose-input');
				if (ta) {
					ta.value = String(msg.body);
					ta.focus();
					// Auto-resize if handler exists
					ta.dispatchEvent(new Event('input', { bubbles: true }));
				}
			}
			// Theme change propagation. Parent sends this when the user picks
			// a new theme in the theme settings. We apply it immediately so
			// the embed matches without requiring a browser refresh.
			if (msg.type === 'zim-embed-theme' && typeof msg.theme === 'string') {
				var t = msg.theme.replace(/[^a-z-]/gi, '').slice(0, 16);
				if (t) {
					document.documentElement.setAttribute('data-theme', t);
				}
			}
		});

		// Same-origin fallback: the theme's own JS writes localStorage.zdz_theme
		// whenever the user changes themes. Listen for storage events so we
		// pick up the change even when the parent doesn't postMessage us.
		// (The storage event only fires on *other* windows of the same origin
		// that have loaded the same storage — which is exactly our situation:
		// the parent is the writer, we're the other window reading.)
		window.addEventListener('storage', function (ev) {
			if (!ev || ev.key !== 'zdz_theme' || !ev.newValue) return;
			var t = String(ev.newValue).replace(/[^a-z-]/gi, '').slice(0, 16);
			if (t) {
				document.documentElement.setAttribute('data-theme', t);
			}
		});
	};

	/**
	 * Handle an embed DM-route instruction:
	 *  1. Open (or get) the DM with user_id
	 *  2. Refresh the sidebar so the DM appears there
	 *  3. Select the conversation
	 *  4. If a body was supplied and auto_send is true, send it
	 *
	 * Steps 2-4 happen sequentially because selectConversation does async
	 * work (fetch_before → render). We send AFTER selection has loaded
	 * the history, so the auto-sent message appears correctly placed
	 * (as the latest) rather than being rendered before the history
	 * arrives and then re-ordered.
	 */
	TSIMController.prototype.acceptDmRoute = function (msg) {
		var self = this;
		// v1.0.24 — On a read-only shared device, refuse the TSA embed's
		// DM-route / auto-send instruction outright. This is the client-side
		// half of the messaging lockdown: even if an upstream Brain Bot emitted
		// a DM draft, this account cannot open a DM or auto-send. The server
		// blocks (zim_dm_open / ZIM_Messages::post) are the real guarantee;
		// this prevents the request from ever being attempted and acks the
		// parent so it doesn't wait on a delivery that will never come.
		if (data.isReadOnly) {
			ackEmbed('blocked', String(msg && msg.body || ''));
			return;
		}
		var userId = msg.user_id | 0;
		if (!userId) return;
		var displayName = String(msg.name || msg.login || 'DM');
		var body = String(msg.body || '').trim();
		var autoSend = !!msg.auto_send && body.length > 0;

		ajax('zim_dm_open', { user_id: userId }, { method: 'POST' }).then(function (r) {
			if (!r.json || !r.json.success) return;
			var convId = r.json.data.conversation_id | 0;
			if (!convId) return;

			// Refresh sidebar so the DM is visible there, then select.
			ajax('zim_sidebar').then(function (r2) {
				if (r2.json && r2.json.success) {
					self.renderSidebar(r2.json.data);
				}
				self.selectConversation({ kind: 'dm', id: convId, name: displayName });

				// Auto-send if a body was supplied. We poll for selectConversation
				// completion by looking for the composer being unhidden — a cheap
				// proxy for "the conversation has loaded."
				if (autoSend) {
					self.pendingEmbedAutoSend = { convId: convId, body: body, tries: 0 };
					scheduleEmbedAutoSend(self);
				}
			});
		});
	};

	function scheduleEmbedAutoSend(self) {
		// Up to 20 tries × 100ms = 2s budget. If history fetch is slower than
		// that, the user will just need to hit send themselves; not worth
		// blocking the UI.
		setTimeout(function () {
			var p = self.pendingEmbedAutoSend;
			if (!p) return;
			var composerReady = self.active
				&& self.active.id === p.convId
				&& self.el.composer
				&& !self.el.composer.hidden
				&& self.el.input;
			if (!composerReady) {
				if (++p.tries < 20) {
					scheduleEmbedAutoSend(self);
				} else {
					// Give up — leave the body pre-filled so the user can send it.
					if (self.el.input) self.el.input.value = p.body;
					self.pendingEmbedAutoSend = null;
					// v1.0.19: Acknowledge pre-fill to parent so it can clear the analytics input
					ackEmbed('prefilled', p.body);
				}
				return;
			}
			self.el.input.value = p.body;
			self.updateSendEnabled();
			self.sendMessage();
			self.pendingEmbedAutoSend = null;
			// v1.0.19: Acknowledge delivery to parent
			ackEmbed('sent', p.body);
		}, 100);
	}

	/**
	 * v1.0.19: Send acknowledgment to parent window (TSA embed).
	 * The parent holds the analytics input text until it receives this ack,
	 * then clears it. If ack never arrives, the parent restores the text.
	 */
	function ackEmbed(status, body) {
		if (window.parent === window) return;
		try {
			window.parent.postMessage({
				type:   'zim-embed-dm-delivered',
				status: status, // 'sent' | 'prefilled'
				body:   body || '',
			}, window.location.origin);
		} catch (e) { /* parent might not accept — harmless */ }
	}

})();
