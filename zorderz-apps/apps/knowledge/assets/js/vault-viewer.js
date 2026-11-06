/**
 * Knowledge — open vault links INSIDE the app.
 *
 * Any click on a same-site link to a vault document (/vault/{slug}) — the Vault list's
 * Open button, a link in a chat answer or a message, a document card in Messages — opens
 * the document in the theme's in-app document layer (Bridge.openDoc) when the theme has
 * one. That layer is a frame on this site, so it runs with the app's own sign-in; a new
 * window does not (on a phone's Home Screen web app it is the browser, which is signed
 * out). Back returns to where you were.
 *
 * Runs in the capture phase on window, so it sees the click before any widget's own
 * handler (some of which call window.open themselves). Cmd/Ctrl/Shift/middle-click and
 * links marked `download` are left alone, as is the details button on a Messages document
 * card. When the page is a frame inside the app, the TOP window's layer is used. Without
 * an openDoc layer the document opens in a new window — that page is the viewer too.
 *
 * The contract other apps and the theme use: window.ZKVVaultLinks = { open(href, title), slugOf(href) }. open()
 * answers false for a link that is not a vault document, so a caller can try its own way next; the theme's
 * Bridge.openDoc() hands vault links here first.
 */
(function () {
	'use strict';
	if (window.ZKVVaultLinks) { return; }

	var RESERVED = { shared: 1, excerpt: 1 };

	function parse(href) {
		try { return new URL(String(href || ''), window.location.href); } catch (e) { return null; }
	}

	/** The document slug when href is a same-site vault DOCUMENT link, else ''. */
	function slugOf(href) {
		var u = parse(href);
		if (!u || u.origin !== window.location.origin) { return ''; }
		var m = u.pathname.match(/^\/vault\/([a-z0-9][a-z0-9-]*)\/?$/);
		if (!m || RESERVED[m[1]]) { return ''; }
		if (u.searchParams.has('raw') || u.searchParams.has('download')) { return ''; }
		return m[1];
	}

	/** The app shell's Bridge with openDoc: this window's, or the top window's when framed. */
	function shell() {
		var wins = [];
		try { if (window.top && window.top !== window) { wins.push(window.top); } } catch (e) {}
		wins.push(window);
		for (var i = 0; i < wins.length; i++) {
			try {
				var B = wins[i].Bridge;
				if (B && typeof B.openDoc === 'function') { return B; }
			} catch (e) { /* cross-origin top: not ours */ }
		}
		return null;
	}

	function open(href, title) {
		var slug = slugOf(href);
		if (!slug) { return false; }
		var url = window.location.origin + '/vault/' + slug;
		var B = shell();
		if (B && B.openDoc(url, title || '', { download: url + '?raw=1&dl=1', fromVault: true })) { return true; }
		var w = window.open(url, '_blank', 'noopener');
		if (w) { try { w.opener = null; } catch (e) {} }
		return true;
	}

	/** A readable title from the link itself (the page's own title replaces it on load). */
	function titleOf(a) {
		var el = a.querySelector && a.querySelector('.zim-w-preview-number');
		var t = ((el || a).textContent || '').replace(/[\u{1F4C4}ⓘ]/gu, '').replace(/\s+/g, ' ').trim();
		if (!t || t.length > 120 || /^(https?:|www\.|\/vault\/)/i.test(t) || t.indexOf(window.location.host + '/') === 0) { return ''; }
		return t;
	}

	window.addEventListener('click', function (e) {
		if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
		var t = e.target;
		if (!t || !t.closest) { return; }
		if (t.closest('.zim-w-vault-info')) { return; } // Messages' ⓘ opens its details panel
		var a = t.closest('a[href]');
		if (!a || a.hasAttribute('download')) { return; }
		if (!slugOf(a.getAttribute('href'))) { return; }
		e.preventDefault();
		e.stopPropagation();
		if (e.stopImmediatePropagation) { e.stopImmediatePropagation(); }
		open(a.href, titleOf(a));
	}, true);

	window.ZKVVaultLinks = { open: open, slugOf: slugOf };
})();
