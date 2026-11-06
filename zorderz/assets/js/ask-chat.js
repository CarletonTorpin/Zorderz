'use strict';
/**
 * "Ask chat about this" on every dashboard widget.
 *
 * A chat button in each widget's header opens a small panel: a question box and
 * "What chat will see". What is shared:
 *   • the app's own summary, when the app publishes one:
 *       window.zdzWidgetContext[appId] = function () { return { title, text } | null; }
 *     (an app that wants no button sets window.zdzWidgetContext[appId] = false, or
 *     puts data-zdz-ask="off" on its widget markup);
 *   • otherwise the text visible in the widget. Form fields' typed values, password
 *     fields, hidden elements and anything marked data-zdz-private are never read;
 *   • the last few things the person did in that widget (button and option labels,
 *     never what they typed).
 * The server (POST zorderz/v1/ask-context, ZDZ_Ask_Context) removes private details and
 * fences the text as data BEFORE the person sees the preview; nothing is sent to
 * chat until they press Send. Never on the shared device or in Demo Mode.
 *
 * Every element is separated from the next (glued text such as "Customer" + "Sam
 * Example" read as "CustomerSam Example" would slip past the server's patterns); a
 * collapsed <details> shares only its summary; the action log keeps only a control's
 * own short visible label, never its tooltip or a long row of text; and an app that
 * publishes a summary but returns nothing shares nothing, rather than falling back to
 * the raw screen.
 */
