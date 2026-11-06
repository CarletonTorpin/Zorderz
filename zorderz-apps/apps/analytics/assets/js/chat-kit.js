/**
 * Zorderz Analytics — the chat's pure helpers.
 *
 * Everything here works on strings and plain objects: no DOM, no network. chat.js draws what these return. The same
 * file loads in the browser (window.ZanaChatKit) and in Node (module.exports), so the rules are tested on their own.
 *
 * What an answer may carry, besides its words (each written by the server only; a marker-shaped string in the model's
 * own text is defused on the server before the answer is stored, so the model cannot draw any of these):
 *
 *   [ZDZ_CONFIRM:<32 hex>]                                   a held change, drawn as a Confirm card
 *   [ZANA_DM_DRAFT:<32 hex>|@login|Name]words[/ZANA_DM_DRAFT] a direct message to a teammate, held on the server and
 *                                                            sent only by the Send press
 *   [ZANA_POST_DRAFT:#channel]words[/ZANA_POST_DRAFT]        a post for a channel (the person copies it)
 *   [ZDZ_EMAIL_DRAFT(:Name)]to:/subject:/---/body[/ZDZ_EMAIL_DRAFT]  an email draft: never a send from the chat
 *   <div class="zana-charts-chips"> buttons </div>           "Open in Charts" buttons (and a small chart of their rows)
 *   <div class="zana-app-chips"> buttons </div>              "Open in <app>" buttons with the settings the app opens with
 *   <!--ZANA_OPEN_PAGE:{"url","title","id"}-->               a page of this site, opened in the app
 *   <a href="#" data-zana-doc-kind="invoice|estimate">#1001</a>  an invoice or estimate that opens under its line (and a
 *                                                            "#1001" in a table column headed Invoice or Estimate)
 *
 * Nothing in an answer is ever read as HTML. The few shapes above are matched exactly and turned into data; any other
 * markup stays visible as the text it is.
 */
