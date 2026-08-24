/**
 * Zorderz Analytics — the Chat surface.
 *
 * Injects the permanent bottom "Chat" nav item and its sub-view (the theme marks
 * the spot in front-page.php), and exposes window.ZanaChat.open() so the KPI tiles
 * and the digest deep-link land here. Talks to the REST routes under zorderz/v1.
 *
 * No company/person/product name anywhere; every label comes from zanaChat.i18n.
 */
(function () {
  'use strict';

  var CFG = window.zanaChat || {};
  var I18N = CFG.i18n || {};
  var state = { sessionId: 0, sending: false, booted: false };

  function h(tag, cls, html) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (html != null) e.innerHTML = html;
    return e;
  }

  function api(path, opts) {
    opts = opts || {};
    opts.headers = Object.assign({ 'X-WP-Nonce': CFG.nonce || '', 'Content-Type': 'application/json' }, opts.headers || {});
    opts.credentials = 'same-origin';
    return fetch((CFG.apiUrl || '') + path, opts).then(function (r) { return r.json(); });
  }

  // ── Nav item ────────────────────────────────────────────────────────────
  function ensureNav() {
    var nav = document.querySelector('nav.bnav');
    if (!nav || nav.querySelector('.ni-chat')) return;
    var btn = h('button', 'ni ni-chat', '<i data-lucide="message-circle"></i><span class="ni-label">' + esc(I18N.title || 'Chat') + '</span>');
    btn.setAttribute('data-view', 'sv-chat');
    btn.setAttribute('aria-label', esc(I18N.title || 'Chat'));
    btn.addEventListener('click', function () { showChat(); });
    nav.appendChild(btn);
    if (window.lucide && typeof lucide.createIcons === 'function') { try { lucide.createIcons(); } catch (e) {} }
  }

  // ── Sub-view ────────────────────────────────────────────────────────────
  function ensureSubview() {
    if (document.getElementById('sv-chat')) return document.getElementById('sv-chat');
    var host = document.querySelector('.app-shell') || document.body;
    var sv = h('div', 'sub-view zana-chat');
    sv.id = 'sv-chat';
    sv.setAttribute('role', 'main');
    sv.innerHTML =
      '<div class="zana-head"><h4>' + esc(I18N.title || 'Chat') + '</h4>' +
      (CFG.isKiosk ? '<span class="zana-kiosk-note">' + esc(I18N.kioskNote || '') + '</span>' : '') +
      '</div>' +
      '<div class="zana-messages" id="zana-messages"><p class="zana-empty">' + esc(I18N.empty || '') + '</p></div>' +
      '<form class="zana-form" id="zana-form">' +
      '<textarea id="zana-input" rows="1" placeholder="' + esc(I18N.placeholder || '') + '"></textarea>' +
      '<button type="submit" class="zana-send">' + esc(I18N.send || 'Send') + '</button>' +
      '</form>';
    host.appendChild(sv);

    sv.querySelector('#zana-form').addEventListener('submit', function (ev) {
      ev.preventDefault();
      var ta = document.getElementById('zana-input');
      var text = (ta.value || '').trim();
      if (text) { ta.value = ''; send(text); }
    });
    return sv;
  }

  function showChat() {
    ensureSubview();
    document.querySelectorAll('.sub-view').forEach(function (v) { v.classList.remove('active'); });
    document.querySelectorAll('.bnav .ni').forEach(function (n) { n.classList.remove('active'); });
    var sv = document.getElementById('sv-chat');
    if (sv) sv.classList.add('active');
    var btn = document.querySelector('.ni-chat');
    if (btn) btn.classList.add('active');
  }

  // ── Public entry point (KPI tiles / digest deep-link route here) ─────────
  function open(options) {
    options = options || {};
    showChat();
    if (options.session) { loadSession(parseInt(options.session, 10)); }
    if (options.prompt) {
      var ta = document.getElementById('zana-input');
      if (ta) { ta.value = options.prompt; }
      send(String(options.prompt));
    }
  }

  function loadSession(id) {
    if (!id) return;
    state.sessionId = id;
    api('/session/' + id).then(function (res) {
      var box = document.getElementById('zana-messages');
      if (!box) return;
      box.innerHTML = '';
      (res.messages || []).forEach(function (m) { appendMsg(m.role, m.body, m); });
    }).catch(function () {});
  }

  function send(text) {
    if (state.sending) return;
    state.sending = true;
    appendMsg('user', text, {});
    var pending = appendMsg('assistant', I18N.thinking || '…', { pending: true });
    // Prefer the async (enqueue + poll) path so a slow turn can't 502; fall back to
    // the synchronous /chat route whenever async is disabled or unavailable. Kiosk
    // (shared device) always uses the sync route — its turns are never persisted, and
    // the async path would need a job row (see ZANA_Background::enqueue).
    if (CFG.async && !CFG.isKiosk) {
      sendAsync(text, pending);
    } else {
      sendSync(text, pending);
    }
  }

  // Replace the thinking bubble with the final answer and release the lock.
  function finish(pending, body, meta) {
    state.sending = false;
    if (pending && pending.parentNode) pending.parentNode.removeChild(pending);
    appendMsg('assistant', body, meta || {});
  }

  // The original synchronous turn — one request holds open for the whole model call.
  // Kept intact as the fallback path.
  function sendSync(text, pending) {
    api('/chat', {
      method: 'POST',
      body: JSON.stringify({ message: text, session_id: state.sessionId || 0 })
    }).then(function (res) {
      if (res && res.session_id) state.sessionId = res.session_id;
      finish(pending, (res && res.answer) || '…', res || {});
    }).catch(function () {
      finish(pending, I18N.error || '…', { verdict: 'refuse' });
    });
  }

  // Async turn: enqueue, then poll for status. Any failure to enqueue falls back to
  // the sync path so behaviour never regresses.
  function sendAsync(text, pending) {
    api('/chat/enqueue', {
      method: 'POST',
      body: JSON.stringify({ message: text, session_id: state.sessionId || 0 })
    }).then(function (res) {
      if (!res || res.ok === false || !res.job) {
        // Async unavailable (e.g. 501) or refused — use the sync route instead.
        return sendSync(text, pending);
      }
      pollTurn(res.job, pending, Date.now());
    }).catch(function () {
      // Network / route error on enqueue — use the sync route instead.
      sendSync(text, pending);
    });
  }

  function pollTurn(job, pending, startedAt) {
    var everyMs = CFG.pollMs || 1500;
    var maxMs = CFG.maxPollMs || 180000;
    api('/turn/' + job).then(function (res) {
      if (!res || res.ok === false) {
        // Job not found / unreadable — honest failure rather than an endless spinner.
        return finish(pending, I18N.error || '…', { verdict: 'refuse' });
      }
      var st = res.status;
      if (st === 'done' || st === 'error') {
        var r = res.result || {};
        if (r.session_id) state.sessionId = r.session_id;
        // The result carries the honest answer even on error; prefer it, then the
        // stored error message, then a generic honest fallback.
        var body = r.answer || res.error || (I18N.error || '…');
        return finish(pending, body, r);
      }
      // Still queued / running — keep the thinking state until the cap.
      if (Date.now() - startedAt > maxMs) return stopPolling(pending);
      setTimeout(function () { pollTurn(job, pending, startedAt); }, everyMs);
    }).catch(function () {
      // Transient poll error — retry until the cap, then stop honestly.
      if (Date.now() - startedAt > maxMs) return stopPolling(pending);
      setTimeout(function () { pollTurn(job, pending, startedAt); }, everyMs);
    });
  }

  // Cap reached: the turn is still running server-side and will be persisted to the
  // transcript, so say so honestly and stop spinning. Reopening the session shows it.
  function stopPolling(pending) {
    state.sending = false;
    if (pending) {
      pending.classList.remove('zana-pending');
      pending.textContent = I18N.timeout || '…';
    }
  }

  function appendMsg(role, body, meta) {
    var box = document.getElementById('zana-messages');
    if (!box) return null;
    var empty = box.querySelector('.zana-empty');
    if (empty) empty.remove();
    var cls = 'zana-msg zana-msg-' + (role === 'assistant' ? 'assistant' : 'user');
    if (meta && meta.pending) cls += ' zana-pending';
    var msg = h('div', cls);
    msg.textContent = String(body || '');
    if (meta && meta.verdict && meta.verdict !== 'ok') {
      msg.appendChild(h('span', 'zana-verdict zana-verdict-' + meta.verdict, esc(meta.verdict)));
    }
    box.appendChild(msg);
    // v1.6.2 (D-01): linkify addresses in the ANSWER, LAST — after the body text
    // and any verdict chip are in place. Assistant answers only; a user echo is
    // left untouched. Draft/preview subtrees are skipped inside linkifyAddresses.
    if (role === 'assistant') { try { linkifyAddresses(msg); } catch (e) {} }
    box.scrollTop = box.scrollHeight;
    return msg;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // ── Address linkifier (Wave D / D-01) ────────────────────────────────────
  // A DOM post-processor that turns address-shaped text in a chat ANSWER into a
  // device-appropriate map link, via the ONE shared helper window.zdzMapsUrl().
  //
  // Runs LAST in the append path, over the rendered node — a two-pass TreeWalker
  // on TEXT NODES ONLY (never a regex on an HTML string, which would corrupt any
  // existing <a>/attributes). Precision over recall: a house number is always
  // required, street types split into strong (abbrev, may end bare), weak (full
  // word, needs a corroborating tail), and Spanish-prefix tiers.
  //
  // DRAFT-CARD-SAFE — the load-bearing property: the walker skips A/CODE/PRE/…
  // AND any draft/preview subtree, so a live <a> can NEVER be baked into the body
  // of an unsent estimate/quote/message/email draft that a later turn might POST
  // verbatim. A draft renderer marks its subtree with one of DRAFT_SEL (Zorderz
  // naming; the generic [data-no-linkify] is the escape hatch for any new card).
  //
  // XSS: the href is the shared helper's output (hardcoded https + encodeURIComponent);
  // the visible text is set via textContent — no markup is ever parsed from the match.
  //
  // GENERALIZATION: the street grammar ships a US + common-Spanish default and is
  // extendable per-locale via window.zdzStreetGrammar (an Identity `territories`
  // extension). The addresses themselves are runtime data, never shipped.
  var SKIP_TAGS = { A: 1, CODE: 1, PRE: 1, SCRIPT: 1, STYLE: 1, TEXTAREA: 1, BUTTON: 1, KBD: 1, SAMP: 1, SVG: 1 };
  var DRAFT_SEL = '.zana-draft-card, .zana-zim-draft-card, .zana-email-draft-card, .zdz-draft-card, [data-draft], [data-draft-card], [data-no-linkify]';

  var ADDR_RE = (function () {
    var G = (typeof window !== 'undefined' && window.zdzStreetGrammar) || {};
    function esc1(s) { return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
    function alt(a) { return a.map(esc1).join('|'); }
    // Strong: USPS-style abbreviations that may legitimately END an address bare.
    var STRONG = G.strong || ['St', 'Ave', 'Av', 'Blvd', 'Rd', 'Dr', 'Ln', 'Ct', 'Pl', 'Ter', 'Cir', 'Hwy', 'Pkwy', 'Pky', 'Trl', 'Sq', 'Wy', 'Xing', 'Cv', 'Pt'];
    // Weak: full words — need a corroborating tail (direction / unit / ,City ZIP).
    var WEAK = G.weak || ['Street', 'Avenue', 'Boulevard', 'Road', 'Drive', 'Lane', 'Court', 'Place', 'Terrace', 'Circle', 'Highway', 'Parkway', 'Trail', 'Square', 'Way', 'Crossing', 'Cove', 'Point'];
    // Spanish-prefix streets (type PRECEDES the name); US-Southwest common set.
    var SPAN = G.spanish || ['Via', 'Calle', 'Camino', 'Avenida', 'Paseo', 'Plaza', 'Rancho', 'Corte', 'Vista'];
    var DIR = '(?:N|S|E|W|NE|NW|SE|SW|North|South|East|West|Northeast|Northwest|Southeast|Southwest)';
    var UNIT = '(?:#\\s?[0-9A-Za-z\\-]+|(?:Apt|Ste|Suite|Unit|Bldg|Fl|Floor|Rm|No)\\.?\\s?[0-9A-Za-z\\-]+)';
    var CITYZIP = ',\\s?[A-Za-z][A-Za-z.\'\\- ]{1,38}?(?:\\s+[A-Z]{2})?\\s+\\d{5}(?:-\\d{4})?';
    var HOUSE = '\\d{1,6}(?:[\\-\\u2013]\\d{1,4})?[A-Za-z]?';
    var TOK = '(?:[A-Z][A-Za-z.\'\\-]*|\\d{1,3}(?:st|nd|rd|th))';
    var NAME = TOK + '(?:\\s+' + TOK + '){0,3}';
    // (?![A-Za-z]) so a type never matches as a PREFIX of a longer word
    // ("St" must not fire inside "Street", "Way" not inside "Wayne").
    var STRONG_RE = '(?:' + alt(STRONG) + ')(?![A-Za-z])\\.?';
    var WEAK_RE = '(?:' + alt(WEAK) + ')(?![A-Za-z])';
    var SPAN_RE = '(?:' + alt(SPAN) + ')(?![A-Za-z])';
    var TAIL = '(?:\\s+' + DIR + '\\b|\\s+' + UNIT + '|\\s*' + CITYZIP + ')';
    var pat =
      '\\b(?:' +
        // Spanish-prefix: HOUSE SPAN NAME [dir] [tail]
        '(?:' + HOUSE + '\\s+' + SPAN_RE + '\\s+' + NAME + '(?:\\s+' + DIR + '\\b)?(?:' + TAIL + ')?)' +
        '|' +
        // Strong: HOUSE [dir] NAME STRONG [dir] [tail]
        '(?:' + HOUSE + '\\s+(?:' + DIR + '\\s+)?' + NAME + '\\s+' + STRONG_RE + '(?:\\s+' + DIR + '\\b)?(?:' + TAIL + ')?)' +
        '|' +
        // Weak: HOUSE [dir] NAME WEAK TAIL(required — precision guard, kills "3 First Place")
        '(?:' + HOUSE + '\\s+(?:' + DIR + '\\s+)?' + NAME + '\\s+' + WEAK_RE + TAIL + ')' +
      ')';
    return new RegExp(pat, 'g');
  })();

  // Adversarial guards (F1–F6): reject runaway captures and the count-phrase
  // false positive ("3 First Place"/"1 Second Prize" — a ranking, not an address).
  function passesGuards(s) {
    if (!s || s.length > 140) { return false; }
    if (!/\d/.test(s)) { return false; }
    if (/^\d+\s+(First|Second|Third|Fourth|Fifth|Sixth|Seventh|Eighth|Ninth|Tenth)\s+(Place|Pl|Prize)\b/i.test(s) && !/\d{5}/.test(s)) {
      return false;
    }
    return true;
  }

  // Build a map URL through the shared helper; fall back to the same rule inline
  // if the theme helper is not on the page (keeps the surface self-sufficient).
  function addrUrl(addr) {
    if (typeof window !== 'undefined' && typeof window.zdzMapsUrl === 'function') {
      return window.zdzMapsUrl(addr);
    }
    var q = encodeURIComponent(String(addr == null ? '' : addr).trim());
    if (!q) { return ''; }
    var ua = (typeof navigator !== 'undefined' && navigator.userAgent) || '';
    return /iPhone|iPad|iPod|Macintosh/i.test(ua)
      ? 'https://maps.apple.com/?q=' + q
      : 'https://www.google.com/maps/search/?api=1&query=' + q;
  }

  // Run the address regex over a string, applying the guards; call cb(match, index).
  function eachAddressMatch(text, cb) {
    ADDR_RE.lastIndex = 0;
    var m;
    while ((m = ADDR_RE.exec(text))) {
      if (m[0] === '') { ADDR_RE.lastIndex++; continue; }
      if (passesGuards(m[0])) { cb(m[0], m.index); }
    }
  }

  // Pure helper (also a test seam): the address strings a given text yields.
  function matchAddresses(text) {
    var out = [];
    eachAddressMatch(String(text == null ? '' : text), function (s) { out.push(s); });
    return out;
  }

  // Is this text node inside a subtree we must not touch (a skip tag or a draft card)?
  function inSkippedSubtree(node, root) {
    for (var el = node.parentNode; el && el.nodeType === 1; el = el.parentNode) {
      var tn = el.tagName ? String(el.tagName).toUpperCase() : '';
      if (SKIP_TAGS[tn]) { return true; }
      if (el.matches && el.matches(DRAFT_SEL)) { return true; }
      if (el === root) { break; }
    }
    return false;
  }

  function linkifyTextNode(node) {
    var text = node.nodeValue;
    var frag = null, last = 0;
    eachAddressMatch(text, function (matched, index) {
      var url = addrUrl(matched.replace(/\s+/g, ' ').trim());
      if (!url) { return; }
      if (!frag) { frag = document.createDocumentFragment(); }
      if (index > last) { frag.appendChild(document.createTextNode(text.slice(last, index))); }
      var a = document.createElement('a');
      a.setAttribute('href', url);            // helper output: https + encodeURIComponent
      a.setAttribute('target', '_blank');
      a.setAttribute('rel', 'noopener');
      a.className = 'zana-addr-link';
      a.textContent = matched;                // textContent — never innerHTML
      frag.appendChild(a);
      last = index + matched.length;
    });
    if (frag) {
      if (last < text.length) { frag.appendChild(document.createTextNode(text.slice(last))); }
      if (node.parentNode) { node.parentNode.replaceChild(frag, node); }
    }
  }

  function linkifyAddresses(root) {
    if (!root || typeof document === 'undefined' || !document.createTreeWalker) { return; }
    // If the whole node is (inside) a draft/preview card, do nothing at all.
    if (root.nodeType === 1 && root.closest && root.closest(DRAFT_SEL)) { return; }
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode: function (n) {
        if (!n.nodeValue) { return NodeFilter.FILTER_REJECT; }
        ADDR_RE.lastIndex = 0;
        if (!ADDR_RE.test(n.nodeValue)) { return NodeFilter.FILTER_REJECT; }
        if (inSkippedSubtree(n, root)) { return NodeFilter.FILTER_REJECT; }
        return NodeFilter.FILTER_ACCEPT;
      }
    });
    // Pass 1: collect (splitting mutates the tree, so never split mid-walk).
    var targets = [], t;
    while ((t = walker.nextNode())) { targets.push(t); }
    // Pass 2: split + wrap.
    for (var i = 0; i < targets.length; i++) { linkifyTextNode(targets[i]); }
  }

  function init() {
    if (state.booted) return;
    state.booted = true;
    ensureNav();
    ensureSubview();
    // Digest / recall deep-link: #zana-session=<id> (legacy #tsa-session= also honoured).
    var m = (window.location.hash || '').match(/#(?:zana|tsa)-session=(\d+)/);
    if (m) { open({ session: parseInt(m[1], 10) }); }
  }

  window.ZanaChat = { open: open, showChat: showChat };
  // Reusable/testable address helpers (pure matcher + DOM linkifier). Exposed so
  // the harness can prove the draft-safe + precision + XSS invariants against the
  // shipped code, and so another surface could reuse the matcher if needed.
  window.ZanaChat.matchAddresses = matchAddresses;
  window.ZanaChat.linkifyAddresses = linkifyAddresses;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