(function () {
  var CHAT_APPS = (window.zdzData && Array.isArray(window.zdzData.askChatApps) && window.zdzData.askChatApps.length) ? window.zdzData.askChatApps : ['sales-analytics'];
  var MAX_TEXT = 12000;
  var SKIP = { SCRIPT: 1, STYLE: 1, NOSCRIPT: 1, SVG: 1, TEMPLATE: 1, INPUT: 1, TEXTAREA: 1, BUTTON: 0, IFRAME: 1, CANVAS: 1 };
  var BLOCK = /^(DIV|P|LI|TR|H[1-6]|SECTION|ARTICLE|HEADER|FOOTER|UL|OL|TABLE|TBODY|THEAD|DL|DT|DD|FORM|FIELDSET|LABEL|BR|HR|DETAILS|SUMMARY|FIGURE|BLOCKQUOTE|PRE)$/;
  var actions = {};          // appId -> [{label}]
  window.zdzWidgetContext = window.zdzWidgetContext || {};

  function data() { return window.zdzData || {}; }
  function chatApp() {
    var apps = data().apps || [];
    for (var i = 0; i < apps.length; i++) if (CHAT_APPS.indexOf(apps[i].id) >= 0) return apps[i];
    return null;
  }
  function allowed() {
    var d = data();
    return !!chatApp() && d.userRole !== 'zdz_general' && !d.kioskDemoActive
      && window.Bridge && typeof window.Bridge.loadApp === 'function';
  }
  function appInfo(id) {
    var apps = data().apps || [];
    for (var i = 0; i < apps.length; i++) if (apps[i].id === id) return apps[i];
    return { id: id, nm: id };
  }
  function optedOut(container, id) {
    return window.zdzWidgetContext[id] === false || !!container.querySelector('[data-zdz-ask="off"]')
      || CHAT_APPS.indexOf(id) >= 0;
  }

  // ---------------------------------------------------------------- what it shows
  function visible(el) {
    if (el.hidden || el.getAttribute('aria-hidden') === 'true') return false;
    var cs = window.getComputedStyle(el);
    return cs.display !== 'none' && cs.visibility !== 'hidden';
  }
  function screenText(body) {
    var out = [];
    (function walk(node) {
      for (var n = node.firstChild; n; n = n.nextSibling) {
        if (n.nodeType === 3) { var t = n.nodeValue.replace(/\s+/g, ' '); if (t.trim()) out.push(t); continue; }
        if (n.nodeType !== 1) continue;
        var tag = n.tagName;
        if (SKIP[tag] || n.classList.contains('zdz-ask') || n.hasAttribute('data-zdz-private') || !visible(n)) continue;
        if (tag === 'SELECT') {         // the choice on screen, e.g. "This month"
          var opt = n.options[n.selectedIndex];
          if (opt) out.push(' [' + (n.getAttribute('aria-label') || n.name || 'choice') + ': ' + opt.text + '] ');
          continue;
        }
        if (tag === 'DETAILS' && !n.open) {   // collapsed = only its heading
          var sm = n.querySelector(':scope > summary');
          if (sm) out.push('\n' + sm.textContent.replace(/\s+/g, ' ').trim() + '\n');
          continue;
        }
        // a separator on BOTH sides of every element, so a label and its value
        // (<span>Customer</span><span>Sam Example</span>) can never glue into one word.
        var sep = BLOCK.test(tag) ? '\n' : ' ';
        out.push(sep);
        walk(n);
        out.push(sep);
      }
    })(body);
    return out.join('').replace(/[ \t]+/g, ' ').replace(/ ?\n ?/g, '\n').replace(/\n{3,}/g, '\n\n').trim().slice(0, MAX_TEXT);
  }
  function snapshot(container, id) {
    var p = window.zdzWidgetContext[id];
    if (typeof p === 'function') {
      // an app that publishes its own summary decides what is shared. When it
      // has nothing yet (a chart not drawn) the answer is "nothing", never the raw screen,
      // which is exactly what the app chose not to share.
      try {
        var r = p();
        if (r && r.text) return { source: 'provider', title: r.title || '', text: String(r.text).slice(0, MAX_TEXT) };
      } catch (e) { /* nothing */ }
      return { source: 'provider', title: '', text: '' };
    }
    var body = container.querySelector('.dash-widget-body') || container;
    return { source: 'screen', title: '', text: screenText(body) };
  }

  // ---------------------------------------------------------------- what they did
  // a control's own short VISIBLE label only ("Send", "This month", "Won").
  // Never its tooltip or aria-label ("Email Jordan Example"), and never a long run of
  // text: an Inbox row is a <button> whose text is the sender, subject and snippet.
  function label(el) {
    var t = (el.textContent || (el.type === 'button' || el.type === 'submit' ? el.value : '') || '')
      .replace(/\s+/g, ' ').trim();
    if (!t || t.length > 30 || t.split(' ').length > 4 || /[@\d]{3,}/.test(t)) return '';
    // Two capitalised words in a row, or a colon, is a name or a calendar chip
    // ("Sam Example install", "09:00 Alex: Example measure"), not a control's label.
    if (/:/.test(t) || /[A-Z\u00C0-\u00DE][a-z\u00DF-\u00FF'\-]+\s+[A-Z\u00C0-\u00DE][a-z\u00DF-\u00FF'\-]+/.test(t)) return '';
    return t;
  }
  function note(e, kind) {
    var t = e.target;
    if (!t || !t.closest) return;
    var body = t.closest('.dash-widget-body');
    var c = body && body.closest('.dash-widget-container');
    if (!c || t.closest('[data-zdz-private]')) return;
    var text = '';
    if (kind === 'click') {
      var b = t.closest('button, a, [role="button"], [role="tab"], summary');
      if (!b) return;
      text = 'Pressed: ' + (label(b) || 'a button');
    } else if (kind === 'change' && t.tagName === 'SELECT') {
      var o = t.options[t.selectedIndex];
      text = 'Chose: ' + (o ? (label({ textContent: o.text }) || 'an option') : 'an option');
    } else if (kind === 'change' && (t.type === 'checkbox' || t.type === 'radio')) {
      var l = t.closest('label');
      text = (t.checked ? 'Ticked: ' : 'Unticked: ') + ((l && label(l)) || 'a box');
    } else if (kind === 'submit') {
      text = 'Submitted a form';                // never what was typed
    }
    if (!text || /:\s*$/.test(text)) return;
    var list = actions[c.getAttribute('data-app-id')] = actions[c.getAttribute('data-app-id')] || [];
    list.push({ label: text.slice(0, 120) });
    if (list.length > 5) list.shift();
  }
  document.addEventListener('click', function (e) { note(e, 'click'); }, true);
  document.addEventListener('change', function (e) { note(e, 'change'); }, true);
  document.addEventListener('submit', function (e) { note(e, 'submit'); }, true);

  // ---------------------------------------------------------------- the panel
  var ICON = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16v11H9l-5 4z"/><path d="M8 9.5h8M8 12.5h5"/></svg>';

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text) n.textContent = text;
    return n;
  }

  function prepare(container, id, panel) {
    var info = appInfo(id), snap = snapshot(container, id);
    panel._ready = null;
    panel._pre.textContent = 'Getting it ready…';
    panel._hidden.textContent = '';
    if (!snap.text) { panel._pre.textContent = 'Nothing to share in this widget yet.'; return Promise.resolve(null); }
    var d = data();
    return fetch((d.apiUrl || '/wp-json/zorderz/v1/') + 'ask-context', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': d.nonce || '' },
      body: JSON.stringify({ app_id: id, app_name: info.nm || id, title: snap.title, source: snap.source,
                             text: snap.text, actions: actions[id] || [] })
    }).then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (r) {
        if (!r || !r.ok) { panel._pre.textContent = 'This widget can\'t be shared with chat right now.'; return null; }
        panel._ready = r.text;
        panel._pre.textContent = r.text;
        var h = r.hidden || {}, bits = Object.keys(h).map(function (k) { return h[k] + ' ' + k; });
        panel._hidden.textContent = bits.length ? 'Hidden before sharing: ' + bits.join(', ') + '.'
          : 'Nothing private was found to hide.';
        return r.text;
      }).catch(function () { panel._pre.textContent = 'This widget can\'t be shared with chat right now.'; return null; });
  }

  function send(container, id, panel) {
    var q = panel._input.value.trim() || 'Help me understand what this shows and what I should do next.';
    var go = function (text) {
      if (!text) return;
      window.Bridge.loadApp(chatApp().id, { prompt: q + '\n\n' + text, source: 'ask-chat:' + id });
      panel._input.value = '';
      toggle(container, id, false);
    };
    if (panel._ready) go(panel._ready); else prepare(container, id, panel).then(go);
  }

  function panelFor(container, id) {
    var p = container.querySelector(':scope > .zdz-ask');
    if (p) return p;
    var info = appInfo(id);
    p = el('div', 'zdz-ask');
    p.hidden = true;
    var form = el('form', 'zdz-ask-row');
    var input = el('input', 'zdz-ask-input');
    input.type = 'text'; input.maxLength = 500; input.autocomplete = 'off';
    input.placeholder = 'Ask chat about ' + (info.nm || 'this') + '…';
    input.setAttribute('aria-label', input.placeholder);
    input.setAttribute('enterkeyhint', 'send');
    var btn = el('button', 'zdz-ask-send', 'Send'); btn.type = 'submit';
    form.appendChild(input); form.appendChild(btn);
    var det = el('details', 'zdz-ask-preview');
    det.appendChild(el('summary', '', 'What chat will see'));
    var hid = el('p', 'zdz-ask-hidden');
    var pre = el('pre', 'zdz-ask-text');
    det.appendChild(hid); det.appendChild(pre);
    p.appendChild(form); p.appendChild(det);
    p._input = input; p._pre = pre; p._hidden = hid;
    form.addEventListener('submit', function (e) { e.preventDefault(); send(container, id, p); });
    var header = container.querySelector(':scope > .dash-widget-header');
    if (header && header.nextSibling) container.insertBefore(p, header.nextSibling); else container.appendChild(p);
    return p;
  }

  function toggle(container, id, open) {
    var p = panelFor(container, id);
    var b = container.querySelector('.dw-ask-btn');
    open = open === undefined ? p.hidden : open;
    p.hidden = !open;
    if (b) b.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) { prepare(container, id, p); p._input.focus({ preventScroll: true }); }
  }

  function decorate() {
    if (!allowed()) return;
    var list = document.querySelectorAll('.dash-widget-container[data-app-id]');
    Array.prototype.forEach.call(list, function (c) {
      var id = c.getAttribute('data-app-id');
      var header = c.querySelector(':scope > .dash-widget-header');
      if (!header || header.querySelector('.dw-ask-btn') || optedOut(c, id)) return;
      var b = el('button', 'dw-ask-btn');
      b.type = 'button';
      b.innerHTML = ICON;                     // constant markup only
      b.title = 'Ask chat about this';
      b.setAttribute('aria-label', 'Ask chat about ' + (appInfo(id).nm || 'this'));
      b.setAttribute('aria-expanded', 'false');
      b.addEventListener('click', function (e) { e.stopPropagation(); toggle(c, id); });
      var nav = header.querySelector('.dw-nav');
      header.insertBefore(b, nav || null);
    });
  }

  window.zdzAskChat = { _test: { screenText: screenText, label: label, snapshot: snapshot, actions: actions }, open: function (appId) {
    var c = document.querySelector('.dash-widget-container[data-app-id="' + appId + '"]');
    if (c && allowed() && !optedOut(c, appId)) toggle(c, appId, true);
  } };
  document.addEventListener('zdz_widgets_rendered', function () { setTimeout(decorate, 0); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', decorate); else decorate();
})();