(function (root) {
  'use strict';

  var CARD = '';   // a card's place in the text: CARD + index + CARD_END, on a line of its own
  var CARD_END = '';
  var DOC = '';    // an inline document opener: DOC + index + CARD_END
  var MAX_TEXT = 60000;

  /* ── small helpers ─────────────────────────────────────────────────────────────────────────────── */

  function str(v) { return v == null ? '' : String(v); }

  /** HTML entities a server writes into an attribute or a label, back to their characters. */
  function unentity(s) {
    return str(s).replace(/&(#\d{1,7}|#x[0-9a-f]{1,6}|amp|lt|gt|quot|apos|nbsp);/gi, function (all, e) {
      var k = e.toLowerCase();
      if (k === 'amp') return '&';
      if (k === 'lt') return '<';
      if (k === 'gt') return '>';
      if (k === 'quot') return '"';
      if (k === 'apos') return "'";
      if (k === 'nbsp') return ' ';
      var n = k.charAt(1) === 'x' ? parseInt(k.slice(2), 16) : parseInt(k.slice(1), 10);
      if (!(n > 0 && n <= 0x10FFFF) || (n >= 0xD800 && n <= 0xDFFF)) return all;
      try { return String.fromCodePoint(n); } catch (x) { return all; }
    });
  }

  /** Decode base64 (standard or URL-safe, padded or not) to a UTF-8 string; null when it is not one. */
  function b64decode(raw) {
    var b = str(raw).replace(/-/g, '+').replace(/_/g, '/');
    if (!/^[A-Za-z0-9+/]*={0,2}$/.test(b)) return null;
    while (b.length % 4) b += '=';
    try {
      var bin = typeof atob === 'function' ? atob(b) : Buffer.from(b, 'base64').toString('binary');
      try { return decodeURIComponent(escape(bin)); } catch (e) { return bin; }
    } catch (e2) { return null; }
  }

  /** A base64url JSON object (a chip's settings), or null. Arrays and scalars are refused. */
  function b64urlJson(raw, maxLen) {
    var s = str(raw);
    if (!s || s.length > (maxLen || 20000) || !/^[A-Za-z0-9_-]{2,}$/.test(s)) return null;
    var txt = b64decode(s);
    if (txt === null) return null;
    try {
      var o = JSON.parse(txt);
      return (o && typeof o === 'object' && !Array.isArray(o)) ? o : null;
    } catch (e) { return null; }
  }

  /** The Charts app's handover for a chip's spec: "zch:" + the spec as standard base64, URI-encoded (charts.js). */
  function chartsPrompt(b64url) {
    var s = str(b64url);
    if (!/^[A-Za-z0-9_-]{2,6000}$/.test(s)) return '';
    var b = s.replace(/-/g, '+').replace(/_/g, '/');
    while (b.length % 4) b += '=';
    return 'zch:' + encodeURIComponent(b);
  }

  /* ── links ────────────────────────────────────────────────────────────────────────────────────── */

  /**
   * A link an answer may carry: https, mailto, tel, or a path on this site. Everything else (javascript:, data:,
   * http:, a protocol-relative address, an address with spaces or quotes) gives '' and the words stay plain text.
   */
  function safeHref(href) {
    var h = str(href).trim();
    if (!h || h.length > 2000 || /[\s"'<>`\\]/.test(h) || /[\u0000-\u001f\u007f]/.test(h)) return '';
    if (/^https:\/\/[^/?#]+/i.test(h)) return h;
    if (/^mailto:[^/]/i.test(h)) return h;
    if (/^tel:\+?[0-9().-]{3,30}$/i.test(h)) return h;
    if (/^\/(?![/\\])/.test(h)) return h;
    if (/^#[A-Za-z0-9_-]{1,80}$/.test(h)) return h;
    return '';
  }

  /** A link that stays on this site (relative, or https on the same origin), resolved; '' for anything else. */
  function safeSiteUrl(url, origin) {
    var u = str(url).trim();
    if (!u || /[\s"'<>`\\]/.test(u)) return '';
    if (/^\/(?![/\\])/.test(u)) return u;
    var o = str(origin).replace(/\/+$/, '');
    if (!o || !/^https:\/\//i.test(u)) return '';
    var host = u.replace(/^https:\/\//i, '').split(/[/?#]/)[0].toLowerCase();
    var mine = o.replace(/^https:\/\//i, '').toLowerCase();
    return host === mine ? u : '';
  }

  /** Is this link a document of the site's own document library (/vault/<slug>)? */
  function isVaultHref(href, origin) {
    var u = safeSiteUrl(href, origin);
    if (!u) return false;
    var path = u.replace(/^https:\/\/[^/]+/i, '');
    return /^\/vault\/[A-Za-z0-9_-][A-Za-z0-9_./-]{0,200}$/.test(path.split(/[?#]/)[0]);
  }

  /* ── markdown (a safe subset) ──────────────────────────────────────────────────────────────────── */

  /**
   * Inline marks of one line: [{t:'text',v}] | {t:'strong'|'em'|'del',c:[…]} | {t:'code',v} | {t:'link',href,c:[…]}
   * | {t:'doc',i} | {t:'chg',dir:'up'|'down',v}. Unknown or unsafe links keep their words as text.
   */
  function parseInline(s, depth) {
    s = str(s);
    depth = depth || 0;
    var out = [];
    var buf = '';
    function flush() { if (buf) { out.push({ t: 'text', v: buf }); buf = ''; } }
    var i = 0;
    while (i < s.length) {
      var c = s.charAt(i);
      var rest = s.slice(i);
      var m;
      if (c === DOC && (m = /^(\d{1,4})/.exec(rest))) { flush(); out.push({ t: 'doc', i: parseInt(m[1], 10) }); i += m[0].length; continue; }
      if (c === '`' && (m = /^`([^`\n]{1,500})`/.exec(rest))) { flush(); out.push({ t: 'code', v: m[1] }); i += m[0].length; continue; }
      if (depth < 3) {
        if ((m = /^\*\*(\S(?:[\s\S]{0,400}?\S)?)\*\*/.exec(rest)) || (!/[A-Za-z0-9]/.test(i > 0 ? s.charAt(i - 1) : ' ') && (m = /^__(\S(?:[\s\S]{0,400}?\S)?)__(?![A-Za-z0-9])/.exec(rest)))) {
          flush(); out.push({ t: 'strong', c: parseInline(m[1], depth + 1) }); i += m[0].length; continue;
        }
        if ((m = /^~~(\S(?:[\s\S]{0,400}?\S)?)~~/.exec(rest))) { flush(); out.push({ t: 'del', c: parseInline(m[1], depth + 1) }); i += m[0].length; continue; }
        var prevCh = i > 0 ? s.charAt(i - 1) : ' ';
        if (c === '*' && !/[A-Za-z0-9]/.test(prevCh) && (m = /^\*(?=[^\s*])([^*\n]{0,300}?[^\s*])\*(?![A-Za-z0-9*])/.exec(rest))) {
          flush(); out.push({ t: 'em', c: parseInline(m[1], depth + 1) }); i += m[0].length; continue;
        }
        if (c === '_' && !/[A-Za-z0-9]/.test(prevCh) && (m = /^_(?=[^\s_])([^_\n]{0,300}?[^\s_])_(?![A-Za-z0-9])/.exec(rest))) {
          flush(); out.push({ t: 'em', c: parseInline(m[1], depth + 1) }); i += m[0].length; continue;
        }
        if (c === '[' && (m = /^\[([^\]\n]{1,300})\]\(\s*<?([^()\s<>]{1,2000})>?(?:\s+"[^"\n]{0,200}")?\s*\)/.exec(rest))) {
          var href = safeHref(m[2]);
          flush();
          if (href) { out.push({ t: 'link', href: href, c: parseInline(m[1], depth + 1) }); }
          else { out = out.concat(parseInline(m[1], depth + 1)); }
          i += m[0].length;
          continue;
        }
      }
      if ((c === 'h' || c === 'H') && (m = /^https:\/\/[^\s<>"'`)\]]{2,1990}/i.exec(rest)) && !/[A-Za-z0-9]/.test(i > 0 ? s.charAt(i - 1) : ' ')) {
        var url = m[0].replace(/[.,;:!?]+$/, '');
        flush();
        out.push({ t: 'link', href: url, c: [{ t: 'text', v: url }] });
        i += url.length;
        continue;
      }
      if ((c === '▲' || c === '▼') && (m = /^[▲▼][ \t]*[+-]?\$?\d[\d,]*(?:\.\d+)?%?/.exec(rest))) {
        flush(); out.push({ t: 'chg', dir: c === '▲' ? 'up' : 'down', v: m[0] }); i += m[0].length; continue;
      }
      buf += c;
      i++;
    }
    flush();
    return out;
  }

  function splitRow(line) {
    var l = str(line).trim().replace(/^\|/, '').replace(/\|$/, '');
    var cells = [];
    var cur = '';
    for (var i = 0; i < l.length; i++) {
      var ch = l.charAt(i);
      if (ch === '\\' && l.charAt(i + 1) === '|') { cur += '|'; i++; continue; }
      if (ch === '|') { cells.push(cur.trim()); cur = ''; continue; }
      cur += ch;
    }
    cells.push(cur.trim());
    return cells;
  }

  function isTableSep(line) {
    return /^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/.test(str(line)) && str(line).indexOf('|') !== -1;
  }

  /**
   * Blocks of an answer: {t:'p', lines:[inline[]]}, {t:'h', level, c}, {t:'ul'|'ol', start, items:[inline[]]},
   * {t:'code', v}, {t:'quote', blocks}, {t:'table', head:[inline[]], align:[], rows:[[inline[]]]}, {t:'hr'},
   * {t:'card', i}. A single line break inside a paragraph is kept as a line break (chat answers write lines).
   */
  function parseBlocks(text, depth) {
    depth = depth || 0;
    var lines = str(text).replace(/\r\n?/g, '\n').split('\n');
    var out = [];
    var i = 0;
    var para = [];
    function endPara() { if (para.length) { out.push({ t: 'p', lines: para.map(function (l) { return parseInline(l); }) }); para = []; } }
    while (i < lines.length) {
      var line = lines[i];
      var m;
      if (/^\s*```/.test(line)) {
        endPara();
        var code = [];
        i++;
        while (i < lines.length && !/^\s*```\s*$/.test(lines[i])) { code.push(lines[i]); i++; }
        i++;
        out.push({ t: 'code', v: code.join('\n') });
        continue;
      }
      if ((m = /^\s*(\d{1,4})\s*$/.exec(line))) { endPara(); out.push({ t: 'card', i: parseInt(m[1], 10) }); i++; continue; }
      if (/^\s*$/.test(line)) { endPara(); i++; continue; }
      if ((m = /^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*$/.exec(line))) { endPara(); out.push({ t: 'h', level: m[1].length, c: parseInline(m[2]) }); i++; continue; }
      if (/^\s{0,3}([-*_])(\s*\1){2,}\s*$/.test(line)) { endPara(); out.push({ t: 'hr' }); i++; continue; }
      // a table: a header row, then a row of dashes with as many cells (GitHub's rule)
      if (line.indexOf('|') !== -1 && i + 1 < lines.length && isTableSep(lines[i + 1]) && splitRow(line).length === splitRow(lines[i + 1]).length) {
        endPara();
        var head = splitRow(line);
        var align = splitRow(lines[i + 1]).map(function (c) {
          return /^:-+:$/.test(c) ? 'center' : (/^-+:$/.test(c) ? 'right' : '');
        });
        i += 2;
        var rows = [];
        while (i < lines.length && lines[i].indexOf('|') !== -1 && !/^\s*$/.test(lines[i]) && rows.length < 400) {
          rows.push(splitRow(lines[i]).map(function (c) { return parseInline(c); }));
          i++;
        }
        out.push({ t: 'table', head: head.map(function (c) { return parseInline(c); }), align: align, rows: rows });
        continue;
      }
      if (depth < 2 && /^\s{0,3}>/.test(line)) {
        endPara();
        var q = [];
        while (i < lines.length && /^\s{0,3}>/.test(lines[i])) { q.push(lines[i].replace(/^\s{0,3}>\s?/, '')); i++; }
        out.push({ t: 'quote', blocks: parseBlocks(q.join('\n'), depth + 1) });
        continue;
      }
      if ((m = /^(\s*)([-*+•]|\d{1,3}[.)])\s+(.*)$/.exec(line))) {
        endPara();
        var ordered = /\d/.test(m[2]);
        var list = { t: ordered ? 'ol' : 'ul', start: ordered ? parseInt(m[2], 10) : 1, items: [] };
        while (i < lines.length) {
          var lm = /^(\s*)([-*+•]|\d{1,3}[.)])\s+(.*)$/.exec(lines[i]);
          if (lm && (/\d/.test(lm[2]) === ordered || lm[1].length >= 2)) {
            // a nested item (indented) stays in this list, set in by an em space
            list.items.push(parseInline((lm[1].length >= 2 ? ' ' : '') + lm[3]));
            i++;
            continue;
          }
          // a wrapped line of the item above (indented, not blank, not a new block)
          if (list.items.length && /^\s{2,}\S/.test(lines[i]) && !/^\s*```/.test(lines[i])) {
            var last = list.items[list.items.length - 1];
            list.items[list.items.length - 1] = last.concat([{ t: 'br' }], parseInline(lines[i].trim()));
            i++;
            continue;
          }
          break;
        }
        out.push(list);
        continue;
      }
      para.push(line);
      i++;
    }
    endPara();
    return out;
  }

  /** The words of an answer as a person reads them (for Copy): markers' cards and markup taken out. */
  function plainText(text) {
    return str(text)
      .replace(/\[ZDZ_CONFIRM:[a-f0-9]{32}\]/g, '')
      .replace(/<!--[\s\S]*?-->/g, '')
      .replace(/\[ZANA_DM_DRAFT:[^\]]*\]|\[\/ZANA_DM_DRAFT\]|\[ZANA_POST_DRAFT:[^\]]*\]|\[\/ZANA_POST_DRAFT\]|\[\/?ZDZ_EMAIL_DRAFT[^\]]*\]/g, '')
      .replace(/<div class="zana-(?:charts|app)-chips">[\s\S]*?<\/div>/g, '')
      .replace(/\*\*([^*]+)\*\*/g, '$1')
      .replace(/__([^_]+)__/g, '$1')
      .replace(/`([^`]+)`/g, '$1')
      .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, '$1 ($2)')
      .replace(/^\s{0,3}#{1,6}\s+/gm, '')
      .replace(/\n{3,}/g, '\n\n')
      .trim();
  }

  /* ── the server's cards in an answer ───────────────────────────────────────────────────────────── */

  /** Buttons inside a chips block, matched exactly: [{kind:'chart'|'say'|'app', label, …}], at most 8. */
  function parseChips(html) {
    var items = [];
    var re = /<button\b([^<>]{0,25000})>([^<>]{1,200})<\/button>/g;
    var m;
    while ((m = re.exec(str(html))) && items.length < 8) {
      var attrs = {};
      var ar = /\b([a-z][a-z0-9-]{0,30})="([^"]{0,24000})"/g;
      var a;
      while ((a = ar.exec(m[1]))) { attrs[a[1]] = unentity(a[2]); }
      var cls = ' ' + (attrs['class'] || '') + ' ';
      var label = unentity(m[2]).replace(/\s+/g, ' ').trim().slice(0, 140);
      if (!label) continue;
      if (cls.indexOf(' zana-plan-route ') !== -1) {
        var say = str(attrs['data-say']).slice(0, 300);
        if (/^[^<>\[\]{}]{8,300}$/.test(say) && /\bfor me$/i.test(say)) items.push({ kind: 'say', label: label, say: say });
        continue;
      }
      if (cls.indexOf(' zana-charts-open ') !== -1) {
        var spec = attrs['data-zch'] || '';
        if (!/^[A-Za-z0-9_-]{2,6000}$/.test(spec)) continue;
        items.push({ kind: 'chart', label: label, spec: spec, mini: attrs['data-mini'] ? b64urlJson(attrs['data-mini']) : null, title: str(attrs.title).slice(0, 200) });
        continue;
      }
      if (cls.indexOf(' zana-rp-open ') !== -1) {
        var rp = rpOptions(b64urlJson(attrs['data-rp'], 600));
        if (rp) items.push({ kind: 'app', label: label, app: 'route-planner', options: rp });
        continue;
      }
      if (cls.indexOf(' zana-app-open ') !== -1) {
        var app = str(attrs['data-app']);
        var opts = cleanOpenOptions(b64urlJson(attrs['data-open'], 2000) || {});
        if (/^[a-z0-9-]{2,40}$/.test(app) && opts) items.push({ kind: 'app', label: label, app: app, options: opts });
      }
    }
    return items;
  }

  /** The Route Planner's Plan settings a chip may carry (the planner checks the person, area and day again). */
  function rpOptions(o) {
    if (!o || typeof o !== 'object' || Array.isArray(o) || o.mode !== 'plan') return null;
    var out = { mode: 'plan' };
    if (typeof o.area === 'string' && o.area.length && o.area.length <= 80 && /^[^<>"`{}\[\]\\]+$/.test(o.area)) out.area = o.area;
    if (typeof o.date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(o.date)) out.date = o.date;
    var n = parseInt(o.count, 10);
    out.count = (n >= 1 && n <= 5) ? n : 3;
    if (out.area && o.go === true) out.go = true;   // run at once only with an area, never with the one it remembers
    return out;
  }

  /** Settings an "Open in <app>" button hands the app: flat, short, plain values only. */
  function cleanOpenOptions(o) {
    if (!o || typeof o !== 'object' || Array.isArray(o)) return null;
    var out = {};
    var n = 0;
    for (var k in o) {
      if (!Object.prototype.hasOwnProperty.call(o, k) || n >= 12) continue;
      if (!/^[a-z][a-z0-9_]{0,23}$/.test(k)) continue;
      var v = o[k];
      if (typeof v === 'string') { if (v.length > 200 || /[<>`]/.test(v)) continue; }
      else if (typeof v === 'number') { if (!isFinite(v)) continue; }
      else if (typeof v !== 'boolean') { continue; }
      out[k] = v;
      n++;
    }
    out.source = 'chat';
    return out;
  }

  /** An email draft's parts: {to, subject, body} from "to: …", "subject: …", a "---" line, then the body. */
  function parseEmailDraft(raw) {
    var t = str(raw).replace(/^\s+|\s+$/g, '');
    var to = '', subject = '', body = t;
    var sep = /^\s*---\s*$/m.exec(t);
    if (sep) {
      var head = t.slice(0, sep.index);
      body = t.slice(sep.index + sep[0].length).replace(/^\s*\n?/, '');
      var tm = /^to:\s*(.+)$/mi.exec(head);
      var sm = /^subject:\s*(.+)$/mi.exec(head);
      if (tm) to = tm[1].trim();
      if (sm) subject = sm[1].trim();
    } else {
      var lines = t.split('\n');
      var end = 0;
      for (var i = 0; i < Math.min(lines.length, 6); i++) {
        var l = lines[i].trim();
        if (/^to:\s*.+/i.test(l)) { to = l.replace(/^to:\s*/i, '').trim(); end = i + 1; }
        else if (/^subject:\s*.+/i.test(l)) { subject = l.replace(/^subject:\s*/i, '').trim(); end = i + 1; }
        else if (l === '' && end > 0) { end = i + 1; break; }
        else if (end > 0) { break; }
      }
      if (end > 0) body = lines.slice(end).join('\n').replace(/^\s*\n?/, '');
    }
    return { to: to, subject: subject, body: body };
  }

  /** The addresses in a To line (each once, in order). */
  function emailAddresses(to) {
    var hits = str(to).match(/[^\s<>"',;:()\[\]]+@[^\s<>"',;:()\[\]]+\.[A-Za-z]{2,}/g) || [];
    var seen = {};
    var out = [];
    hits.forEach(function (a) { var k = a.toLowerCase(); if (!seen[k]) { seen[k] = true; out.push(a); } });
    return out;
  }

  /**
   * A mailto: for a draft, offered always: with no address it opens a filled-in draft the person addresses in their own
   * mail program. Markdown marks are taken out of the body first.
   */
  function mailtoFor(to, subject, body) {
    var addrs = emailAddresses(to);
    var plain = str(body).replace(/\[([^\]]+)\]\(([^)]+)\)/g, '$1 ($2)').replace(/\*\*([^*]+)\*\*/g, '$1').replace(/\*([^*]+)\*/g, '$1');
    var url = 'mailto:' + addrs.map(encodeURIComponent).join(',');
    var q = [];
    if (subject) q.push('subject=' + encodeURIComponent(subject));
    if (plain) q.push('body=' + encodeURIComponent(plain));
    return { url: url + (q.length ? '?' + q.join('&') : ''), hasAddress: addrs.length > 0 };
  }

  /** The number a document opener names ("#1001", "invoice 1001"); null when there is none. */
  function docRef(text, kind) {
    var k = kind === 'estimate' ? 'estimate' : (kind === 'invoice' ? 'invoice' : '');
    if (!k) return null;
    var t = str(text).replace(/\s+/g, ' ').trim();
    var n = /#\s*([A-Za-z0-9-]{1,20})\b/.exec(t) || /^(?:(?:invoice|estimate|inv|est)\.?\s*)?([0-9]{2,20})$/i.exec(t);
    if (!n || !/\d/.test(n[1])) return null;
    return { kind: k, number: n[1] };
  }

  /**
   * Which columns of a table name documents: a header that is "Invoice" or "Estimate" (or its plural, or with "#" or
   * "No."). [kind | '' per column]. A cell in such a column that is only "#<number>" opens that document.
   */
  function tableDocKinds(headTexts) {
    return (headTexts || []).map(function (h0) {
      var x = str(h0).trim().toLowerCase().replace(/\s*(?:#|no\.?|number)$/, '');
      if (/^invoices?$/.test(x)) return 'invoice';
      if (/^estimates?$/.test(x)) return 'estimate';
      return '';
    });
  }

  /** The text of inline tokens, for a table header or a cell. */
  function inlineText(toks) {
    return (toks || []).map(function (tk) {
      if (tk.t === 'text' || tk.t === 'code' || tk.t === 'chg') return tk.v;
      if (tk.c) return inlineText(tk.c);
      return '';
    }).join('');
  }

  /**
   * Take the server's cards out of an answer, leaving a place-holder line for each: {text, cards:[…], docs:[…]}.
   * Code fences are left exactly as written (an explanation of a marker never becomes a card).
   */
  function extractCards(text, origin) {
    var cards = [];
    var docs = [];
    function card(c) { cards.push(c); return '\n\n' + CARD + (cards.length - 1) + CARD_END + '\n\n'; }
    var src = str(text).slice(0, MAX_TEXT).replace(/[-]/g, '');
    var parts = src.split(/(```[\s\S]*?(?:```|$))/);
    for (var p = 0; p < parts.length; p++) {
      if (p % 2 === 1) continue;
      var s = parts[p];
      s = s.replace(/<!--ZANA_OPEN_PAGE:([\s\S]{2,2000}?)-->/g, function (all, json) {
        var o = null;
        try { o = JSON.parse(json); } catch (e) { o = null; }
        var url = o && typeof o === 'object' ? safeSiteUrl(o.url, origin) : '';
        if (!url) return '';
        return card({ k: 'page', url: url, title: str(o.title).replace(/[<>&]/g, '').slice(0, 80) || 'page', id: str(o.id).slice(0, 80) });
      });
      s = s.replace(/<!--[\s\S]*?-->/g, '');
      // a message the server holds for Send (its id), and one it did not hold (no id: never sendable from here)
      s = s.replace(/\[ZANA_DM_DRAFT:([a-f0-9]{32})\|@([^|\[\]\n]{1,100})\|([^\[\]\n]{0,120})\]([\s\S]{0,8000}?)\[\/ZANA_DM_DRAFT\]/g, function (all, id, who, name, body) {
        return card({ k: 'dm', id: id, target: who.trim(), name: name.trim(), body: str(body).trim() });
      });
      s = s.replace(/\[ZANA_DM_DRAFT:\s*@?([^|\[\]\n]{1,100})\]([\s\S]{0,8000}?)\[\/ZANA_DM_DRAFT\]/g, function (all, who, body) {
        return card({ k: 'dm', id: '', target: who.trim(), name: '', body: str(body).trim() });
      });
      s = s.replace(/\[ZANA_POST_DRAFT:#([a-z0-9_-]{1,60})\]([\s\S]{0,8000}?)\[\/ZANA_POST_DRAFT\]/gi, function (all, ch, body) {
        return card({ k: 'post', channel: ch.toLowerCase(), body: str(body).trim() });
      });
      s = s.replace(/\[ZDZ_EMAIL_DRAFT(?::([^\]\n]{0,80}))?\]([\s\S]{0,12000}?)\[\/ZDZ_EMAIL_DRAFT\]/g, function (all, name, raw) {
        var d = parseEmailDraft(raw);
        return card({ k: 'email', name: str(name).trim(), to: d.to, subject: d.subject, body: d.body });
      });
      s = s.replace(/<div class="zana-(?:charts|app)-chips">([\s\S]{0,60000}?)<\/div>/g, function (all, inner) {
        var items = parseChips(inner);
        return items.length ? card({ k: 'chips', items: items }) : '';
      });
      s = s.replace(/\[ZDZ_CONFIRM:([a-f0-9]{32})\]/g, function (all, id) { return card({ k: 'confirm', id: id }); });
      s = s.replace(/<a href="#" data-zana-doc-kind="(invoice|estimate)"[^<>]{0,200}>([^<>]{1,40})<\/a>/g, function (all, kind, words) {
        var ref = docRef(unentity(words), kind);
        if (!ref) return unentity(words);
        docs.push({ kind: ref.kind, number: ref.number, text: unentity(words) });
        return DOC + (docs.length - 1) + CARD_END;
      });
      s = s.replace(/<br\s*\/?>/gi, '\n');
      parts[p] = s;
    }
    return { text: parts.join(''), cards: cards, docs: docs };
  }

  /* ── the small chart under "Open in Charts" ────────────────────────────────────────────────────── */

  /** A short figure for a chart's axis: 1.2k, 34k, 1.5M; "$" first when the unit is money. */
  function fmtMini(v, unit) {
    var a = Math.abs(v);
    var t;
    if (a >= 1e6) t = (v / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
    else if (a >= 1e3) t = (v / 1e3).toFixed(a >= 1e4 ? 0 : 1).replace(/\.0$/, '') + 'k';
    else t = String(Math.round(v));
    return unit === '$' ? '$' + t : t;
  }

  /** Series shades: the brand for the first, then steps of the text colour (the theme's colours only). */
  var MINI_ALPHA = ['1', '0.55', '0.35', '0.22', '0.14', '0.08'];

  function num(x) { var n = +x; return isFinite(n) && n > 0 ? n : 0; }

  /**
   * The shapes of the small chart, from the rows a chip carries ({p: periods, s: [{g, v[]}], u, h}); null when there is
   * nothing to draw. Bars stacked per period (a line per series past 24 periods); with h, one bar per group.
   */
  function miniModel(m) {
    if (!m || typeof m !== 'object') return null;
    var p = Array.isArray(m.p) ? m.p.slice(0, 60).map(str) : [];
    var s = Array.isArray(m.s) ? m.s.slice(0, 6).filter(function (x) { return x && typeof x === 'object'; }) : [];
    var unit = str(m.u);
    if (m.h) {
      var vals = (s[0] && Array.isArray(s[0].v)) ? s[0].v : [];
      if (p.length < 2 || p.length > 15) return null;
      var mx = 0;
      for (var i = 0; i < p.length; i++) mx = Math.max(mx, num(vals[i]));
      if (!(mx > 0)) return null;
      var W = 320, LW = 118, R = 46, rowH = 16, T = 4, iw = W - LW - R;
      var rows = p.map(function (lab, j) {
        var v = num(vals[j]);
        var w = Math.max(0.5, iw * v / mx);
        return {
          label: lab.length > 22 ? lab.slice(0, 21) + '…' : lab,
          x: LW, y: T + rowH * j + 3, w: +w.toFixed(1), h: rowH - 6,
          ty: T + rowH * j + 11.5, value: fmtMini(v, unit), title: lab + ': ' + fmtMini(v, unit)
        };
      });
      return { kind: 'h', W: W, H: T + rowH * p.length + 4, LW: LW, rows: rows };
    }
    if (p.length < 2 || !s.length) return null;
    var VW = 320, VH = 118, L = 40, RR = 6, TT = 8, B = 20, w2 = VW - L - RR, ih = VH - TT - B;
    var totals = p.map(function (_, k) { return s.reduce(function (a, x) { return a + num(x.v && x.v[k]); }, 0); });
    var max = Math.max.apply(null, totals.concat([0]));
    if (!(max > 0)) return null;
    var line = p.length > 24;
    var step = w2 / p.length, bw = Math.max(1, step * 0.72);
    var series = s.map(function (x, si) {
      var out = { label: str(x.g).slice(0, 40), shade: si % MINI_ALPHA.length, alpha: MINI_ALPHA[si % MINI_ALPHA.length] };
      if (line) {
        out.points = p.map(function (_, k) {
          return (L + step * k + step / 2).toFixed(1) + ',' + (TT + ih - ih * num(x.v && x.v[k]) / max).toFixed(1);
        }).join(' ');
      } else {
        out.rects = [];
        for (var k = 0; k < p.length; k++) {
          var v = num(x.v && x.v[k]);
          if (!v) continue;
          var below = 0;
          for (var b = 0; b < si; b++) below += num(s[b].v && s[b].v[k]);
          var h = ih * v / max;
          out.rects.push({
            x: +(L + step * k + (step - bw) / 2).toFixed(1), y: +(TT + ih - ih * below / max - h).toFixed(1),
            w: +bw.toFixed(1), h: +Math.max(0.5, h).toFixed(1), title: p[k] + ' · ' + out.label + ': ' + fmtMini(v, unit)
          });
        }
      }
      return out;
    });
    var labels = [
      { x: L - 4, y: TT + 9, text: fmtMini(max, unit), anchor: 'end' },
      { x: L - 4, y: TT + ih, text: '0', anchor: 'end' },
      { x: L, y: VH - 6, text: p[0], anchor: 'start' },
      { x: L + w2, y: VH - 6, text: p[p.length - 1], anchor: 'end' }
    ];
    if (p.length > 2) labels.push({ x: L + w2 / 2, y: VH - 6, text: p[Math.floor(p.length / 2)], anchor: 'middle' });
    return { kind: line ? 'line' : 'bars', W: VW, H: VH, series: series, labels: labels, key: s.length > 1 };
  }

  /* ── starter questions ─────────────────────────────────────────────────────────────────────────── */

  /**
   * n items picked at random, each weighted (weights[key] in 0..1, default 1; 0 = only when nothing else is left):
   * weighted sampling without replacement (Efraimidis–Spirakis). rand is injectable for tests.
   */
  function weightedPick(items, n, keyOf, weights, rand) {
    rand = rand || Math.random;
    weights = weights || {};
    var scored = (items || []).map(function (it) {
      var w = weights[keyOf(it)];
      w = (typeof w === 'number' && w >= 0) ? Math.min(1, w) : 1;
      var r = rand();
      return { it: it, k: w > 0 ? Math.pow(r > 0 ? r : 1e-12, 1 / w) : -1 + r * 1e-6 };
    });
    scored.sort(function (a, b) { return b.k - a.k; });
    return scored.slice(0, Math.max(0, n | 0)).map(function (x) { return x.it; });
  }

  /* ── Confirm cards, turns, the docked thread ───────────────────────────────────────────────────── */

  /**
   * Has a held change's time run out? A card whose answer is older than the hold's life (plus a minute of slack for the
   * clocks) is drawn as expired: a Confirm button whose only answer is "that expired" is not offered.
   */
  function confirmExpired(createdAtMs, ttlS, nowMs) {
    var t = +createdAtMs;
    var ttl = +ttlS;
    if (!(t > 0) || !(ttl > 0)) return false;
    return (nowMs || Date.now()) > t + (ttl + 60) * 1000;
  }

  /** A time the server sends (ISO 8601 with its offset; a bare "2026-05-04 10:31:00" is read as UTC) in ms; 0 if unreadable. */
  function parseTime(v) {
    var s = str(v).trim();
    if (!s) return 0;
    var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/.exec(s);
    if (m && !/[zZ]|[+-]\d{2}:?\d{2}$/.test(s)) {
      return Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0));
    }
    var t = Date.parse(s);
    return isFinite(t) ? t : 0;
  }

  /**
   * How long to keep asking about a turn. A turn the server carried into a fresh request (attempt 2, 3, …) earns two
   * more minutes of asking for each new attempt.
   */
  function pollLimit(baseMs, attempt, extendedFor) {
    var a = parseInt(attempt, 10) || 1;
    var had = parseInt(extendedFor, 10) || 1;
    if (a > had) return { limit: baseMs + (a - 1) * 120000, extendedFor: a };
    return { limit: baseMs + (had - 1) * 120000, extendedFor: had };
  }

  /** The address of a direct-message thread drawn under the answer (the messaging app's one-thread view). */
  function dockSrc(home, cid) {
    var h = str(home || '/');
    if (h.slice(-1) !== '/') h += '/';
    var c = parseInt(cid, 10) || 0;
    return c > 0 ? h + '?zim_page=1&zdz_embed=dock&zim_compact=1&c=' + c : '';
  }

  var api = {
    unentity: unentity,
    b64decode: b64decode,
    b64urlJson: b64urlJson,
    chartsPrompt: chartsPrompt,
    safeHref: safeHref,
    safeSiteUrl: safeSiteUrl,
    isVaultHref: isVaultHref,
    parseInline: parseInline,
    parseBlocks: parseBlocks,
    plainText: plainText,
    parseChips: parseChips,
    rpOptions: rpOptions,
    cleanOpenOptions: cleanOpenOptions,
    parseEmailDraft: parseEmailDraft,
    emailAddresses: emailAddresses,
    mailtoFor: mailtoFor,
    docRef: docRef,
    tableDocKinds: tableDocKinds,
    inlineText: inlineText,
    extractCards: extractCards,
    fmtMini: fmtMini,
    miniModel: miniModel,
    weightedPick: weightedPick,
    confirmExpired: confirmExpired,
    parseTime: parseTime,
    pollLimit: pollLimit,
    dockSrc: dockSrc
  };
  if (typeof module === 'object' && module && module.exports) module.exports = api;
  if (root) root.ZanaChatKit = api;
})(typeof window !== 'undefined' ? window : null);
