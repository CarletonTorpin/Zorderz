/**
 * Zorderz Internal Messaging — Widget (front-end)
 * v1.1.4
 *
 * v1.1.4: Vimeo chapter embed. A trusted-origin Vimeo player + clickable chapter
 * list, distilled server-side to a [zdz-video] token and rebuilt here with the
 * iframe origin fixed to player.vimeo.com (never an arbitrary iframe src).
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
 *   SW posts 'zim-push-resubscribe' on pushsubscriptionchange. The theme's
 *   shared routine (window.ZDZPush) heals it - inside the app the widget runs
 *   in a same-origin frame and reaches it on window.parent.
 *
 * PUSH BELL:
 *   #zim-w-push-btn runs the SAME routine as Settings -> Notifications
 *   (ZDZPush: one switch for notices, Messages and chat answers on this
 *   device) and is drawn filled while this device is subscribed for Messages.
 *   Only the standalone full page (?zim_page=1 outside the app, no theme)
 *   keeps the Messages-only subscribe.
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
		try { new ZIMController(root); } catch (e) { console.error('[Messaging] boot failed', e); }
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
	/** The app shell's Bridge (this page's, or the same-origin parent's), or null. */
	function zimShellBridge() {
		try { if (window.Bridge && typeof window.Bridge.loadApp === 'function') return window.Bridge; } catch (e) {}
		try { if (window.parent && window.parent !== window && window.parent.Bridge && typeof window.parent.Bridge.loadApp === 'function') return window.parent.Bridge; } catch (e2) {}
		return null;
	}
	// "From your chat" on one's own message opens the chat it came from (the chat app, in the app shell).
	document.addEventListener('click', function (ev) {
		var b = ev.target && ev.target.closest ? ev.target.closest('.zim-w-from-chat') : null;
		if (!b) return;
		ev.preventDefault();
		var sid = parseInt(b.getAttribute('data-zim-chat-session'), 10);
		var br = zimShellBridge();
		if (br && sid > 0) { try { br.loadApp('sales-analytics', { session: sid }); } catch (e) {} }
	});

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
	function zimShowLightbox(url, alt) {
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

	/** A phone or tablet: a coarse pointer and no fine one. */
	function isTouchFirst() {
		try { return window.matchMedia('(pointer: coarse)').matches && !window.matchMedia('(any-pointer: fine)').matches; }
		catch (e) { return false; }
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

	/* ═══════════════════════════════════════════════════════════════════════
	 * v1.1.4: Vimeo chapter embed (trusted-origin rebuild).
	 *
	 * The server (ZIM_Messages::extract_video_embeds) distils a trusted Vimeo
	 * embed to a plain-text [zdz-video]<hex>[/zdz-video] token carrying only a
	 * numeric video id, optional privacy hash, and the chapter list. Here we
	 * rebuild the player — with the iframe origin HARD-FIXED to player.vimeo.com
	 * and the id re-validated numeric — so a forged token (the token is plain
	 * text a user could type) can never point the iframe off Vimeo. Chapter
	 * clicks preventDefault() and seek host-side via the Vimeo postMessage API,
	 * with a #t= reload fallback. Labels go in via textContent — injection-proof.
	 * ═══════════════════════════════════════════════════════════════════════ */
	var VIDEO_PH_OPEN = 'zdzvideotokenopen';
	var VIDEO_PH_CLOSE = 'zdzvideotokenclose';
	var MSHARE_PH_OPEN = 'zdzmsharetokenopen';
	var MSHARE_PH_CLOSE = 'zdzmsharetokenclose';
	var VIMEO_ORIGIN = 'https://player.vimeo.com';

	function zimHexToStr(hex) {
		var bytes = [];
		for (var i = 0; i + 1 < hex.length; i += 2) bytes.push(parseInt(hex.substr(i, 2), 16));
		try { return new TextDecoder('utf-8').decode(new Uint8Array(bytes)); }
		catch (e) { var s = ''; for (var j = 0; j < bytes.length; j++) s += String.fromCharCode(bytes[j]); return s; }
	}
	function zimClock(sec) {
		sec = Math.max(0, sec | 0);
		var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
		function p(n) { return (n < 10 ? '0' : '') + n; }
		return h > 0 ? h + ':' + p(m) + ':' + p(s) : m + ':' + p(s);
	}
	function zimHms(sec) {
		sec = Math.max(0, sec | 0);
		var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
		return (h > 0 ? h + 'h' : '') + m + 'm' + s + 's';
	}

	// Active embed iframes, and a single message listener that marks each ready
	// when its player handshakes. Only messages from the Vimeo origin are trusted.
	var zimVideoFrames = [];
	var zimVimeoListenerAdded = false;
	var zimVideoSeq = 0;
	function zimEnsureVimeoListener() {
		if (zimVimeoListenerAdded) return;
		zimVimeoListenerAdded = true;
		window.addEventListener('message', function (e) {
			if (e.origin !== VIMEO_ORIGIN) return;   // trusted origin only
			for (var i = 0; i < zimVideoFrames.length; i++) {
				var f = zimVideoFrames[i];
				if (f.iframe && e.source === f.iframe.contentWindow) f.ready = true;
			}
		}, false);
	}
	function zimPruneVideoFrames() {
		zimVideoFrames = zimVideoFrames.filter(function (f) {
			return f.iframe && f.iframe.isConnected;
		});
	}

	function wireVideoEmbeds(container) {
		if (!container || !container.querySelectorAll) return;
		zimPruneVideoFrames();
		var nodes = container.querySelectorAll('.zim-video-embed[data-zim-video-token]');
		for (var i = 0; i < nodes.length; i++) buildOneVideoEmbed(nodes[i]);
	}

	function buildOneVideoEmbed(host) {
		var hex = host.getAttribute('data-zim-video-token') || '';
		host.removeAttribute('data-zim-video-token');
		if (!/^([0-9a-f]{2})+$/i.test(hex)) { host.remove(); return; }

		var data;
		try { data = JSON.parse(zimHexToStr(hex)); } catch (e) { host.remove(); return; }
		if (!data || typeof data !== 'object') { host.remove(); return; }

		var id = String(data.v == null ? '' : data.v);
		if (!/^\d+$/.test(id)) { host.remove(); return; }          // id MUST be numeric
		var hash = String(data.h == null ? '' : data.h);
		if (hash && !/^[0-9a-zA-Z]+$/.test(hash)) hash = '';        // drop a malformed hash
		var chapters = Array.isArray(data.c) ? data.c : [];

		// Origin is FIXED here. The token can never move the iframe off Vimeo.
		var pid = 'zimvid_' + (++zimVideoSeq);
		var params = 'dnt=1&badge=0&autopause=0&api=1&player_id=' + pid + (hash ? '&h=' + encodeURIComponent(hash) : '');
		var srcBase = VIMEO_ORIGIN + '/video/' + id + '?' + params;
		var watchBase = 'https://vimeo.com/' + id + (hash ? '/' + hash : '');

		host.className = 'zim-video-embed';
		while (host.firstChild) host.removeChild(host.firstChild);

		var frameWrap = document.createElement('div');
		frameWrap.className = 'zim-video-embed__frame';
		var iframe = document.createElement('iframe');
		iframe.src = srcBase;
		iframe.title = 'Video';
		iframe.setAttribute('frameborder', '0');
		iframe.setAttribute('allow', 'autoplay; fullscreen; picture-in-picture; encrypted-media');
		iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
		iframe.allowFullscreen = true;
		iframe.loading = 'lazy';
		frameWrap.appendChild(iframe);
		host.appendChild(frameWrap);

		var record = { pid: pid, iframe: iframe, ready: false };
		zimVideoFrames.push(record);
		zimEnsureVimeoListener();

		if (chapters.length) {
			var nav = document.createElement('nav');
			nav.className = 'zim-video-embed__chapters';
			nav.setAttribute('aria-label', 'Video chapters');
			var heading = document.createElement('p');
			heading.className = 'zim-video-embed__heading';
			heading.textContent = 'Chapters';
			nav.appendChild(heading);
			var ol = document.createElement('ol');
			ol.className = 'zim-video-embed__list';
			for (var c = 0; c < chapters.length; c++) {
				var item = chapters[c];
				var sec = 0, label = '';
				if (Array.isArray(item)) { sec = parseInt(item[0], 10) || 0; label = String(item[1] == null ? '' : item[1]); }
				else if (item && typeof item === 'object') { sec = parseInt(item.t, 10) || 0; label = String(item.x == null ? '' : item.x); }
				if (sec < 0) sec = 0;
				var li = document.createElement('li');
				var a = document.createElement('a');
				a.className = 'zim-video-embed__ch';
				a.href = watchBase + '#t=' + zimHms(sec);            // fallback link (preventDefault below)
				a.target = '_blank';
				a.rel = 'noopener noreferrer';
				var num = document.createElement('span'); num.className = 'zim-video-embed__num'; num.textContent = String(c + 1);
				var tm = document.createElement('span'); tm.className = 'zim-video-embed__time'; tm.textContent = zimClock(sec);
				var ti = document.createElement('span'); ti.className = 'zim-video-embed__title'; ti.textContent = label; // injection-proof
				a.appendChild(num); a.appendChild(tm); a.appendChild(ti);
				(function (seconds) {
					a.addEventListener('click', function (e) {
						e.preventDefault();                          // never navigate
						seekVimeoEmbed(record, srcBase, seconds);
					});
				})(sec);
				li.appendChild(a);
				ol.appendChild(li);
			}
			nav.appendChild(ol);
			host.appendChild(nav);
		}
	}

	function seekVimeoEmbed(record, srcBase, sec) {
		var iframe = record.iframe;
		if (record.ready && iframe && iframe.contentWindow) {
			try {
				iframe.contentWindow.postMessage(JSON.stringify({ method: 'setCurrentTime', value: sec }), VIMEO_ORIGIN);
				iframe.contentWindow.postMessage(JSON.stringify({ method: 'setMuted', value: false }), VIMEO_ORIGIN);
				iframe.contentWindow.postMessage(JSON.stringify({ method: 'play' }), VIMEO_ORIGIN);
				return;
			} catch (e) { /* fall through to the reload fallback */ }
		}
		// #t= reload fallback — loads the player straight to the timestamp when the
		// postMessage API hasn't handshaked (blocked, not yet ready, older player).
		if (iframe) {
			iframe.src = srcBase + '#t=' + zimHms(sec);
			record.ready = false; // re-handshakes after the reload
		}
	}

	// Markdown-lite with DOMPurify sanitization. Reuses marked.js if present.
	// v1.0.21: Added italic support (_text_ and *text*), double-underscore bold,
	// and friendly display text for this site URLs.
	// v1.0.24: Analytics v1.18.1 sends pre-rendered HTML (bodyDiv.innerHTML instead of
	// .textContent). Detect it and skip the markdown-lite pipeline — the regexes
	// will mangle HTML (underscores in href URLs, nested <a> tags from the URL
	// autolinker, asterisks in attribute values triggering italic). The DOMPurify
	// pass still runs so there's no XSS risk.
	// v1.1.4: renderBody wraps the markdown/DOMPurify pipeline with protection for
	// trusted [zdz-video] tokens, then rebuilds them as origin-fixed Vimeo players.
	function renderBody(raw) {
		var src = String(raw || '');
		// Protect [zdz-video]<hex>[/zdz-video] tokens across the transform: swap each
		// for an inert alphanumeric placeholder that marked.js and DOMPurify leave as
		// text, then restore below as a self-built, hex-only placeholder element.
		var videoTokens = [];
		src = src.replace(/\[zdz-video\]([0-9a-fA-F]+)\[\/zdz-video\]/g, function (_, hex) {
			var i = videoTokens.push(hex.toLowerCase()) - 1;
			return VIDEO_PH_OPEN + i + VIDEO_PH_CLOSE;
		});
		// Media-share markers ([ZDZ-MEDIA-SHARE-<group>]) are protected the same way and restored AFTER
		// sanitization as our own gallery-card placeholder. The card is hydrated by wireMediaShares() from an
		// authorized server payload; the marker itself carries no access.
		var mshareTokens = [];
		src = src.replace(/\[ZDZ-MEDIA-SHARE-([a-f0-9]{16,64})\]/gi, function (_, g) {
			var i = mshareTokens.push(g.toLowerCase()) - 1;
			return MSHARE_PH_OPEN + i + MSHARE_PH_CLOSE;
		});
		var out = renderBodyPipeline(src);
		if (mshareTokens.length) {
			out = out.replace(new RegExp(MSHARE_PH_OPEN + '(\\d+)' + MSHARE_PH_CLOSE, 'g'), function (_, i) {
				var g = mshareTokens[+i] || '';
				// Inserted AFTER DOMPurify: safe by construction (a hex group only).
				return /^[a-f0-9]{16,64}$/.test(g)
					? '<div class="zim-w-mshare is-loading" data-zim-mshare="' + g + '" data-zim-state="pending"><span class="zim-w-mshare-load">Loading shared media\u2026</span></div>'
					: '';
			});
		}
		if (videoTokens.length) {
			out = out.replace(new RegExp(VIDEO_PH_OPEN + '(\\d+)' + VIDEO_PH_CLOSE, 'g'), function (_, i) {
				var hex = videoTokens[+i];
				// Inserted AFTER DOMPurify, so it must be safe by construction: a div
				// carrying only a validated [0-9a-f] token. wireVideoEmbeds() turns it
				// into an origin-fixed player; a malformed token yields nothing.
				if (!hex || !/^([0-9a-f]{2})+$/.test(hex)) return '';
				return '<div class="zim-video-embed" data-zim-video-token="' + hex + '"></div>';
			});
		}
		return out;
	}

	// The markdown-lite + DOMPurify transform (unchanged behaviour; wrapped above).
	function renderBodyPipeline(raw) {
		var src = String(raw || '');

		// v1.0.24: If the message body already contains HTML tags, it was
		// pre-rendered by Analytics v1.18.1+ or another plugin. Skip all text→HTML
		// transforms (markdown, @mentions, #NNNNN chips, vault slug conversion)
		// because those regexes assume plain text input and will produce broken
		// output when run against existing HTML (nested <a> tags, matches inside
		// attribute values, etc.). Go straight to DOMPurify.
		if (/<(a |a>|strong|em|ul|ol|li|br|p[ >\/])/i.test(src)) {
			if (window.DOMPurify && typeof window.DOMPurify.sanitize === 'function') {
				return vaultChips(window.DOMPurify.sanitize(src, {
					ADD_ATTR: ['target', 'rel', 'data-zim-ref', 'data-zim-kind', 'data-zim-state', 'data-zim-vault', 'data-zim-vault-slug'],
				}));
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
						if (zimIsAppHost(u.hostname)) {
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
		// "invoice #1234" is the invoice; any other #1234 is the estimate (the two are separate number series in the
		// billing system). The server reads the same words from the stored message (ZIM_Doc_Shares::kind_for_ref).
		html = html.replace(/(^|[^\w#&])#(\d{3,8})(?!\d)/g,   // not "&#105;" (an entity)
			 function (m, pre, num, at, whole) {
			var kind = kindBefore(String(whole).slice(0, at) + pre);
			return pre + '<a href="#" class="zim-w-preview-chip is-loading" data-zim-ref="' + esc(num) + '" data-zim-kind="' + kind + '" data-zim-state="pending">' +
				'<span class="zim-w-preview-number">#' + esc(num) + '</span>' +
				'<span class="zim-w-preview-meta">loading…</span></a>';
		});
		if (window.DOMPurify && typeof window.DOMPurify.sanitize === 'function') {
			html = window.DOMPurify.sanitize(html, {
				ADD_ATTR: ['target', 'rel', 'data-zim-ref', 'data-zim-kind', 'data-zim-state', 'data-zim-vault', 'data-zim-vault-slug'],
			});
		}
		// Vault document cards are built on the parsed, sanitised message (see vaultChips): a regex over HTML would
		// rewrite an address INSIDE a link's href that marked.js had already made.
		return vaultChips(html);
	}

	// ── Knowledge Vault document cards ─────────────────────────
	// Works on the SANITISED message: every link to a vault document, every "<this site>/vault/{slug}" or
	// "/vault/{slug}" written as text, and every [VAULT-{id}] becomes a document card (title, type, who added it --
	// filled in by loadVaultPreviewInto). Elements are built with the DOM API, values set with
	// textContent/setAttribute; the slug is [a-z0-9-] only. /vault/shared and /vault/excerpt/… are pages, not
	// documents, and are left as they are. "This site" is this page's own host plus the business's app domain
	// (zimData.appHosts), so a link written with the app's public address is recognised too.
	function zimAppHosts() {
		var hosts = [];
		try { hosts.push(String(window.location.hostname || '').toLowerCase()); } catch (e) {}
		(Array.isArray(data.appHosts) ? data.appHosts : []).forEach(function (h) {
			h = String(h || '').toLowerCase().replace(/^www\./, '');
			if (/^[a-z0-9.-]{1,253}$/.test(h) && hosts.indexOf(h) < 0) hosts.push(h);
		});
		return hosts.filter(function (h) { return !!h; });
	}
	function zimIsAppHost(hostname) {
		var h = String(hostname || '').toLowerCase().replace(/^www\./, '');
		return zimAppHosts().some(function (x) { return x.replace(/^www\./, '') === h; });
	}
	function zimEscapeRe(s) { return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
	function vaultSlugFromHref(href) {
		try {
			var u = new URL(String(href || ''), window.location.href);
			if (u.origin !== window.location.origin && !zimIsAppHost(u.hostname)) return '';
			var m = u.pathname.match(/^\/vault\/([a-z0-9][a-z0-9-]{0,198})\/?$/);
			if (!m || m[1] === 'shared' || m[1] === 'excerpt' || u.searchParams.has('raw')) return '';
			return m[1];
		} catch (e) { return ''; }
	}
	function makeVaultChip(slug, id, label) {
		var a = document.createElement('a');
		a.setAttribute('href', '#');
		a.className = 'zim-w-preview-chip zim-w-vault-chip is-loading';
		if (id) { a.setAttribute('data-zim-vault', id); } else { a.setAttribute('data-zim-vault-slug', slug); }
		a.setAttribute('data-zim-state', 'pending');
		var num = document.createElement('span');
		num.className = 'zim-w-preview-number';
		num.textContent = '📄 ' + (label || (id ? 'VAULT-' + id : slug));
		var meta = document.createElement('span');
		meta.className = 'zim-w-preview-meta';
		meta.textContent = 'loading…';
		a.appendChild(num);
		a.appendChild(meta);
		return a;
	}
	function vaultChips(html) {
		html = String(html || '');
		if (!/vault/i.test(html) || typeof document === 'undefined' || !document.createElement) return html;
		var tpl = document.createElement('template');
		if (!('content' in tpl)) return html;
		tpl.innerHTML = html;
		var root = tpl.content;
		var hostAlt = zimAppHosts().map(function (h) { return zimEscapeRe(h.replace(/^www\./, '')); }).join('|') || 'localhost';
		var looksLikeAddress = new RegExp('^(https?:|www\\.|\\/vault\\/|(?:' + hostAlt + ')\\/)', 'i');
		Array.prototype.slice.call(root.querySelectorAll('a[href]')).forEach(function (a) {
			if (a.classList.contains('zim-w-preview-chip')) return;
			var slug = vaultSlugFromHref(a.getAttribute('href'));
			if (!slug) return;
			var label = (a.textContent || '').replace(/\s+/g, ' ').trim();
			if (label.length > 120 || looksLikeAddress.test(label)) label = '';
			a.parentNode.replaceChild(makeVaultChip(slug, '', label), a);
		});
		var walker = document.createTreeWalker(root, 4 /* NodeFilter.SHOW_TEXT */, null);
		var texts = [];
		while (walker.nextNode()) texts.push(walker.currentNode);
		var re = new RegExp('\\[VAULT-(\\d{1,6})\\]|(?:https?:\\/\\/)?(?:www\\.)?(?:' + hostAlt + ')\\/vault\\/([a-z0-9][a-z0-9-]{0,198})|(^|[\\s(])\\/vault\\/([a-z0-9][a-z0-9-]{0,198})', 'gi');
		texts.forEach(function (node) {
			var p = node.parentNode;
			if (!p || (p.closest && p.closest('a, code, pre'))) return;
			var text = node.nodeValue;
			re.lastIndex = 0;
			if (!re.test(text)) return;
			re.lastIndex = 0;
			var frag = document.createDocumentFragment();
			var last = 0, m, made = 0;
			while ((m = re.exec(text))) {
				var slug = m[2] || m[4] || '';
				if (!m[1] && (slug === 'shared' || slug === 'excerpt')) continue;
				var lead = m[3] || '';
				frag.appendChild(document.createTextNode(text.slice(last, m.index) + lead));
				frag.appendChild(m[1] ? makeVaultChip('', m[1], '') : makeVaultChip(slug, '', ''));
				last = re.lastIndex;
				made++;
			}
			if (!made) return;
			frag.appendChild(document.createTextNode(text.slice(last)));
			p.replaceChild(frag, node);
		});
		return tpl.innerHTML;
	}

	// ── Shared-media gallery cards ──────────────────────────────
	//
	// A media-share marker (posted by the Media app through the ZIM_Media_Cards bridge) renders as an in-message
	// gallery of the photos and videos the sender granted THIS viewer. Bytes come from the theme's token-gated media
	// proxy and are viewable only because the server recorded a per-recipient grant: the card never widens access.
	// The viewer is self-contained (no dependency on the Media app's JS).
	function wireMediaShares(scope) {
		if (!scope) return;
		var cards = scope.querySelectorAll('.zim-w-mshare[data-zim-state="pending"]');
		for (var i = 0; i < cards.length; i++) { hydrateMshare(cards[i]); }
	}

	function hydrateMshare(el) {
		var group = el.getAttribute('data-zim-mshare') || '';
		if (!group) return;
		el.setAttribute('data-zim-state', 'loading');
		ajax('zim_media_share', { group: group }).then(function (res) {
			var ok = res && res.json && res.json.success;
			var d = ok ? res.json.data : null;
			if (!ok || !d || d.unavailable || !d.items || !d.items.length) {
				renderMshareUnavailable(el, d && d.message);
			} else {
				renderMshareCard(el, group, d);
			}
		}).catch(function () { renderMshareUnavailable(el); });
	}

	function renderMshareUnavailable(el, msg) {
		el.setAttribute('data-zim-state', 'gone');
		el.classList.remove('is-loading');
		el.textContent = msg || 'This shared media is no longer available.';
	}

	function renderMshareCard(el, group, d) {
		el.setAttribute('data-zim-state', 'ready');
		el.classList.remove('is-loading');
		el.textContent = '';
		var items = d.items || [];

		var head = document.createElement('div');
		head.className = 'zim-w-mshare-head';
		head.textContent = (d.shared_by_name || 'A teammate') + ' shared ' + items.length + ' ' + (items.length === 1 ? 'item' : 'items');
		el.appendChild(head);

		if (d.note) {
			var note = document.createElement('div');
			note.className = 'zim-w-mshare-note';
			note.textContent = d.note;
			el.appendChild(note);
		}

		var grid = document.createElement('div');
		grid.className = 'zim-w-mshare-grid';
		items.forEach(function (it, idx) {
			var cell = document.createElement('button');
			cell.type = 'button';
			cell.className = 'zim-w-mshare-cell';
			cell.title = it.title || '';
			var img = document.createElement('img');
			img.loading = 'lazy';
			img.alt = it.title || '';
			img.src = it.thumbnail_url || it.file_url || '';
			img.addEventListener('error', function () { cell.classList.add('zim-w-mshare-cell--broken'); });
			cell.appendChild(img);
			if (it.media_type === 'video') {
				var badge = document.createElement('span');
				badge.className = 'zim-w-mshare-play';
				badge.textContent = '▶';
				cell.appendChild(badge);
			}
			cell.addEventListener('click', function (e) {
				e.stopPropagation();
				openMediaViewer(items, idx, d.shared_by_name || '');
			});
			grid.appendChild(cell);
		});
		el.appendChild(grid);

		if (d.can_revoke) {
			var foot = document.createElement('div');
			foot.className = 'zim-w-mshare-foot';
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'zim-w-mshare-revoke';
			btn.textContent = 'Stop sharing';
			btn.addEventListener('click', function (e) {
				e.stopPropagation();
				if (btn.disabled) return;
				btn.disabled = true; btn.textContent = 'Stopping…';
				ajax('zim_media_unshare', { group: group }, { method: 'POST' }).then(function (res) {
					if (res && res.json && res.json.success) {
						el.setAttribute('data-zim-state', 'revoked');
						el.textContent = 'You stopped sharing this media.';
					} else {
						btn.disabled = false; btn.textContent = 'Stop sharing';
					}
				}).catch(function () { btn.disabled = false; btn.textContent = 'Stop sharing'; });
			});
			foot.appendChild(btn);
			el.appendChild(foot);
		}
	}

	// A minimal, self-contained fullscreen viewer for a shared set: prev/next, slideshow, inline <video>, swipe, and
	// Esc -- no browser dialogs.
	var mshareViewer = null;
	function openMediaViewer(items, startIndex, byName) {
		closeMediaViewer();
		if (!items || !items.length) return;
		var idx = startIndex || 0;
		var timer = null;

		var ov = document.createElement('div');
		ov.className = 'zim-w-mviewer';
		ov.setAttribute('role', 'dialog');
		ov.setAttribute('aria-modal', 'true');

		var stage = document.createElement('div'); stage.className = 'zim-w-mviewer-stage';
		var cap = document.createElement('div'); cap.className = 'zim-w-mviewer-cap';
		var close = document.createElement('button'); close.type = 'button'; close.className = 'zim-w-mviewer-close'; close.setAttribute('aria-label', 'Close'); close.textContent = '✕';
		var prev = document.createElement('button'); prev.type = 'button'; prev.className = 'zim-w-mviewer-nav zim-w-mviewer-prev'; prev.setAttribute('aria-label', 'Previous'); prev.textContent = '‹';
		var next = document.createElement('button'); next.type = 'button'; next.className = 'zim-w-mviewer-nav zim-w-mviewer-next'; next.setAttribute('aria-label', 'Next'); next.textContent = '›';
		var play = document.createElement('button'); play.type = 'button'; play.className = 'zim-w-mviewer-play'; play.textContent = '▶ Slideshow';

		function stopSlide() { if (timer) { clearInterval(timer); timer = null; } play.textContent = '▶ Slideshow'; play.classList.remove('is-playing'); }
		function startSlide() { stopSlide(); timer = setInterval(function () { show(idx + 1, true); }, 4000); play.textContent = '❚❚ Pause'; play.classList.add('is-playing'); }

		function render() {
			stage.textContent = '';
			var it = items[idx];
			if (!it) return;
			if (it.media_type === 'video') {
				stopSlide();
				var v = document.createElement('video');
				v.src = it.file_url || ''; v.controls = true; v.preload = 'metadata';
				v.playsInline = true; v.setAttribute('playsinline', '');
				v.className = 'zim-w-mviewer-media';
				stage.appendChild(v);
			} else {
				var im = document.createElement('img');
				im.src = it.file_url || it.thumbnail_url || ''; im.alt = it.title || '';
				im.className = 'zim-w-mviewer-media';
				im.addEventListener('error', function () { stage.textContent = 'This item is no longer available.'; });
				stage.appendChild(im);
			}
			cap.textContent = (idx + 1) + ' / ' + items.length + (it.title ? ' · ' + it.title : '') + (byName ? ' · from ' + byName : '');
		}
		function show(n, wrap) {
			idx = wrap ? (((n % items.length) + items.length) % items.length) : Math.max(0, Math.min(items.length - 1, n));
			render();
		}

		prev.addEventListener('click', function (e) { e.stopPropagation(); stopSlide(); show(idx - 1, true); });
		next.addEventListener('click', function (e) { e.stopPropagation(); stopSlide(); show(idx + 1, true); });
		close.addEventListener('click', function (e) { e.stopPropagation(); closeMediaViewer(); });
		play.addEventListener('click', function (e) { e.stopPropagation(); if (timer) stopSlide(); else startSlide(); });
		ov.addEventListener('click', function (e) { if (e.target === ov) closeMediaViewer(); });

		var sx = 0;
		stage.addEventListener('touchstart', function (e) { sx = e.changedTouches[0].clientX; }, { passive: true });
		stage.addEventListener('touchend', function (e) { var dx = e.changedTouches[0].clientX - sx; if (Math.abs(dx) > 40) { stopSlide(); show(idx + (dx < 0 ? 1 : -1), true); } }, { passive: true });

		mshareViewer = { el: ov, stop: stopSlide, key: function (e) {
			if (e.key === 'Escape') { closeMediaViewer(); }
			else if (e.key === 'ArrowLeft') { stopSlide(); show(idx - 1, true); }
			else if (e.key === 'ArrowRight') { stopSlide(); show(idx + 1, true); }
		} };
		document.addEventListener('keydown', mshareViewer.key);

		ov.appendChild(close);
		if (items.length > 1) { ov.appendChild(prev); ov.appendChild(next); }
		ov.appendChild(stage);
		var bar = document.createElement('div'); bar.className = 'zim-w-mviewer-bar';
		if (items.length > 1) { bar.appendChild(play); }
		bar.appendChild(cap);
		ov.appendChild(bar);
		document.body.appendChild(ov);
		render();
	}
	function closeMediaViewer() {
		if (!mshareViewer) return;
		try { if (mshareViewer.stop) mshareViewer.stop(); } catch (e) {}
		document.removeEventListener('keydown', mshareViewer.key);
		if (mshareViewer.el && mshareViewer.el.parentNode) { mshareViewer.el.parentNode.removeChild(mshareViewer.el); }
		mshareViewer = null;
	}

	// ── Links inside a message ──────────────────────────────────
	// The mobile messenger is a full page hosted in the Team tab's frame. A plain link that navigated would replace
	// the messenger inside that frame (a same-origin link especially), with no way back. So a real link in a message
	// opens in a new browsing context (window.open, '_blank', no opener) and the messenger stays put. Returns true
	// when the click was on a real link, so the caller stops. mailto:/tel: fall through to normal handling; chips
	// (.zim-w-preview-chip) are excluded and handled by the caller's own logic.
	//
	// A Knowledge Vault document opens INSIDE the app: through the vault's link helper (window.ZKVVaultLinks) or the
	// theme's document layer (Bridge.openDoc), from this window or the app shell above it (the mobile messenger is a
	// frame). A new window would be the plain browser on an iPhone Home Screen app, which is signed out, so the
	// document would not open. Falls back to a new window when neither is present.
	function isVaultDocHref(href) {
		try {
			var u = new URL(String(href || ''), window.location.href);
			if (u.origin !== window.location.origin) return false;
			var m = u.pathname.match(/^\/vault\/([a-z0-9][a-z0-9-]*)\/?$/);
			return !!m && m[1] !== 'shared' && m[1] !== 'excerpt' && !u.searchParams.has('raw');
		} catch (e) { return false; }
	}
	function openVaultDoc(href, title) {
		var wins = [window];
		try { if (window.top && window.top !== window) wins.unshift(window.top); } catch (e) {}
		for (var i = 0; i < wins.length; i++) {
			try {
				var L = wins[i].ZKVVaultLinks;
				if (L && typeof L.open === 'function' && L.open(href, title)) return;
			} catch (e) {}
		}
		for (var j = 0; j < wins.length; j++) {
			try {
				var B = wins[j].Bridge;
				if (B && typeof B.openDoc === 'function' && B.openDoc(href, title)) return;
			} catch (e) {}
		}
		var w = window.open(href, '_blank', 'noopener');
		if (w) { try { w.opener = null; } catch (err) {} }
	}

	function handleMessageLinkClick(e) {
		var a = e.target && e.target.closest && e.target.closest('a:not(.zim-w-preview-chip)');
		if (!a) return false;
		var href = a.getAttribute('href') || '';
		if (!href || href === '#') return false;
		if (isVaultDocHref(href)) {
			e.preventDefault();
			if (e.stopPropagation) { e.stopPropagation(); }
			openVaultDoc(a.href, (a.textContent || '').trim());
			return true;
		}
		if (/^https?:/i.test(href)) {
			e.preventDefault();
			if (e.stopPropagation) { e.stopPropagation(); }
			var w = window.open(href, '_blank', 'noopener');
			if (w) { try { w.opener = null; } catch (err) {} }
		}
		return true;
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

	// The chat docks this page under an answer; the chat says when that dock is scrolled out of view or its view is
	// hidden (an iframe cannot see that from inside -- its own viewport is the frame). While hidden, no poll goes out.
	var zimEmbedHidden = false;
	function elementOnScreen(el) {
		if (zimEmbedHidden) return false;
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
	var zimPollFailures = 0;
	function zimNotePollResult(ok) {
		zimPollFailures = ok ? 0 : Math.min(zimPollFailures + 1, 5);
	}
	// A response is a "failure" for backoff purposes on a 5xx (origin/gateway
	// error) or a thrown/rejected fetch. 4xx (auth/nonce) is NOT a transient
	// origin problem, so it doesn't trigger backoff.
	function zimPollOkFromStatus(status) {
		return !(status >= 500);
	}
	function zimPollDelay(baseMs) {
		// base, ×2, ×4, ×8, ×16, capped — e.g. 10s → 20 → 40 → 80 → 120 (cap).
		var d = baseMs * Math.pow(2, zimPollFailures);
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
			}, zimPollDelay(intervalMs));
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
	function ZIMController(root) {
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
		this.pendingDeepLinkTries = 2; // a deep link waits up to two sidebar reads for its conversation to be listed
		this.searchMode = false;
		this.selectMode = false;   // v1.0.21: bulk-delete select mode
		this.selectedIds = {};     // v1.0.21: { messageId: true }
		this.embedMode = (data.embedMode || '') === 'analytics' || (data.embedMode || '') === 'theme';
		// The one-thread view the chat docks under its answer. Messages draws it itself; the conversation comes from
		// ?c= (the same deep link as ever, opened only when the sidebar lists it, i.e. a member).
		this.compact = !!data.compact;

		this.wireEvents();
		this.setupPreviewObserver();
		this.listenForSWMessages();
		this.listenForParentMessages();
		this.syncPushBtn(); // filled while this device is subscribed
		this.loadDrafts();
		this.initPullToRefresh(); // v1.0.21: Pull-down gesture to soft-refresh
		this.initSwipeToDelete(); // v1.0.21: Swipe-left to delete messages
		// The docked one-thread view has no list to keep fresh: one sidebar read opens its conversation (membership),
		// and only the thread is polled after that.
		if (this.compact) this.openConversationById(this.pendingDeepLink);
		else this.startSidebarPoll();

		// Initial view: show the list (matches iMessage's opening screen).
		// Applies to both embed and standalone modes. At wide viewports the
		// CSS ignores this class, so both panes render side-by-side.
		// The docked one-thread view opens on its conversation.
		if (this.compact) {
			this.showConvoView();
			// one line to reply from under the chat's answer (the full hint is in Messages itself)
			if (this.el.input) { this.el.input.setAttribute('placeholder', 'Reply…'); this.el.input.setAttribute('rows', '1'); }
		} else { this.showListView(); }
	}

	// ── Event wiring ────────────────────────────────────────────
	ZIMController.prototype.wireEvents = function () {
		var self = this;
		this.el.sendBtn && this.el.sendBtn.addEventListener('click', function () { self.sendMessage(); });
		this.shareUntickedBy = {};
		if (this.el.input) {
			// On a phone the return key makes a new line (as in the phone's own messaging app) and the Send button
			// sends; on a computer Enter sends and Shift+Enter makes a new line.
			if (isTouchFirst()) this.el.input.setAttribute('enterkeyhint', 'enter');
			this.el.input.addEventListener('input', function () { self.onInput(); });
			this.el.input.addEventListener('keydown', function (e) { self.onKeydown(e); });
			this.el.input.addEventListener('blur', function () {
				setTimeout(function () { self.closeMention(); }, 120);
			});
			this.buildShareLine();
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
			// A real link in a message opens in a new browsing context (a vault document inside the app) and never
			// navigates the messenger itself (see handleMessageLinkClick). Only chips (.zim-w-preview-chip) get the
			// special handling below.
			if (handleMessageLinkClick(e)) return;

			var chip = e.target.closest && e.target.closest('.zim-w-preview-chip');
			if (chip) {
				// v1.0.21: vault chips with a resolved URL navigate directly on
				// title click. The ⓘ info icon opens the metadata panel instead.
				// Estimate and invoice #NNNN chips open the document panel.
				var vaultId   = chip.getAttribute('data-zim-vault');
				var vaultSlug = chip.getAttribute('data-zim-vault-slug');
				if (vaultId || vaultSlug) {
					// If the user clicked the info icon, open the panel
					var isInfoBtn = e.target.closest && e.target.closest('.zim-w-vault-info');
					if (isInfoBtn) {
						e.preventDefault();
						self.openVaultPreviewPanel(vaultId, vaultSlug);
					} else if (chip.href && chip.href !== '#' && chip.href !== window.location.href) {
						// URL resolved: open the document inside the app (a new window is
						// signed out on a Home Screen app).
						e.preventDefault();
						var numEl = chip.querySelector('.zim-w-preview-number');
						openVaultDoc(chip.href, numEl ? numEl.textContent.replace(/^\uD83D\uDCC4\s*/, '') : '');
					} else {
						// URL not yet resolved — fall back to panel
						e.preventDefault();
						self.openVaultPreviewPanel(vaultId, vaultSlug);
					}
				} else {
					e.preventDefault();
					self.openPreviewPanel(chip.getAttribute('data-zim-ref'), chipMessageId(chip), chip.getAttribute('data-zim-kind') || '');
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
	 * Consistent with Analytics v1.13.7/v1.13.8 implementation. On iOS the
	 * browser chrome disappears when scrolling, leaving no way to trigger
	 * a page refresh. This adds a pull-down gesture at the top of the
	 * messages area that soft-refreshes the sidebar + active conversation.
	 * ═══════════════════════════════════════════════════════════════════ */
	ZIMController.prototype.initPullToRefresh = function () {
		var self = this;
		var msgArea = this.el.messages;
		if (!msgArea) return;

		var startY = 0;
		var pulling = false;
		var indicator = null;
		var threshold = 45; // matches Analytics v1.13.8 reduced threshold

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
	 * in-place without navigating away. Matches Analytics v1.13.8 pattern.
	 */
	ZIMController.prototype.softRefresh = function () {
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

	ZIMController.prototype.showOverlay = function (el) {
		if (el) { el.hidden = false; el.style.display = ''; }
	};
	ZIMController.prototype.hideOverlay = function (el) {
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
	ZIMController.prototype.showConvoView = function () {
		this.root.classList.remove('zim-w--view-list');
		this.root.classList.add('zim-w--view-convo');
	};
	ZIMController.prototype.showListView = function () {
		this.root.classList.remove('zim-w--view-convo');
		this.root.classList.add('zim-w--view-list');
	};

	// ── Sidebar polling ─────────────────────────────────────────
	ZIMController.prototype.startSidebarPoll = function () {
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
				zimNotePollResult(zimPollOkFromStatus(r.status)); // v1.1.1 backoff signal
				if (r.status === 404) { self.hideAll(); return; }
				if (r.json && r.json.success) self.renderSidebar(r.json.data);
			}).catch(function () { self.sidebarFetching = false; zimNotePollResult(false); });
		};
		// v1.1.1 — base 10s (server-provided, filterable), hard floor 8s; backoff
		// stretches this further while the origin is failing.
		this.sidebarPoll = pollable(tick, Math.max(8000, data.pollIntervalMs || 10000));
		this.sidebarPoll.start();
	};

	/** Open a conversation by id, if (and only if) this person's sidebar lists it. */
	ZIMController.prototype.openConversationById = function (cid) {
		var self = this;
		if (!(cid > 0)) return;
		this.pendingDeepLink = cid;
		this.pendingDeepLinkTries = 2;
		ajax('zim_sidebar').then(function (r) {
			if (r.json && r.json.success) {
				self.renderSidebar(r.json.data);
				self.root.classList.remove('zim-w-sidebar-open');
			}
		});
	};

	ZIMController.prototype.hideAll = function () {
		// v1.0.17 — fully destroy the pollable wrappers (clears their
		// internal timers AND removes the visibilitychange listeners they
		// each registered). `stop()` would only do the former, leaving the
		// listeners attached to a torn-down controller; on the next
		// visibilitychange they'd try to restart the polls.
		if (this.sidebarPoll) { this.sidebarPoll.destroy(); this.sidebarPoll = null; }
		if (this.mainPoll)    { this.mainPoll.destroy();    this.mainPoll    = null; }
		this.root.innerHTML = '';
	};

	ZIMController.prototype.renderSidebar = function (payload) {
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
				self.selectConversation({ kind: 'dm', id: d.id, name: d.other_name || 'DM', self: !!d.is_self });
			});
			self.el.dms.appendChild(li);
		});

		if (this.pendingDeepLink > 0) {
			var id = this.pendingDeepLink;
			var match = null;
			channels.forEach(function (c) { if (c.id === id) match = { kind: 'channel', id: c.id, name: c.name }; });
			if (!match) dms.forEach(function (d) { if (d.id === id) match = { kind: 'dm', id: d.id, name: d.other_name, self: !!d.is_self }; });
			// A poll already in flight with the older list (read before the chat's message made the conversation) must
			// not use the link up: it waits for one more read, then is dropped.
			if (match || --this.pendingDeepLinkTries <= 0) this.pendingDeepLink = 0;
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
	ZIMController.prototype.selectConversation = function (conv) {
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

	ZIMController.prototype.startMainPoll = function () {
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
				zimNotePollResult(zimPollOkFromStatus(r.status)); // v1.1.1 backoff signal
				if (!r.json || !r.json.success) return;
				var msgs = r.json.data.messages || [];
				if (!msgs.length) return;
				var wasAtBottom = self.isNearBottom();
				msgs.forEach(function (m) { self.appendMessage(m); });
				self.lastSeenId = r.json.data.latest_id || self.lastSeenId;
				if (wasAtBottom) { self.scrollToBottom(); self.markRead(); }
			}).catch(function () { self.mainFetching = false; zimNotePollResult(false); });
		};
		// v1.1.1 — base 10s (server-provided, filterable), hard floor 8s; backoff
		// stretches this further while the origin is failing.
		this.mainPoll = pollable(tick, Math.max(8000, data.pollIntervalMs || 10000));
		this.mainPoll.start();
	};

	ZIMController.prototype.loadOlder = function () {
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

	ZIMController.prototype.isNearBottom = function () {
		var el = this.el.messages;
		return el.scrollHeight - el.scrollTop - el.clientHeight < 80;
	};
	ZIMController.prototype.scrollToBottom = function () {
		this.el.messages.scrollTop = this.el.messages.scrollHeight;
	};

	ZIMController.prototype.markRead = function () {
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
	ZIMController.prototype.maybeInsertTimeSeparator = function (m, position) {
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

	ZIMController.prototype.appendMessage = function (m) {
		if (this.renderedIds[m.id]) { this.updateMessage(m); return; }
		this.renderedIds[m.id] = true;
		this.maybeInsertTimeSeparator(m, 'append');
		var node = this.renderMessageNode(m);
		this.el.messages.appendChild(node);
		this.observePreviewsIn(node);
	};
	ZIMController.prototype.prependMessage = function (m) {
		if (this.renderedIds[m.id]) return;
		this.renderedIds[m.id] = true;
		var node = this.renderMessageNode(m);
		this.el.messages.insertBefore(node, this.el.messages.firstChild);
		this.observePreviewsIn(node);
	};
	ZIMController.prototype.updateMessage = function (m) {
		var existing = this.el.messages.querySelector('[data-id="' + m.id + '"]');
		if (!existing) return;
		// Never redraw a message while its author is editing it; the save brings the fresh copy.
		if (existing.classList.contains('is-editing')) { existing._zimPending = m; return; }
		var fresh = this.renderMessageNode(m);
		existing.parentNode.replaceChild(fresh, existing);
		this.observePreviewsIn(fresh);
	};

ZIMController.prototype.renderMessageNode = function (m) {
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
		// What the author typed, for Edit - never the drawn text, which has no line breaks and carries the chips' own
		// labels.
		node._zimRaw = m.deleted ? '' : String(m.body_raw != null ? m.body_raw : (m.body || ''));

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
			wireVideoEmbeds(text); // v1.1.4: rebuild any distilled Vimeo chapter embed
			wireMediaShares(text); // hydrate shared-media gallery cards
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
						zimShowLightbox(a.url, a.name || '');
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
		// A message sent from a chat answer links back to that chat -- for its sender only (the server sends from_chat
		// on the viewer's own messages alone; the chat itself is theirs), and only where the app's shell can open it.
		if (isMine && !m.deleted && !this.compact && m.from_chat && (m.from_chat.session_id | 0) > 0 && zimShellBridge()) { // not in the dock: that IS the chat
			var fc = document.createElement('button');
			fc.type = 'button';
			fc.className = 'zim-w-from-chat';
			fc.setAttribute('data-zim-chat-session', String(m.from_chat.session_id | 0));
			fc.textContent = 'From your chat';
			fc.title = 'Open the chat this came from';
			meta.appendChild(fc);
		}

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
	ZIMController.prototype.onInput = function () {
		this.autoGrow();
		this.updateSendEnabled();
		this.maybeOpenMention();
		this.saveDraft();
		this.updateShareLine();
		this.composerError('');
	};

	/* Typing #1234 shows "Share #1234 with Sam (this document only)", ticked: the sender grants access to this one
	   estimate or invoice, for this occasion only, never to the customer's other documents. Untick it and nobody new
	   can open the document from this message. The server shares only numbers that are in the message, only with the
	   other people in this conversation, and only if the sender may see it. */
	var SHARE_RE = /(^|[^\w#&])#(\d{3,8})(?!\d)/g;
	function refsIn(text) {
		var out = [], m;
		SHARE_RE.lastIndex = 0;
		while ((m = SHARE_RE.exec(String(text || ''))) && out.length < 5) {
			var n = String(parseInt(m[2], 10));
			if (n !== '0' && out.indexOf(n) < 0) out.push(n);
		}
		return out;
	}
	ZIMController.prototype.buildShareLine = function () {
		var self = this;
		var row = this.el.composer ? this.el.composer.querySelector('.zim-w-composer-row') : null;
		if (!row || this.el.shareLine) return;
		var lab = document.createElement('label');
		lab.className = 'zim-w-share-line';
		lab.hidden = true;
		var box = document.createElement('input');
		box.type = 'checkbox';
		box.checked = true;
		box.className = 'zim-w-share-check';
		var txt = document.createElement('span');
		txt.className = 'zim-w-share-text';
		lab.appendChild(box);
		lab.appendChild(txt);
		box.addEventListener('change', function () {
			self.shareUnticked = !box.checked;
			if (self.active) self.shareUntickedBy[self.active.id] = self.shareUnticked;   // kept with the draft
		});
		row.parentNode.insertBefore(lab, row);
		var err = document.createElement('p');
		err.className = 'zim-w-composer-error';
		err.setAttribute('role', 'alert');
		err.hidden = true;
		row.parentNode.insertBefore(err, row);
		this.el.shareLine = lab; this.el.shareCheck = box; this.el.shareText = txt; this.el.composerError = err;
		this.shareUnticked = false;
	};
	ZIMController.prototype.shareRefs = function () {
		if (!this.el.input || !this.active || this.active.self || data.isReadOnly || !data.docShares) return [];
		return refsIn(this.el.input.value);
	};
	ZIMController.prototype.updateShareLine = function () {
		if (!this.el.shareLine) return;
		var refs = this.shareRefs();
		if (!refs.length) {
			this.el.shareLine.hidden = true;
			this.shareUnticked = false;   // the next number typed starts ticked again
			if (this.active && this.shareUntickedBy) delete this.shareUntickedBy[this.active.id];
			return;
		}
		var nums = refs.map(function (n) { return '#' + n; });
		var list = nums.length === 1 ? nums[0] : nums.slice(0, -1).join(', ') + ' and ' + nums[nums.length - 1];
		var who = this.active.kind === 'dm' ? (this.active.name || 'them') : 'the people in ' + (this.active.name || 'this conversation');
		this.el.shareText.textContent = 'Share ' + list + ' with ' + who + (nums.length === 1 ? ' (this document only)' : ' (these documents only)');
		this.el.shareCheck.checked = !this.shareUnticked;
		this.el.shareLine.hidden = false;
	};
	ZIMController.prototype.composerError = function (text) {
		var e = this.el.composerError;
		if (!e) return;
		e.textContent = text || '';
		e.hidden = !text;
	};
	ZIMController.prototype.autoGrow = function () {
		var el = this.el.input; if (!el) return;
		el.style.height = 'auto';
		// Room for a few paragraphs before it scrolls.
		el.style.height = Math.min(Math.max(160, Math.round(window.innerHeight * 0.4)), el.scrollHeight) + 'px';
	};
	ZIMController.prototype.updateSendEnabled = function () {
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
	ZIMController.prototype.onKeydown = function (e) {
		if (this.mention.open) {
			if (e.key === 'ArrowDown') { e.preventDefault(); this.moveMention(1); return; }
			if (e.key === 'ArrowUp')   { e.preventDefault(); this.moveMention(-1); return; }
			if (e.key === 'Escape')    { this.closeMention(); return; }
			if (e.key === 'Enter' || e.key === 'Tab') {
				if (this.mention.candidates.length) { e.preventDefault(); this.acceptMention(); return; }
			}
		}
		if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && !isTouchFirst()) { e.preventDefault(); this.sendMessage(); }
	};

	ZIMController.prototype.maybeOpenMention = function () {
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
	ZIMController.prototype.renderMentionPop = function () {
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
	ZIMController.prototype.moveMention = function (dir) {
		var n = this.mention.candidates.length;
		if (!n) return;
		this.mention.active = (this.mention.active + dir + n) % n;
		this.renderMentionPop();
	};
	ZIMController.prototype.acceptMention = function () {
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
	ZIMController.prototype.closeMention = function () {
		this.mention.open = false;
		this.mention.candidates = [];
		if (this.el.mentionPop) { this.el.mentionPop.hidden = true; this.el.mentionPop.style.display = 'none'; }
	};

	ZIMController.prototype.sendMessage = function (opts) {
		var self = this;
		opts = opts || {};
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
		this.composerError('');
		// The numbers to share, when the share line is showing and ticked.
		var share = (!opts.noShare && this.el.shareLine && !this.el.shareLine.hidden && this.el.shareCheck && this.el.shareCheck.checked) ? this.shareRefs() : [];
		ajax('zim_post', {
			conversation_id: this.active.id,
			body: body,
			attachment_ids: atts,
			share_refs: share,
		}, { method: 'POST' }).then(function (r) {
			if (r.json && r.json.success) {
				self.el.input.value = '';
				self.shareUnticked = false;
				if (self.active && self.shareUntickedBy) delete self.shareUntickedBy[self.active.id];
				self.updateShareLine();
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
				// Said under the box, not in a browser dialog; the message stays in the box to try again.
				self.composerError(r.json.data.message);
				self.updateSendEnabled();
			} else {
				self.composerError('It was not sent. Try again.');
				self.updateSendEnabled();
			}
		}).catch(function () { self.composerError('No connection: it was not sent. Try again.'); self.updateSendEnabled(); });
	};

	// ── Edit / delete ──────────────────────────────────────────
	/* Edit opens in the message itself, as a multi-line box filled with exactly what was typed (a one-line prompt
	   filled from the DRAWN text would lose every line break and save a chip's label as part of the message). Enter
	   makes a new line; Save (or Ctrl/Cmd+Enter) saves; Cancel (or Escape) puts the message back as it was. No browser
	   dialog. */
	ZIMController.prototype.beginEdit = function (mid, mEl) {
		var self = this;
		if (!mEl || mEl.classList.contains('is-editing')) return;
		var textEl = mEl.querySelector('.zim-w-msg-text'); if (!textEl) return;
		var raw = typeof mEl._zimRaw === 'string' ? mEl._zimRaw : '';
		mEl.classList.add('is-editing');
		textEl.style.display = 'none';

		var box = document.createElement('div');
		box.className = 'zim-w-edit';
		var ta = document.createElement('textarea');
		ta.className = 'zim-w-edit-input';
		ta.value = raw;
		ta.setAttribute('aria-label', 'Edit message');
		ta.setAttribute('enterkeyhint', 'enter');
		var err = document.createElement('div');
		err.className = 'zim-w-edit-error';
		err.setAttribute('role', 'alert');
		err.hidden = true;
		var row = document.createElement('div');
		row.className = 'zim-w-edit-row';
		var cancel = document.createElement('button');
		cancel.type = 'button'; cancel.className = 'zim-w-edit-cancel'; cancel.textContent = 'Cancel';
		var save = document.createElement('button');
		save.type = 'button'; save.className = 'zim-w-edit-save'; save.textContent = 'Save';
		row.appendChild(cancel); row.appendChild(save);
		box.appendChild(ta); box.appendChild(err); box.appendChild(row);
		textEl.parentNode.insertBefore(box, textEl.nextSibling);

		function grow() { ta.style.height = 'auto'; ta.style.height = Math.min(Math.round(window.innerHeight * 0.5), ta.scrollHeight + 2) + 'px'; }
		function close(fresh) {
			if (box.parentNode) box.parentNode.removeChild(box);
			textEl.style.display = '';
			mEl.classList.remove('is-editing');
			var pending = mEl._zimPending; mEl._zimPending = null;
			if (fresh) {
				mEl._zimRaw = fresh;
				textEl.innerHTML = renderBody(fresh);
				wireVideoEmbeds(textEl); wireMediaShares(textEl);
				self.observePreviewsIn(mEl);
			} else if (pending) {
				self.updateMessage(pending);
			}
		}
		function doSave() {
			var next = ta.value.replace(/\s+$/, '');
			if (!next.trim()) { err.textContent = 'A message cannot be empty. Use delete to remove it.'; err.hidden = false; return; }
			if (next === raw.replace(/\s+$/, '')) { close(null); return; }
			save.disabled = true; cancel.disabled = true; err.hidden = true;
			save.textContent = 'Saving\u2026';
			ajax('zim_edit', { message_id: mid, body: next }, { method: 'POST' }).then(function (r) {
				if (!r.json || !r.json.success) {
					save.disabled = false; cancel.disabled = false; save.textContent = 'Save';
					err.textContent = (r.json && r.json.data && r.json.data.message) || 'The edit was not saved. Try again.';
					err.hidden = false;
					return;
				}
				close(next);
				self.lastSeenId = Math.max(0, mid - 1); // the next poll brings the "(edited)" mark
			}, function () {
				save.disabled = false; cancel.disabled = false; save.textContent = 'Save';
				err.textContent = 'The edit was not saved (no connection). Try again.';
				err.hidden = false;
			});
		}
		ta.addEventListener('input', grow);
		ta.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(null); return; }
			if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); doSave(); }
			e.stopPropagation(); // the composer's Enter-to-send never sees these keys
		});
		cancel.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); close(null); });
		save.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); doSave(); });
		grow();
		ta.focus();
		try { ta.setSelectionRange(ta.value.length, ta.value.length); } catch (ignore) {}
	};
	ZIMController.prototype.confirmDelete = function (mid) {
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

	ZIMController.prototype.toggleSelectMode = function () {
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

	ZIMController.prototype.toggleMessageSelect = function (mid) {
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

	ZIMController.prototype.updateBulkBar = function () {
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

	ZIMController.prototype.bulkDelete = function () {
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

	ZIMController.prototype.initSwipeToDelete = function () {
		var self = this;
		var msgArea = this.el.messages;
		if (!msgArea) return;

		var activeSwipe = null; // { el, startX, startY, swiping }

		msgArea.addEventListener('touchstart', function (e) {
			if (self.selectMode) return; // select mode uses taps, not swipes
			var msg = e.target.closest('.zim-w-msg');
			if (!msg) return;
			// Never while a message is being edited (dragging in the box moves the cursor).
			if (msg.classList.contains('is-editing') || (e.target.closest && e.target.closest('textarea, input'))) return;
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
	ZIMController.prototype.onFilePicked = function () {
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

	ZIMController.prototype.renderAttachChips = function () {
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
	ZIMController.prototype.loadDrafts = function () {
		try { this.drafts = JSON.parse(sessionStorage.getItem(this.draftsKey) || '{}') || {}; }
		catch (e) { this.drafts = {}; }
	};
	ZIMController.prototype.saveDraft = function () {
		if (!this.active || !this.el.input) return;
		this.drafts[this.active.id] = this.el.input.value;
		try { sessionStorage.setItem(this.draftsKey, JSON.stringify(this.drafts)); } catch (e) {}
	};
	ZIMController.prototype.restoreDraft = function () {
		if (!this.active || !this.el.input) return;
		var draft = this.drafts[this.active.id] || '';
		this.el.input.value = draft;
		this.autoGrow();
		this.updateSendEnabled();
		this.shareUnticked = !!(this.shareUntickedBy && this.shareUntickedBy[this.active.id]);
		this.updateShareLine();
	};
	ZIMController.prototype.clearDraft = function () {
		if (!this.active) return;
		delete this.drafts[this.active.id];
		try { sessionStorage.setItem(this.draftsKey, JSON.stringify(this.drafts)); } catch (e) {}
	};

	// ── Search ─────────────────────────────────────────────────
	ZIMController.prototype.toggleSearch = function () {
		if (!this.el.searchBar) return;
		this.searchMode = !this.searchMode;
		this.el.searchBar.hidden = !this.searchMode; this.el.searchBar.style.display = this.searchMode ? '' : 'none';
		if (this.searchMode) { this.el.searchInput.focus(); this.el.searchInput.value = ''; }
		else { this.runSearch(); } // empty = reloads regular view
	};
	ZIMController.prototype.runSearch = function () {
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
	ZIMController.prototype.setupPreviewObserver = function () {
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
					self.loadPreviewInto(chip, ref, chip.getAttribute('data-zim-kind') || '');
				}
			});
		}, { root: this.el.messages, rootMargin: '100px' });
	};
	ZIMController.prototype.observePreviewsIn = function (scope) {
		if (!this.previewObserver) return;
		var chips = scope.querySelectorAll('.zim-w-preview-chip[data-zim-state="pending"]');
		chips.forEach(function (c) { this.previewObserver.observe(c); }, this);
	};
	// The #NNNN card. The server decides, per person and per request, whether this person may see the document (their
	// own right in Estimates, or a share from the message's sender) and sends the document as the billing system has
	// it; the panel draws it with the theme's ZDZ.ui.paper. The chip shows a one-line summary.
	function docKindWord(k) { return k === 'invoice' ? 'Invoice' : (k === 'estimate' ? 'Estimate' : 'Document'); }
	function docMoney(v) {
		var n = Number(v);
		return isFinite(n) ? '$' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '';
	}
	function docDay(iso) {
		var d = iso ? new Date(iso) : null;
		return d && !isNaN(d.getTime()) ? d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '';
	}
	// The words right before a #N, read the way the server reads the stored message (ZIM_Doc_Shares::text_of +
	// INVOICE_WORDS_RE). A line break, paragraph or list item ends what is said; other tags show no words; &nbsp; is a
	// space; the marks * _ ` ~ go. Only spaces and tabs may sit between the word and the number, and "inv." takes the
	// dot where "invoice." does not ("Sent the invoice. #1234 is the estimate" stays the estimate). No look-behind
	// (older iPhones cannot parse one): the word must start the text or follow a non-letter.
	var INVOICE_WORDS = /(?:^|[^a-z0-9])(?:invoice|inv\.?)(?:[ \t]*(?:no\.?|num(?:ber)?\.?))?[ \t]*[:\-\u2013]?[ \t]*$/i;
	var kindTa = null;
	function kindBefore(html) {
		var t = String(html || '')
			.replace(/\r\n?/g, '\n')
			.replace(/<\/?(?:p|div|li|ul|ol|br|h[1-6]|blockquote|pre|table|tr|td|th|hr)\b[^>]*>/gi, '\n')
			.replace(/<\/?[a-z][^>]*>/gi, '');   // inline tags go, as the server takes out * _ ` ~ ("*inv*. #N" = "inv. #N")
		// Every entity decoded as html_entity_decode does (a textarea holds text only: nothing in it is parsed as markup).
		try { kindTa = kindTa || document.createElement('textarea'); kindTa.innerHTML = t; t = kindTa.value; } catch (e) {}
		t = t.replace(/[\x00-\x08\x0B\x0C\x0E-\x1F]/g, '').replace(/\u00a0/g, ' ').replace(/[*_`~\[\]]/g, '');
		return INVOICE_WORDS.test(t) ? 'invoice' : 'estimate';
	}
	// A not-found answer is kept five minutes (the billing system may catch up on a new document); the person's own
	// document for the visit.
	var NOT_FOUND_KEEP_MS = 5 * 60 * 1000;
	function cachedDoc(cache, key) {
		var c = cache[key];
		if (c && c._until && Date.now() > c._until) { delete cache[key]; return null; }
		return c || null;
	}
	function keepDoc(cache, key, card) {
		if (card.state === 'not_found') { var k = Object.assign({}, card, { _until: Date.now() + NOT_FOUND_KEEP_MS }); delete k.share; cache[key] = k; }
		else if (card.state === 'ok' && card.via === 'own') { var o = Object.assign({}, card); delete o.share; cache[key] = o; }
	}
	function chipMessageId(chip) {
		var m = chip && chip.closest ? chip.closest('.zim-w-msg') : null;
		return m ? (parseInt(m.getAttribute('data-id'), 10) || 0) : 0;
	}
	ZIMController.prototype.loadPreviewInto = function (chip, ref, kind) {
		var self = this;
		kind = kind === 'invoice' ? 'invoice' : 'estimate';
		ref = String(ref || '');
		if (!/^\d{1,8}$/.test(ref)) return;   // only digits reach the selector below
		var key = ref + '|' + kind;   // estimate #1234 and invoice #1234 are different documents
		var hit = cachedDoc(this.previewCache, key);
		if (hit) { this.applyPreview(chip, hit); return; }
		this._chipLoads = this._chipLoads || {};
		if (this._chipLoads[key]) return;   // one request per document; it fills every chip for it
		this._chipLoads[key] = true;
		this._chipTries = this._chipTries || {};
		var chipsFor = function () {
			return Array.prototype.slice.call(document.querySelectorAll('.zim-w-preview-chip[data-zim-ref="' + ref + '"]'))
				.filter(function (c) { return (c.getAttribute('data-zim-kind') === 'invoice' ? 'invoice' : 'estimate') === kind; });
		};
		// Every chip for this number goes back to waiting, and is watched again only after a pause that grows
		// (15 s, 30 s, … 2 min) -- a chip on screen would otherwise ask again at once, over and over. A refusal
		// (signed out, 4xx) is not retried by itself: the chip says "tap to open" and a tap asks again.
		var retry = function (status) {
			var tries = self._chipTries[key] = (self._chipTries[key] || 0) + 1;
			chipsFor().forEach(function (c) {
				if (c.getAttribute('data-zim-state') !== 'loading') return;
				if (status >= 400 && status < 500) {
					c.classList.remove('is-loading');
					c.setAttribute('data-zim-state', 'loaded');
					var mt = c.querySelector('.zim-w-preview-meta'); if (mt) mt.textContent = 'tap to open';
					return;
				}
				c.setAttribute('data-zim-state', 'pending');
			});
			if (status >= 400 && status < 500) return;
			setTimeout(function () {
				if (!self.previewObserver) return;
				chipsFor().forEach(function (c) { if (c.getAttribute('data-zim-state') === 'pending') self.previewObserver.observe(c); });
			}, Math.min(120000, 15000 * Math.pow(2, tries - 1)));
		};
		// No message_id: the chip already carries the kind, and the sender's share status is for the panel only.
		ajax('zim_preview_ref', { number: ref, kind: kind }).then(function (r) {
			delete self._chipLoads[key];
			if (!r.json || !r.json.success) { retry(r.status || 0); return; }
			delete self._chipTries[key];
			var card = r.json.data;
			// Kept only when it cannot change under the person: their own document, or one the billing system does
			// not have. Not a passing failure, not a shared answer (the sender can withdraw it), and not a refusal (the
			// sender may share it a minute later) -- those are asked again next time.
			keepDoc(self.previewCache, key, card);
			chipsFor().forEach(function (c) { self.applyPreview(c, card); });
		}).catch(function () { delete self._chipLoads[key]; retry(0); });
	};
	ZIMController.prototype.applyPreview = function (chip, card) {
		chip.classList.remove('is-loading');
		chip.setAttribute('data-zim-state', 'loaded');
		chip.href = '#';
		chip.removeAttribute('target');
		var meta = chip.querySelector('.zim-w-preview-meta');
		if (!meta) return;
		chip.classList.toggle('is-denied', card.state === 'denied');
		if (card.state === 'ok' && card.doc) {
			var d = card.doc, parts = [docKindWord(card.kind || d.kind)];
			if (d.customer) parts.push(d.customer);
			if (d.money && d.totals && d.totals.total != null) parts.push(docMoney(d.totals.total));
			if (d.status) parts.push(d.status);
			if (card.via === 'shared') parts.push('shared with you');
			meta.textContent = parts.join(' · ');
		} else if (card.state === 'denied') {
			// When the person's own Estimates access is off, "someone else's" would be wrong: the panel says why.
			meta.textContent = (card.why === 'no_access' || card.why === 'safe_mode') ? 'not shared with you' : 'belongs to someone else';
		} else if (card.state === 'not_found') {
			meta.textContent = 'not found';
		} else if (card.state === 'unavailable') {
			meta.textContent = 'tap to open';
		} else {
			meta.textContent = 'open';   // the plain preview card's answer (no document reader)
		}
	};
	// Without the theme's paper (an older theme), the same facts as plain text.
	function plainPaper(doc) {
		var box = document.createElement('div');
		box.className = 'zim-w-paper-plain';
		var add = function (tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; n.textContent = text; box.appendChild(n); return n; };
		add('div', 'zim-w-paper-plain-head', docKindWord(doc.kind) + ' #' + (doc.number || ''));
		(doc.billed_to || []).forEach(function (x) { add('div', '', String(x)); });
		(doc.lines || []).forEach(function (l) {
			var t = String(l.name || '') + (l.description ? '\n' + l.description : '');
			if (doc.money && l.amount != null) t += '\n' + (l.qty != null ? l.qty + ' × ' : '') + docMoney(l.rate) + ' = ' + docMoney(l.amount);
			add('div', 'zim-w-paper-plain-line', t);
		});
		if (doc.money && doc.totals && doc.totals.total != null) add('div', 'zim-w-paper-plain-total', 'Total ' + docMoney(doc.totals.total));
		if (doc.notes) add('div', 'zim-w-paper-plain-line', doc.notes);
		return box;
	}
	ZIMController.prototype.openPreviewPanel = function (ref, messageId, kind) {
		var self = this;
		kind = kind === 'invoice' ? 'invoice' : 'estimate';   // the words in the message decide; no word = estimate
		ref = String(ref || '');
		if (!/^\d{1,8}$/.test(ref)) return;   // only digits reach a selector
		var key = ref + '|' + kind;
		var body = this.el.previewBody;
		var card = this.el.previewPanel ? this.el.previewPanel.querySelector('.zim-w-overlay-card') : null;
		var title = this.el.previewTitle;
		this.showOverlay(this.el.previewPanel);
		title.textContent = '#' + ref;
		if (card) card.setAttribute('aria-label', '#' + ref);
		var seq = this._paperSeq = (this._paperSeq || 0) + 1;
		var loading = function () {
			body.innerHTML = '';
			if (window.ZDZ && ZDZ.ui && ZDZ.ui.loading) ZDZ.ui.loading(body, 'Opening #' + ref + '…');
			else body.innerHTML = '<div class="zim-w-empty">Loading…</div>';
		};
		var line = function (cls, text) { var p = document.createElement('p'); p.className = cls; p.textContent = text; return p; };
		var button = function (text, cls, fn) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'zdz-btn zdz-btn--sm ' + (cls || 'zdz-btn--secondary');
			b.textContent = text;
			b.addEventListener('click', fn);
			return b;
		};
		var listNums = function (nums) {
			nums = (nums || []).map(function (n) { return '#' + n; });
			return nums.length < 2 ? (nums[0] || '') : nums.slice(0, -1).join(', ') + ' and ' + nums[nums.length - 1];
		};
		// The sender's own controls: what this message shares, and the ways to share more or stop.
		var shareBox = function (share) {
			if (!share) return null;
			var wrap = document.createElement('div');
			wrap.className = 'zim-w-share-box';
			var err = line('zim-w-share-error', '');
			err.setAttribute('role', 'alert');
			var act = function (action, b, extra) {
				var go = function () {
					return ajax(action, Object.assign({ message_id: share.message_id }, extra || {}), { method: 'POST' }).then(function (r) {
						if (r.json && r.json.success) {
							(r.json.data.share.numbers || []).concat([ref]).forEach(function (n) { delete self.previewCache[n + '|estimate']; delete self.previewCache[n + '|invoice']; });
							draw(r.json.data.share);
							return;
						}
						err.textContent = (r.json && r.json.data && r.json.data.message) || 'That did not work. Try again.';
					}).catch(function () { err.textContent = 'No connection. Try again.'; });
				};
				if (window.ZDZ && ZDZ.ui && ZDZ.ui.busy) ZDZ.ui.busy(b, go); else go();
			};
			var draw = function (s) {
				share = s || share;
				wrap.innerHTML = '';
				err.textContent = '';
				var anything = false;
				if (share.active) {
					anything = true;
					wrap.appendChild(line('zim-w-share-status', 'Shared ' + listNums(share.numbers) + ' with ' + (share.names || []).join(', ') +
						' — this document only, read only' + (share.until ? ', until ' + docDay(share.until) : '') + '.'));
					if (share.pending && share.pending.length) {
						var more = button('Share ' + listNums(share.pending) + ' too', 'zdz-btn--secondary', function () { act('zim_share_ref', more); });
						wrap.appendChild(more);
					}
					var stop = button('Stop sharing', 'zdz-btn--secondary', function () { act('zim_unshare_ref', stop); });
					wrap.appendChild(stop);
				} else if (share.can_share) {
					anything = true;
					wrap.appendChild(line('zim-w-share-status', 'Only you and people who may already see it can open it here.'));
					var go = button('Share with this conversation', 'zdz-btn--primary', function () { act('zim_share_ref', go); });
					wrap.appendChild(go);
					wrap.appendChild(line('zim-w-share-hint', 'They will see this document only, read only, for 30 days.'));
				}
				wrap.hidden = !anything;
				wrap.appendChild(err);
			};
			draw(share);
			return wrap;
		};
		var render = function (c) {
			if (seq !== self._paperSeq) return;   // another chip was opened meanwhile
			body.innerHTML = '';
			if (card) card.classList.toggle('is-paper', c.state === 'ok');
			if (c.state === 'ok' && c.doc) {
				var name = docKindWord(c.kind || c.doc.kind) + ' #' + (c.doc.number || ref);
				title.textContent = name;
				if (card) card.setAttribute('aria-label', name);
				var note = c.via === 'shared'
					? 'Shared with you by ' + (c.shared_by || 'the sender') + ' — read only' + (c.until ? ', until ' + docDay(c.until) : '') + '.'
					: 'Read only. Changes are made in Estimates.';
				var paper = (window.ZDZ && ZDZ.ui && typeof ZDZ.ui.paper === 'function')
					? ZDZ.ui.paper(c.doc, { note: note, label: name })
					: (function () { var f = document.createDocumentFragment(); f.appendChild(line('zim-w-paper-note', note)); f.appendChild(plainPaper(c.doc)); return f; })();
				var sb = shareBox(c.share);
				if (sb) body.appendChild(sb);
				body.appendChild(paper);
				return;
			}
			if (c.state === 'denied' || c.state === 'not_found') {
				body.appendChild(line('zim-w-paper-msg', c.message || 'This document belongs to someone else. Ask the sender to share it.'));
				var sb2 = shareBox(c.share);
				if (sb2) body.appendChild(sb2);
				return;
			}
			if (c.state === 'unavailable') {
				body.appendChild(line('zim-w-paper-msg', c.message || 'It could not be opened just now.'));
				body.appendChild(button('Try again', 'zdz-btn--secondary', function () { load(); }));
				return;
			}
			// The plain preview card's answer (no document reader): say so plainly.
			body.appendChild(line('zim-w-paper-msg', 'This document cannot be shown here yet.'));
		};
		var load = function () {
			loading();
			ajax('zim_preview_ref', { number: ref, message_id: messageId || 0, kind: kind }).then(function (r) {
				if (!r.json || !r.json.success) {
					render({ state: 'unavailable', message: (r.json && r.json.data && r.json.data.message) || 'It could not be opened just now.' });
					return;
				}
				var c = r.json.data;
				keepDoc(self.previewCache, key, c);
				// The chips for this document say what the panel found (also where they were never in view).
				document.querySelectorAll('.zim-w-preview-chip[data-zim-ref="' + ref + '"]').forEach(function (ch) {
					if ((ch.getAttribute('data-zim-kind') === 'invoice' ? 'invoice' : 'estimate') === kind) self.applyPreview(ch, c);
				});
				render(c);
				// Focus moves into the panel, to its title, for keyboard and screen-reader users.
				if (seq === self._paperSeq && title) { title.setAttribute('tabindex', '-1'); try { title.focus({ preventScroll: true }); } catch (e) { /* older browsers */ } }
			}).catch(function () { render({ state: 'unavailable', message: 'No connection. Try again.' }); });
		};
		load();
	};

	// ── v1.0.20: Knowledge Vault preview cards ─────────────────
	ZIMController.prototype.loadVaultPreviewInto = function (chip, vaultId, vaultSlug) {
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
	ZIMController.prototype.applyVaultPreview = function (chip, card) {
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
	ZIMController.prototype.openVaultPreviewPanel = function (vaultId, vaultSlug) {
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
			// "Open in Vault" opens inside the app too.
			var openLink = self.el.previewBody.querySelector('a[href]');
			if (openLink && isVaultDocHref(openLink.getAttribute('href'))) {
				openLink.addEventListener('click', function (ev) {
					ev.preventDefault();
					openVaultDoc(openLink.href, card.title || '');
				});
			}
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
	ZIMController.prototype.createChannel = function () {
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
	ZIMController.prototype.openNewDm = function () {
		// v1.0.24 — Read-only roles cannot start DMs. The affordance is hidden
		// for them; guard the method too so nothing can open the picker.
		if (data.isReadOnly) return;
		this.showOverlay(this.el.dmModal);
		if (this.el.dmSearch) { this.el.dmSearch.value = ''; this.el.dmSearch.focus(); }
		if (this.el.dmCandidates) this.el.dmCandidates.innerHTML = '';
		this.searchDmCandidates();
	};
	ZIMController.prototype.searchDmCandidates = function () {
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
	ZIMController.prototype.saveQuietHours = function () {
		var start = this.el.quietStart.value;
		var end = this.el.quietEnd.value;
		ajax('zim_set_quiet_hours', { start: start, end: end }, { method: 'POST' }).then(function (r) {
			if (!r.json || !r.json.success) {
				alert((r.json && r.json.data && r.json.data.message) || 'Could not save quiet hours.');
			}
		});
	};

	// ── Push subscription ───────────────────────────────────────

	/** The theme's shared push routine (ZDZPush), here or in the app around this frame; null outside the app. */
	function sharedPush() {
		try { if (window.ZDZPush && window.ZDZPush.enable) return window.ZDZPush; } catch (e) { /* none */ }
		try {
			if (window.parent && window.parent !== window && window.parent.ZDZPush && window.parent.ZDZPush.enable) return window.parent.ZDZPush;
		} catch (e) { /* another origin: not ours */ }
		return null;
	}

	/** The bell drawn filled (and pressed) while this device gets Messages; outline otherwise. */
	ZIMController.prototype.paintPushBtn = function (on) {
		var b = this.el.pushBtn;
		if (!b) return;
		b.classList.toggle('is-on', !!on);
		b.setAttribute('aria-pressed', on ? 'true' : 'false');
		var t = on ? 'Notifications on for this device (tap to turn off)' : 'Turn on notifications';
		b.setAttribute('title', t);
		b.setAttribute('aria-label', t);
	};

	ZIMController.prototype.syncPushBtn = function () {
		var self = this, P = sharedPush();
		if (!P || !this.el.pushBtn) return;
		if (data.isReadOnly) { this.el.pushBtn.hidden = true; return; } // the shared device gets no push
		P.status().then(function (st) { self.paintPushBtn(P.channelOn(st, 'messages')); }, function () { /* leave it as drawn */ });
		if (!this._pushListen) {
			this._pushListen = true;
			try {
				var host = (P === window.ZDZPush) ? window : window.parent;
				host.addEventListener('zdz-push-change', function (ev) {
					var st = ev && ev.detail;
					if (st) self.paintPushBtn(P.channelOn(st, 'messages'));
				});
			} catch (e) { /* no events: the next open repaints */ }
		}
	};

	ZIMController.prototype.promptPush = function () {
		var self = this;
		// Inside the app, the one shared routine (every channel, the same state Settings shows).
		var P = sharedPush();
		if (P) {
			var on = this.el.pushBtn && this.el.pushBtn.classList.contains('is-on');
			// Ask here, inside this tap: iOS refuses a permission prompt that is not from a tap, and this frame has it.
			var ask = (!on && 'Notification' in window && Notification.permission === 'default')
				? Notification.requestPermission() : Promise.resolve();
			ask.then(function () {
				return on ? P.disable({ toast: true, source: 'messages' }) : P.enable({ toast: true, source: 'messages' });
			}).then(function (st) {
				if (st) self.paintPushBtn(P.channelOn(st, 'messages'));
			}, function () { self.syncPushBtn(); });
			return;
		}
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
			console.error('[Messaging] push setup failed', err);
			alert('Could not enable notifications.');
		});
	};

	ZIMController.prototype.listenForSWMessages = function () {
		var self = this;
		if (!('serviceWorker' in navigator)) return;
		navigator.serviceWorker.addEventListener('message', function (event) {
			if (!event.data) return;
			if (event.data.type === 'zim-push-resubscribe') {
				// Heal without a prompt (the theme's routine; it also listens itself). No tap here, so never a
				// permission prompt.
				var P = sharedPush();
				if (P && P.heal) P.heal();
			} else if (event.data.type === 'zim-open-conversation') {
				var cid = event.data.conversation_id | 0;
				if (cid > 0) {
					// Force a sidebar reload then select.
					ajax('zim_sidebar').then(function (r) {
						if (r.json && r.json.success) {
							self.renderSidebar(r.json.data);
							var m = null;
							(r.json.data.channels || []).forEach(function (c) { if (c.id === cid) m = { kind:'channel', id:c.id, name:c.name }; });
							(r.json.data.dms || []).forEach(function (d)     { if (d.id === cid) m = { kind:'dm',      id:d.id, name:d.other_name, self: !!d.is_self }; });
							if (m) self.selectConversation(m);
						}
					});
				}
			}
		});
	};

	/**
	 * Listen for postMessages from the parent window (Analytics embed).
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
	ZIMController.prototype.listenForParentMessages = function () {
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
			// "Open in Messages" under the chat's docked conversation opens that thread in the Team panel. Only a
			// conversation the sidebar lists (one this person belongs to) is opened; anything else is ignored.
			if (msg.type === 'zim-embed-open-conversation') {
				self.openConversationById(msg.conversation_id | 0);
			}
			// The chat's dock scrolled out of view, or back (no polls while out of view).
			if (msg.type === 'zim-embed-visibility') {
				zimEmbedHidden = msg.visible === false;
			}
			// Share text from the chat's vault share button.
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
	ZIMController.prototype.acceptDmRoute = function (msg) {
		var self = this;
		// v1.0.24 — On a read-only shared device, refuse the Analytics embed's
		// DM-route / auto-send instruction outright. This is the client-side
		// half of the messaging lockdown: even if an upstream assistant emitted
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
					if (self.el.input) { self.el.input.value = p.body; self.updateShareLine(); }
					self.pendingEmbedAutoSend = null;
					// v1.0.19: Acknowledge pre-fill to parent so it can clear the analytics input
					ackEmbed('prefilled', p.body);
				}
				return;
			}
			self.el.input.value = p.body;
			self.updateSendEnabled();
			// A message sent for someone by another app never shares a document by itself.
			self.sendMessage({ noShare: true });
			self.pendingEmbedAutoSend = null;
			// v1.0.19: Acknowledge delivery to parent
			ackEmbed('sent', p.body);
		}, 100);
	}

	/**
	 * v1.0.19: Send acknowledgment to parent window (Analytics embed).
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
