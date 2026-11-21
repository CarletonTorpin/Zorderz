/**
 * Zorderz Analytics — what the chat page adds for drafts.
 *
 *   - Edit on a draft card (an email, a direct message, a channel post), for an administrator the style rules are on
 *     for. The card's words become a text box; Save puts the new words on the card, so Copy, Open in Mail, Open in
 *     Email and Send use them. A direct message the server holds is changed on the server first (what Send sends is
 *     always the server's copy). The draft as written and as changed go to the style pass, which the page does not
 *     wait for.
 *   - "How you write", under the sentences in the "About you" sheet: the person's style rules by audience, each with
 *     Delete; a box to add one of their own; Clear everything.
 *   - A link from a Messages reply (<home>/?zana_ask=<id>): the chat opens and sends the request the link stands for.
 *     The words come from the server, only for the person the link was made for, once; the id is taken out of the
 *     address as soon as it is read.
 *
 * Everything drawn here is built from elements and text nodes; nothing is ever read as HTML. The settings and words
 * come from window.zanaDrafts; the chat's own address and nonce from window.zanaChat. The helpers at the top are pure
 * and are tested on their own.
 */
(function (root) {
  'use strict';

  /* ── pure helpers ───────────────────────────────────────────────────────────────────── */

  /** The id of a request link in an address's query string, or ''. */
  function askId(search, param) {
    var name = String(param || 'zana_ask').replace(/[^a-z0-9_]/gi, '');
    var m = new RegExp('[?&]' + name + '=([0-9a-f]{24})(?:&|#|$)').exec(String(search || ''));
    return m ? m[1] : '';
  }

  /** The query string without the request link's id (whatever its value was). */
  function withoutAsk(search, param) {
    var name = String(param || 'zana_ask').replace(/[^a-z0-9_]/gi, '');
    var rest = String(search || '').replace(/^\?/, '').split('&').filter(function (p) {
      return p !== '' && p.split('=')[0] !== name;
    });
    return rest.length ? '?' + rest.join('&') : '';
  }

  /** The kind of draft a card is, as the style pass names it; '' for a card that is not a draft. */
  function kindOf(card) {
    var k = card && card.k;
    return (k === 'email' || k === 'dm' || k === 'post') ? k : '';
  }

  /** May this card's words be edited? A draft with words, for a person the style rules are on for. */
  function editable(card, cfg) {
    return !!(cfg && cfg.style && kindOf(card) !== '' && typeof card.body === 'string' && card.body.trim() !== '');
  }

  /** Is this the id of a direct message the server holds? */
  function heldId(card) {
    return !!(card && card.k === 'dm' && /^[a-f0-9]{32}$/.test(String(card.id || '')));
  }

  /** The same words, apart from spaces and line ends? */
  function sameWords(a, b) {
    function sq(s) { return String(s == null ? '' : s).replace(/\s+/g, ' ').trim(); }
    return sq(a) === sq(b);
  }

  /** Rules in the audiences' own order: [ { id, label, rules: [ rule ] } ], empty audiences left out. */
  function groupRules(rules, audiences) {
    var out = [];
    (audiences || []).forEach(function (a) {
      var mine = (rules || []).filter(function (r) { return r && r.audience === a.id; });
      if (mine.length) { out.push({ id: a.id, label: String(a.label || a.id), rules: mine }); }
    });
    return out;
  }

  var pure = { askId: askId, withoutAsk: withoutAsk, kindOf: kindOf, editable: editable, heldId: heldId, sameWords: sameWords, groupRules: groupRules };
  if (typeof module === 'object' && module && module.exports) { module.exports = pure; }
  if (!root || !root.document) { return; }

  /* ── the page ───────────────────────────────────────────────────────────────────── */

  var document = root.document;
  var CFG = root.zanaDrafts || {};
  var CHAT = root.zanaChat || {};
  var I18N = CFG.i18n || {};
  root.ZanaChatDrafts = pure;

  function t(key, fallback) { return (I18N[key] != null && I18N[key] !== '') ? String(I18N[key]) : (fallback || ''); }
  function h(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text != null) { e.textContent = String(text); }
    return e;
  }
  function btn(cls, text) {
    var b = h('button', cls, text);
    b.type = 'button';
    return b;
  }
  function toast(text, kind) {
    var k = root.ZDZ && root.ZDZ.ui;
    if (k && k.toast) { k.toast(text, kind ? { kind: kind } : {}); }
  }

  /** One request to the chat's own routes: the answer's data, or an Error whose message is the server's sentence. */
  function rest(path, method, body) {
    return root.fetch(String(CHAT.apiUrl || '') + path, {
      method: method || 'GET',
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': String(CHAT.nonce || ''), 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok) { var e = new Error(String((j && j.message) || t('notSaved', 'That change could not be saved.'))); e.status = r.status; throw e; }
        return j;
      }, function () {
        if (!r.ok) { var e2 = new Error(t('notSaved', 'That change could not be saved.')); e2.status = r.status; throw e2; }
        return {};
      });
    });
  }

  /* ── Edit on a draft card ───────────────────────────────────────────────────────────────────── */

  function onCard(node, card) {
    if (!editable(card, CFG) || !node || !node.querySelector) { return; }
    var bodyEl = node.querySelector('.zana-draft-body');
    var acts = node.querySelector('.zana-card-actions');
    if (!bodyEl || !acts) { return; }   // a sent message, a note: nothing to edit
    var ed = btn('zdz-btn zdz-btn--quiet zana-style-edit', t('edit', 'Edit'));
    ed.title = t('editTitle', 'Change the draft. The assistant learns from what you change.');
    acts.appendChild(ed);
    ed.addEventListener('click', function () { edit(node, bodyEl, acts, card); });
  }

  function edit(node, bodyEl, acts, card) {
    if (node.classList.contains('zana-style-editing')) { return; }
    var kind = kindOf(card);
    var before = String(card.body || '');
    var box = h('textarea', 'zana-style-box');
    box.value = before;
    box.rows = Math.min(16, Math.max(4, before.split('\n').length + 1));
    box.setAttribute('aria-label', t('editLabel', 'The draft\'s words'));
    var bar = h('div', 'zana-card-actions zana-style-bar');
    var save = btn('zdz-btn zdz-btn--primary', t('save', 'Save'));
    var cancel = btn('zdz-btn zdz-btn--quiet', t('cancel', 'Cancel'));
    var note = h('span', 'zana-card-note');
    note.setAttribute('role', 'status');
    bar.appendChild(save);
    bar.appendChild(cancel);
    bar.appendChild(note);
    node.classList.add('zana-style-editing');
    bodyEl.hidden = true;
    acts.hidden = true;   // nothing is copied or sent while the words are being changed
    bodyEl.parentNode.insertBefore(box, bodyEl.nextSibling);
    box.parentNode.insertBefore(bar, box.nextSibling);
    function close() {
      box.remove();
      bar.remove();
      bodyEl.hidden = false;
      acts.hidden = false;
      node.classList.remove('zana-style-editing');
    }
    cancel.addEventListener('click', close);
    save.addEventListener('click', function () {
      var after = String(box.value || '').replace(/\s+$/, '');
      if (after.trim() === '' || sameWords(after, before)) { close(); return; }
      save.disabled = true;
      cancel.disabled = true;
      note.textContent = '';
      // a direct message the server holds: its copy changes first, and its words are what the card then shows
      var first = heldId(card)
        ? rest('/style/draft', 'POST', { card: card.id, session_id: (root.ZanaChat && root.ZanaChat.sessionId) ? root.ZanaChat.sessionId() : 0, body: after })
        : Promise.resolve({ body: after });
      first.then(function (d) {
        var words = String((d && d.body) || after);
        card.body = words;   // Copy, Open in Email and Open in Mail read the card's words when pressed
        bodyEl.textContent = words;
        var K = root.ZanaChatKit;
        var mail = node.querySelector('a[href^="mailto:"]');
        if (kind === 'email' && mail && K && typeof K.mailtoFor === 'function') {
          try { mail.setAttribute('href', K.mailtoFor(card.to, card.subject, words).url); } catch (e) { /* the link stays */ }
        }
        close();
        // the style pass: the page does not wait for it, and its trouble is its own
        rest('/style/edit', 'POST', { kind: kind, before: before, after: words }).then(function (r) {
          if (r && r.recorded === 'recorded') { toast(t('saved', 'Saved your edit. The assistant will write more like this.'), 'success'); }
        }, function () { /* nothing to show */ });
      }, function (e) {
        save.disabled = false;
        cancel.disabled = false;
        note.textContent = String((e && e.message) || t('notSaved', 'That change could not be saved.'));
      });
    });
    try { box.focus(); } catch (e) { /* not focusable yet */ }
  }

  /* ── "How you write" in the "About you" sheet ───────────────────────────────────────────────────────────────────── */

  function onMemory(sheetBody) {
    if (!CFG.style || !sheetBody || !sheetBody.appendChild) { return; }
    var wrap = h('div', 'zana-style');
    wrap.appendChild(h('h3', 'zana-style-title', t('howTitle', 'How you write')));
    wrap.appendChild(h('p', 'zana-mem-intro', t('howIntro', '')));
    var list = h('div', 'zana-style-list');
    wrap.appendChild(list);
    var status = h('p', 'zana-mem-status');
    status.setAttribute('role', 'status');
    wrap.appendChild(status);
    var form = h('div', 'zana-style-add');
    var who = h('select', 'zana-style-who');
    who.setAttribute('aria-label', t('addFor', 'Who the rule is for'));
    var words = h('input', 'zana-style-words');
    words.type = 'text';
    words.maxLength = 200;
    words.placeholder = t('addWords', 'A rule in one sentence, about style');
    words.setAttribute('aria-label', t('addWords', 'A rule in one sentence, about style'));
    var add = btn('zdz-btn zdz-btn--secondary', t('add', 'Add rule'));
    form.appendChild(who);
    form.appendChild(words);
    form.appendChild(add);
    wrap.appendChild(form);
    var foot = h('div', 'zana-style-foot');
    var kept = h('span', 'zana-mem-date');
    var clear = btn('zdz-btn zdz-btn--quiet zana-style-clear', t('clear', 'Clear everything'));
    foot.appendChild(kept);
    foot.appendChild(clear);
    wrap.appendChild(foot);
    sheetBody.appendChild(wrap);

    var state = { rules: [], audiences: [], edits: 0 };

    function draw() {
      list.textContent = '';
      var groups = groupRules(state.rules, state.audiences);
      if (!groups.length) { list.appendChild(h('p', 'zana-mem-empty', t('howEmpty', 'No rules yet. Edit a draft, or add a rule of your own.'))); }
      groups.forEach(function (g) {
        list.appendChild(h('h4', 'zana-style-group', g.label));
        var ul = h('ul', 'zana-mem-list');
        g.rules.forEach(function (r) {
          var li = h('li', 'zana-mem-item');
          li.appendChild(h('p', 'zana-mem-text', String(r.rule || '')));
          var meta = h('div', 'zana-mem-meta');
          meta.appendChild(h('span', 'zana-mem-date', r.source === 'added' ? t('yours', 'yours') : t('learned', 'learned')));
          var del = btn('zdz-btn zdz-btn--secondary zana-mem-del', t('delete', 'Delete'));
          del.addEventListener('click', function () {
            del.disabled = true;
            rest('/style/rule/' + encodeURIComponent(String(r.id || '')), 'DELETE').then(function (d) {
              if (d && d.deleted) {
                state.rules = state.rules.filter(function (x) { return x !== r; });
                draw();
              } else {
                del.disabled = false;
                status.textContent = t('notSaved', 'That change could not be saved.');
              }
            }, function (e) { del.disabled = false; status.textContent = String((e && e.message) || t('notSaved', '')); });
          });
          meta.appendChild(del);
          li.appendChild(meta);
          ul.appendChild(li);
        });
        list.appendChild(ul);
      });
      kept.textContent = t('howEdits', 'Edits and corrections kept: %d').replace('%d', String(state.edits || 0));
      clear.hidden = !state.rules.length && !state.edits;
    }

    function load() {
      list.textContent = '';
      list.appendChild(h('p', 'zana-mem-empty', t('loading', 'Loading…')));
      rest('/style', 'GET').then(function (d) {
        state.rules = (d && d.rules) || [];
        state.audiences = (d && d.audiences) || [];
        state.edits = parseInt(d && d.edits, 10) || 0;
        who.textContent = '';
        state.audiences.forEach(function (a) {
          var o = h('option', null, String(a.label || a.id));
          o.value = String(a.id);
          who.appendChild(o);
        });
        draw();
      }, function (e) {
        list.textContent = '';
        list.appendChild(h('p', 'zana-mem-empty', String((e && e.message) || t('notSaved', ''))));
      });
    }

    add.addEventListener('click', function () {
      var text = String(words.value || '').trim();
      if (text === '') { try { words.focus(); } catch (e) { /* not focusable */ } return; }
      add.disabled = true;
      status.textContent = '';
      rest('/style/rule', 'POST', { audience: who.value, rule: text }).then(function (d) {
        add.disabled = false;
        if (d && d.rule) {
          state.rules.unshift(d.rule);
          words.value = '';
          draw();
        }
      }, function (e) { add.disabled = false; status.textContent = String((e && e.message) || t('notSaved', '')); });
    });
    words.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); add.click(); } });

    clear.addEventListener('click', function () {
      if (!root.confirm(t('clearAsk', 'Delete every rule and every kept edit? This cannot be undone.'))) { return; }
      clear.disabled = true;
      rest('/style', 'DELETE').then(function () {
        clear.disabled = false;
        state.rules = [];
        state.edits = 0;
        draw();
      }, function (e) { clear.disabled = false; status.textContent = String((e && e.message) || t('notSaved', '')); });
    });

    load();
  }

  /* ── a link from a Messages reply ───────────────────────────────────────────────────────────────────── */

  function openAsk() {
    var id = CFG.ask ? askId(root.location.search, CFG.askParam) : '';
    if (!id) { return; }
    // out of the address first: a reload, or the page being shared, never carries it again
    if (root.history && typeof root.history.replaceState === 'function') {
      try { root.history.replaceState(root.history.state, '', root.location.pathname + withoutAsk(root.location.search, CFG.askParam) + root.location.hash); } catch (e) { /* the address stays */ }
    }
    rest('/ask', 'POST', { id: id }).then(function (d) {
      var words = String((d && d.text) || '').trim();
      if (words !== '' && root.ZanaChat && typeof root.ZanaChat.open === 'function') { root.ZanaChat.open({ prompt: words }); }
    }, function () { toast(t('askGone', 'That link has expired or is not yours.'), 'error'); });
  }

  function start() {
    var C = root.ZanaChat;
    if (!C || typeof C.on !== 'function') { return; }   // the chat did not load: nothing to add to
    if (CFG.style) {
      C.on('card', onCard);
      C.on('memory', onMemory);
    }
    openAsk();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})(typeof window !== 'undefined' ? window : null);
