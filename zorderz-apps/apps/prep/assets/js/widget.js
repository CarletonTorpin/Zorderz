/**
 * Zorderz Prep v2.1.9 — Prep Widget
 *
 * v2.1.9 changes:
 *   - Smarter partial-merge: in addition to stacking partial sheets vertically,
 *     the packer now fills a tall block's empty WIDTH strip with another whole
 *     partial as a clean side-by-side block (one rip between) — but only when it
 *     retires a whole sheet. A typical light job 7 → 6 sheets, every sheet ≤10%
 *     waste, no overlaps. See merge_partial_bins / fit_block_in_strip.
 *   - Debug mode reworked: the 🔍 toggle is now a PERMANENT control under the
 *     Compute button (always reachable — no URL needed; the prior version only
 *     injected it after a plan rendered). The report panel is a COLLAPSIBLE
 *     <details>, hidden until tapped, and now leads with a "Reasoning &
 *     trade-offs" section explaining why the search chose this layout (derived
 *     from the real decisions), above the raw metrics.
 *
 * Zorderz Prep v2.1.8 — Prep Widget
 *
 * v2.1.8 changes:
 *   - Packer reworked to a two-phase, block-based discipline (each type packed
 *     into clean single-type sheets; partial sheets merged ONLY as clean
 *     contiguous blocks, never interleaved) wrapped in a restored seeded
 *     Monte-Carlo search. Scoring (the shop's rule): fewest sheets → clean
 *     guillotine cuts (no T-junctions) → least waste (fill the width; shorter
 *     cuts OK) → prefer full-length cuts as a tie-breaker. See
 *     class-zprep-nesting.php.
 *   - NEW: interactive per-sheet layout EDITOR ("✎ Edit layout" on each sheet
 *     card). Click a piece to lift it, drag to move (snaps to a 0.5" grid + to
 *     neighboring edges), Ctrl/Alt/right-click to rotate 90° CW, Save to write
 *     back. Overlaps blocked at save; edits flow to print/leftovers/CRM
 *     because they all read pieces[]. (Phase 1: within one sheet.)
 *   - v2.1.7 (internal preview, not shipped): first leftover-strip fill.
 *   - NEW: 🔍 Debug mode (off by default; ?zprep_debug=1 to auto-enable). After a
 *     Compute it shows the packer's report — trials run, winning search params,
 *     score breakdown (sheets/T-junctions/waste/cuts), per-sheet metrics — with a
 *     Copy-report button for bug notes. Diagnostic only; doesn't change output.
 *
 * v2.1.6 changes:
 *   - PRINT ROOT CAUSE FIXED: doPrint() referenced an undefined `p`
 *     (`p.deliverables`) — a ReferenceError introduced in v2.1.3's pre-made print
 *     block — which threw and aborted the whole function before any print call,
 *     so "Print Cut Sheets" did nothing. Now reads state.plan.deliverables.
 *     Target browser is Safari (mobile + desktop web app), which supports
 *     window.print() normally, so with this fixed the overlay's Print button
 *     opens the real dialog; Open/Download remain as conveniences.
 *
 * v2.1.5 changes:
 *   - Print, take 2: the v2.1.4 hidden-iframe approach still relied on
 *     window.print()/contentWindow.print(), which an embedded WKWebView often
 *     makes a SILENT no-op (no print, no error) — so "Print" still looked dead.
 *     Print now opens a full-screen in-widget overlay that renders the sheets
 *     and offers Print + "Open in new tab" + Download. Open/Download always
 *     work even when the native print dialog is unavailable, so the button is
 *     never silent. (Deploy note: the live site was still on an OLD version —
 *     this fix only takes effect once the plugin is actually updated.)
 *
 * v2.1.4 changes:
 *   - Print FIXED: "Print cut sheets" now prints via a hidden same-document
 *     iframe instead of window.open('','_blank'), which the app webview was
 *     blocking (popup returned dead/null), so printing appeared to do nothing.
 *     Falls back to an HTML download, then a new tab, if the iframe path fails.
 *   - Orientation STANDARDIZED to landscape for ALL sheets (was 60"-only): the
 *     roll width (36"/60") is the SHORT vertical side and the cut length runs
 *     along the WIDE horizontal side, on screen and on print (page set to
 *     letter landscape). Maximizes printed area and keeps every sheet
 *     consistent.
 *
 * v2.1.3 changes:
 *   - pre-made round units are excluded from cut sheets — they're pre-made
 *     fire-resistant units, not cut from roll stock. They no longer appear on
 *     any sheet or consume roll length; instead they're listed as an "included,
 *     NOT cut" deliverable (on-screen badge, separate note section, and a
 *     callout on the final printed sheet).
 *   - White: the deliverables/cut plan reliably reflect white when the note
 *     says white (engine already honored per-piece color + auto→36" white;
 *     the AI prompt's colour cues were strengthened so "white" isn't missed).
 *
 * v2.1.2 changes:
 *   (Engine/integration-side, no widget UI change: fixes the "Finished — Send
 *    to CRM" failure — the completion NOTE payload used a `parent`/`entity`
 *    shape CRM rejected, and the activity log sent a null activity type.
 *    See class-zprep-crm.php. Also clears the implicit-nullable PHP 8.4
 *    deprecation warnings that were spamming the debug log.)
 *
 * v2.1.1 changes:
 *   - UI: the "Reading measurements from CRM…" loader is now a STAGED
 *     progress indicator (Reading → Parsing → Computing) with a per-stage
 *     spinner that resolves to a check plus a determinate bar, driven by the
 *     real pipeline points — replaces the static element that read as frozen.
 *     Pace is deliberately measured/calm (slower spinner + fades, ~1.2s minimum
 *     dwell per stage); it never fakes duration — completion waits on the real
 *     parse response.
 *   (Engine-side: the v2.1.0 Maximal-Rectangles packer is replaced by a
 *    guillotine strip-row packer that honors the shop's cutting preferences —
 *    same-type grouping, straight full-width cuts, long-strip ripping for
 *    repeats, left-hand bias — see class-zprep-nesting.php. The page data shape
 *    is UNCHANGED, so this renderer/print code is untouched: because the packer
 *    now emits left-aligned, full-width, single-type strip rows, the diagram
 *    automatically shows the straight edge-to-edge seams the operator cuts.)
 *
 * v2.1.0 changes:
 *   - UI: removed redundant in-body header (the dashboard bar already labels
 *     it "Prep"); fixed the title/header overlap and reclaimed dead space.
 *   - UI: removed the "Searches CRM first…" lookup hint and the
 *     "Prices are ignored by design" note; removed the AI confidence badge.
 *   - UI: animated loading skeleton while measurements load.
 *   - Diagrams: ONE uniform label font per sheet (and across sheets) so a
 *     22×17 and a 23×14 read at the same size — no more per-piece scaling.
 *   - Print: standardized scale. Every sheet drawn at one inches→paper ratio
 *     keyed to the workspace's representative field dimension, so "36 inches
 *     wide" looks identical on every page; long pieces oriented vertically.
 *   (Engine-side: shelf packer replaced by a 2-D bin-packing optimizer — see
 *    class-zprep-nesting.php. The page data shape is unchanged.)
 *
 * v2.0.3 changes (retained):
 *   - Print via clean popup window (no body class / dark mode breakage)
 *   - Material displayed as square footage, not dollars
 *   - "Cut Sheets" terminology replaces "Roll Pages"
 *   - Customer card shown on cut plan view
 *   - Scroll-to-widget on view transitions (no layout shift)
 *   - Bigger base fonts throughout
 *
 * Margin model (unchanged from v2.0.1):
 *   - Width: 0.5" margin on RIGHT side only. Usable = roll_w - 0.5.
 *   - Length: ZERO margin. Sheet length = sum of piece heights.
 */
(function () {
  'use strict';

  var PALETTE = {
    black: { meshBg: '#1a1a1a', cutLine: '#ffffff', label: '#ffffff', grungy: 'rgba(255,255,255,0.12)' },
    white: { meshBg: '#f0efe8', cutLine: '#222222', label: '#222222', grungy: 'rgba(0,0,0,0.08)' },
    pageBg: '#c8c8c8',
    /* v2.2.2: dimension callouts + margin caption + cut-here line draw on the
       PAGE backdrop, not the mesh — with black mesh their old color (white
       cutLine) hit 1.5:1 on #c8c8c8. One fixed dark ink: 12.6:1. */
    dim: '#1a1a1a'
  };

  var initialized = false;
  var state = {
    source: null, match: null, customer: null, leadId: null, cachedNotes: null,
    parsed: null, measurements: [], plan: null, useLeftovers: false, reservedLeftoverIds: [],
    pendingLeads: [], batchLeadIds: [], approvedJobs: [], appliedAdjustments: false, debug: false
  };

  function $(id) { return document.getElementById(id); }
  function esc(s) { var d = document.createElement('div'); d.textContent = (s == null) ? '' : String(s); return d.innerHTML; }
  function hide(el) { if (el) el.style.display = 'none'; }
  function show(el, type) { if (el) el.style.display = type || 'block'; }
  function post(action, data) {
    var body = new FormData();
    body.append('action', action);
    body.append('nonce', zprepWidgetData.nonce);
    Object.keys(data || {}).forEach(function (k) { var v = data[k]; body.append(k, (typeof v === 'object') ? JSON.stringify(v) : v); });
    return fetch(zprepWidgetData.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
  }
  function showView(id) {
    ['zprep-w-lookup', 'zprep-w-select', 'zprep-w-match', 'zprep-w-plan'].forEach(function (pid) {
      var el = $(pid); if (el) el.style.display = (pid === id) ? 'block' : 'none';
    });
    // v2.0.3 — Scroll widget into view on every view change to prevent layout shift
    var root = $('zprep-widget');
    if (root) { setTimeout(function () { root.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 60); }
  }
  function setErr(id, msg) { var el = $(id); if (!el) return; if (msg) { el.textContent = msg; el.style.display = 'block'; } else { el.textContent = ''; el.style.display = 'none'; } }
  function fmtIn(n) { if (n == null || isNaN(n)) return ''; n = Number(n); if (Math.abs(n - Math.round(n)) < 0.001) return String(Math.round(n)); return String(Number(n.toFixed(2))).replace(/\.?0+$/, ''); }

  /* ── APPROVED TO CUT (configured cut stage) ── */
  function loadApprovedToCut() {
    var listEl = $('zprep-w-atc-list');
    var emptyEl = $('zprep-w-atc-empty');
    var statusEl = $('zprep-w-atc-status');
    if (!listEl) return;
    hide(emptyEl);
    listEl.innerHTML = '';
    show(statusEl, 'flex');
    post('zprep_approved_to_cut', {}).then(function (res) {
      hide(statusEl);
      if (!res || !res.success) {
        // Fail silently-but-visibly: a calm note, never red error text, since
        // there's nothing the cutter can do about a CRM hiccup here.
        renderApprovedEmpty('Couldn’t load approved jobs right now. Use search below, or tap Reload.');
        return;
      }
      var d = res.data || {};
      if (d.configured === false) {
        // CRM not set up — hide the whole section quietly.
        var wrap = $('zprep-w-atc'); if (wrap) wrap.style.display = 'none';
        var div = document.querySelector('.zprep-w-atc-divider'); if (div) div.style.display = 'none';
        return;
      }
      var jobs = d.jobs || [];
      state.approvedJobs = jobs;
      renderApprovedToCut(jobs, d.stage || '');
      // v2.1.18 — role-aware health notice (e.g. billing token expired).
      renderHealthNotice(d.billing_health || 'off', !!d.is_admin);
    }).catch(function () {
      hide(statusEl);
      renderApprovedEmpty('Couldn’t load approved jobs right now. Use search below, or tap Reload.');
    });
  }

  function renderApprovedEmpty(msg) {
    var listEl = $('zprep-w-atc-list'); if (listEl) listEl.innerHTML = '';
    var moreEl = $('zprep-w-atc-more'); if (moreEl) { moreEl.style.display = 'none'; moreEl.onclick = null; }
    var emptyEl = $('zprep-w-atc-empty');
    if (emptyEl) { emptyEl.textContent = msg; show(emptyEl); }
  }

  // v2.1.18 — When a dependency is degraded, admins get actionable guidance;
  // everyone else gets a one-click "Report a problem" button (pre-filled). The
  // jobs themselves still showed (CRM is trusted); this only explains that
  // the billing cross-check was skipped.
  function renderHealthNotice(health, isAdmin) {
    var el = $('zprep-w-atc-notice');
    if (!el) return;
    el.innerHTML = '';
    if (health !== 'degraded') { el.style.display = 'none'; return; }

    if (isAdmin) {
      el.className = 'zprep-w-atc-notice zprep-w-atc-notice-admin';
      el.innerHTML =
        '<strong>Billing isn’t responding.</strong> Jobs below are from the CRM and are correct; ' +
        'the paid/billed cross-check was skipped. Reconnect billing in <em>Zorderz → Core Settings</em> ' +
        '(the access token looks expired/revoked), then tap Reload.';
      el.style.display = 'block';
    } else {
      el.className = 'zprep-w-atc-notice zprep-w-atc-notice-user';
      var msg = document.createElement('span');
      msg.textContent = 'Heads up: one of the systems (billing) isn’t responding, so this list may be missing a recently-finished job. You can still cut from it.';
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'zprep-w-atc-report';
      btn.textContent = 'Report a problem';
      btn.onclick = function () { reportProblem(btn, health); };
      el.appendChild(msg);
      el.appendChild(btn);
      el.style.display = 'block';
    }
  }

  // One-click, pre-filled problem report for non-admin cutters.
  function reportProblem(btn, health) {
    btn.disabled = true; btn.textContent = 'Sending…';
    var firstJob = (state.approvedJobs && state.approvedJobs[0]) || {};
    var who = (zprepWidgetData.user && zprepWidgetData.user.name) || 'A user';
    var detail = who + ' opened the Prep “Approved to Cut” list and a dependency (billing/billing) ' +
                 'was not responding, so the ready-to-cut list could not be cross-checked against billing. ' +
                 'Reported from the Prep widget.';
    post('zprep_report_problem', {
      context: 'Approved-to-Cut',
      detail: detail,
      lead_id: firstJob.lead_id || 0,
      health: health || ''
    }).then(function (res) {
      if (res && res.success) {
        btn.textContent = '✓ Reported';
        var note = $('zprep-w-atc-notice');
        if (note) {
          var done = document.createElement('div');
          done.className = 'zprep-w-atc-report-done';
          done.textContent = (res.data && res.data.message) || 'Thanks — your report was sent.';
          note.appendChild(done);
        }
      } else {
        btn.disabled = false; btn.textContent = 'Report a problem';
      }
    }).catch(function () { btn.disabled = false; btn.textContent = 'Report a problem'; });
  }

  // v2.1.17 — how many jobs to show before the "Show More" button.
  var ATC_VISIBLE = 3;

  function buildApprovedCard(j) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'zprep-w-atc-card';
    var name = j.customer || j.description || ('Lead #' + j.lead_id);
    var meta = [];
    if (j.estimate_number) meta.push('Est #' + esc(j.estimate_number));
    if (j.city) meta.push(esc(j.city));
    if (j.date) meta.push(esc(j.date));
    var metaHtml = meta.join('<span class="zprep-w-atc-sep">·</span>');
    var count = (j.cut_count > 0)
      ? ('<span class="zprep-w-atc-count">' + j.cut_count + ' piece' + (j.cut_count === 1 ? '' : 's') + '</span>')
      : '';
    // v2.2.0: billing-sourced entries (approved in billing; lead not in the cut
    // stage yet — promotion pending/failed). Badge instead of chevron; a tap
    // pre-fills the lookup box with the customer so the manual path (which
    // already worked for billing-only matches) is one tap away. planFromLead() would
    // no-op anyway (lead_id 0) — this makes the tap USEFUL instead of dead.
    var fbTag = j.fb_pending
      ? '<span class="zprep-w-atc-fbtag">' + esc(j.fb_note || 'Approved in billing') + '</span>'
      : '';
    btn.innerHTML =
      '<span class="zprep-w-atc-card-body">' +
        '<span class="zprep-w-atc-card-name">' + esc(name) + '</span>' +
        (metaHtml ? '<span class="zprep-w-atc-card-meta">' + metaHtml + '</span>' : '') +
        fbTag +
      '</span>' +
      count +
      (j.fb_pending ? '' : '<span class="zprep-w-atc-chev" aria-hidden="true">›</span>');
    btn.addEventListener('click', function () {
      if (j.fb_pending) {
        var box = $('zprep-w-lookup-input') || document.querySelector('#zprep-w-lookup input[type="text"], #zprep-w-lookup input[type="search"]');
        if (box) {
          box.value = j.customer || j.estimate_number || '';
          try { box.focus(); } catch (e) {}
          box.dispatchEvent(new Event('input', { bubbles: true }));
        }
        return;
      }
      planFromLead(j);
    });
    return btn;
  }

  function renderApprovedToCut(jobs, stageName) {
    var listEl = $('zprep-w-atc-list');
    var emptyEl = $('zprep-w-atc-empty');
    var moreEl = $('zprep-w-atc-more');
    if (!listEl) return;
    listEl.innerHTML = '';
    if (moreEl) { moreEl.style.display = 'none'; moreEl.onclick = null; }

    if (!jobs.length) {
      // Calm empty state (v2.1.17) — keep the lookup box below visible.
      renderApprovedEmpty('No approved jobs right now.');
      return;
    }
    hide(emptyEl);

    // Show the first ATC_VISIBLE (jobs already arrive newest-first), then a
    // "Show More" button reveals the rest in place.
    var shown = Math.min(ATC_VISIBLE, jobs.length);
    for (var i = 0; i < shown; i++) listEl.appendChild(buildApprovedCard(jobs[i]));

    var remaining = jobs.length - shown;
    if (remaining > 0 && moreEl) {
      moreEl.textContent = 'Show ' + remaining + ' more';
      moreEl.style.display = 'block';
      moreEl.onclick = function () {
        for (var k = shown; k < jobs.length; k++) listEl.appendChild(buildApprovedCard(jobs[k]));
        moreEl.style.display = 'none';
        moreEl.onclick = null;
      };
    }
  }

  // Tap a job -> load that lead straight into the parse->plan flow. We already
  // know the lead id, so the parse step fetches notes by id (no text search).
  function planFromLead(job) {
    if (!job || !job.lead_id) return;
    state.source = 'crm';
    state.leadId = job.lead_id;
    state.cachedNotes = null;           // force a fresh by-id fetch on the server
    state.batchLeadIds = [];
    state.customer = {
      name: job.customer || '',
      email: '', phone: '', address: '',
      estimate_number: job.estimate_number || '',
      salesperson: '', total: ''
    };
    renderMatch();
    startParse();
  }

  /* ── LOOKUP ── */
  function doLookup() {
    var q = $('zprep-w-search').value.trim();
    if (!q) { setErr('zprep-w-lookup-error', 'Please enter something.'); return; }
    setErr('zprep-w-lookup-error', '');
    show($('zprep-w-lookup-status'), 'flex');
    $('zprep-w-lookup-msg').textContent = 'Searching…';
    post('zprep_lookup', { query: q }).then(function (res) {
      hide($('zprep-w-lookup-status'));
      if (!res || !res.success) { setErr('zprep-w-lookup-error', (res && res.data && res.data.message) || 'Lookup failed.'); return; }
      var d = res.data;
      state.source = d.source || 'billing';

      if (d.source === 'crm_multi') {
        state.pendingLeads = d.leads || [];
        renderLeadPicker(d.query, d.leads);
        return;
      }

      if (d.source === 'crm') {
        state.leadId = d.lead_id; state.cachedNotes = d.notes || null; state.customer = d.customer || {};
        renderMatch(); startParse();
      } else {
        var matches = d.matches || []; if (!matches.length) { setErr('zprep-w-lookup-error', 'No jobs found.'); return; }
        state.match = matches[0];
        state.customer = { name: (state.match.customer_detail && state.match.customer_detail.name) || state.match.customer_name, email: (state.match.customer_detail && state.match.customer_detail.email) || '', phone: (state.match.customer_detail && state.match.customer_detail.phone) || '', address: (state.match.customer_detail && state.match.customer_detail.address) || '', estimate_number: state.match.number, salesperson: '', total: '' };
        renderMatch(); startParse();
      }
    }).catch(function (err) { hide($('zprep-w-lookup-status')); setErr('zprep-w-lookup-error', 'Network error: ' + err); });
  }

  function renderMatch() {
    var c = state.customer || {};
    $('zprep-w-customer').innerHTML = buildCustomerCardHtml();
    showView('zprep-w-match');
  }

  /* v2.0.3 — Build customer card HTML for reuse on plan view */
  function buildCustomerCardHtml() {
    var c = state.customer || {};
    return '<div class="zprep-w-customer-card"><div class="zprep-w-customer-name">' + esc(c.name || '(unknown)') + '</div><div class="zprep-w-customer-meta">' +
      (c.estimate_number ? '<span>Est #' + esc(c.estimate_number) + '</span>' : '') +
      (c.salesperson ? '<span class="zprep-w-sep">·</span><span>SP: ' + esc(c.salesperson) + '</span>' : '') +
      (c.total ? '<span class="zprep-w-sep">·</span><span>' + esc(c.total) + '</span>' : '') +
      '</div>' + (c.address ? '<div class="zprep-w-customer-addr">' + esc(c.address) + '</div>' : '') +
      (c.phone ? '<div class="zprep-w-customer-addr">☎ ' + esc(c.phone) + '</div>' : '') + '</div>';
  }

  /* ── LEAD PICKER (multi-result) ── */
  function renderLeadPicker(query, leads) {
    var sel = $('zprep-w-select');
    if (!sel) {
      sel = document.createElement('div');
      sel.id = 'zprep-w-select';
      sel.className = 'zprep-w-panel';
      var lookup = $('zprep-w-lookup');
      if (lookup && lookup.parentNode) lookup.parentNode.insertBefore(sel, lookup.nextSibling);
    }

    var html = '<div class="zprep-w-select-head">' +
      '<h3>' + leads.length + ' leads found for "' + esc(query) + '"</h3>' +
      '<p class="zprep-w-hint">Select a job to cut, or check multiple for a batch.</p>' +
      '</div><div class="zprep-w-select-list">';

    leads.forEach(function (l, idx) {
      html += '<div class="zprep-w-select-card" data-idx="' + idx + '">' +
        '<label class="zprep-w-select-check"><input type="checkbox" class="zprep-w-batch-cb" data-idx="' + idx + '"></label>' +
        '<div class="zprep-w-select-info" data-idx="' + idx + '">' +
          '<div class="zprep-w-select-name">' + esc(l.customer || 'Lead #' + l.lead_id) + '</div>' +
          '<div class="zprep-w-select-meta">' +
            (l.city ? '<span>' + esc(l.city) + '</span>' : '') +
            (l.date ? '<span class="zprep-w-sep">·</span><span>' + esc(l.date) + '</span>' : '') +
            (l.cut_count ? '<span class="zprep-w-sep">·</span><span>' + l.cut_count + ' pieces</span>' : '') +
            (l.estimate_num ? '<span class="zprep-w-sep">·</span><span>Est #' + esc(l.estimate_num) + '</span>' : '') +
          '</div>' +
        '</div>' +
      '</div>';
    });

    html += '</div>' +
      '<div class="zprep-w-actions">' +
        '<button id="zprep-w-batch-go" class="zprep-w-btn zprep-w-btn-primary zprep-w-btn-full" style="display:none;">Cut Selected Batch</button>' +
        '<button id="zprep-w-select-back" class="zprep-w-btn zprep-w-btn-secondary zprep-w-btn-full">Back to Search</button>' +
      '</div>';

    sel.innerHTML = html;
    showView('zprep-w-select');

    sel.querySelectorAll('.zprep-w-select-info').forEach(function (el) {
      el.addEventListener('click', function () {
        var idx = parseInt(el.getAttribute('data-idx'));
        selectSingleLead(leads[idx]);
      });
    });

    sel.querySelectorAll('.zprep-w-batch-cb').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var checked = sel.querySelectorAll('.zprep-w-batch-cb:checked');
        var batchBtn = $('zprep-w-batch-go');
        if (checked.length > 1) {
          batchBtn.textContent = 'Cut ' + checked.length + ' Jobs as Batch';
          batchBtn.style.display = '';
        } else {
          batchBtn.style.display = 'none';
        }
      });
    });

    var batchBtn = $('zprep-w-batch-go');
    if (batchBtn) {
      batchBtn.addEventListener('click', function () {
        var checked = sel.querySelectorAll('.zprep-w-batch-cb:checked');
        var selected = [];
        checked.forEach(function (cb) { selected.push(leads[parseInt(cb.getAttribute('data-idx'))]); });
        if (selected.length > 0) selectBatchLeads(selected);
      });
    }

    var backBtn = $('zprep-w-select-back');
    if (backBtn) backBtn.addEventListener('click', function () { showView('zprep-w-lookup'); });
  }

  function selectSingleLead(lead) {
    state.leadId = lead.lead_id;
    state.cachedNotes = lead.notes || null;
    state.customer = {
      name: lead.customer || '', email: '', phone: '',
      address: lead.city || '', estimate_number: lead.estimate_num || '',
      salesperson: '', total: ''
    };
    state.source = 'crm';
    renderMatch();
    startParse();
  }

  function selectBatchLeads(leads) {
    var allNotes = [];
    var leadIds = [];
    leads.forEach(function (l) {
      leadIds.push(l.lead_id);
      if (l.notes) allNotes = allNotes.concat(l.notes);
    });
    state.leadId = leadIds[0];
    state.batchLeadIds = leadIds;
    state.cachedNotes = allNotes;
    state.customer = {
      name: leads[0].customer || '', email: '', phone: '',
      address: leads[0].city || '', estimate_number: leads.map(function(l){ return l.estimate_num; }).filter(Boolean).join(', '),
      salesperson: '', total: ''
    };
    state.source = 'crm';
    renderMatch();
    startParse();
  }

  /* ── PARSE ── */
  // v2.1.1 — Staged progress loader. The old "skeleton + static line" read as
  // frozen ("that little half-circle doesn't animate"). This shows the real
  // pipeline as discrete stages — Reading → Parsing → (hand-off to) Computing —
  // each with a live spinner that resolves to a check, plus a determinate bar,
  // so the wait visibly reflects work in progress. Stage 1 begins immediately;
  // stage 2 flips the instant the parse request is actually in flight; the
  // final stage resolves when measurements are ready and the Compute step (the
  // genuine layout computation) takes over.
  var PARSE_STAGES = ['Reading notes from CRM', 'Parsing measurements', 'Computing layout'];

  function parseLoaderHTML() {
    var steps = '';
    for (var i = 0; i < PARSE_STAGES.length; i++) {
      steps += '<div class="zprep-w-stage" data-stage="' + i + '">' +
                 '<span class="zprep-w-stage-ic"><span class="zprep-w-stage-dot"></span></span>' +
                 '<span class="zprep-w-stage-lbl">' + esc(PARSE_STAGES[i]) + '</span>' +
               '</div>';
    }
    return '<div class="zprep-w-loader" role="status" aria-live="polite">' +
             '<div class="zprep-w-loader-bar"><i id="zprep-w-loader-fill"></i></div>' +
             '<div class="zprep-w-stages">' + steps + '</div>' +
           '</div>';
  }

  var CHECK_SVG = '<svg class="zprep-w-stage-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';

  // active = index currently working (spinner); done = number completed (checks).
  function setParseStage(active, done) {
    var fill = $('zprep-w-loader-fill');
    var stages = document.querySelectorAll('.zprep-w-stage');
    if (!stages.length) return;
    Array.prototype.forEach.call(stages, function (el) {
      var i = parseInt(el.getAttribute('data-stage'), 10);
      var ic = el.querySelector('.zprep-w-stage-ic');
      var complete = (i < done) || (done >= PARSE_STAGES.length);
      el.classList.toggle('is-active', i === active && !complete);
      el.classList.toggle('is-done', complete);
      if (complete)            ic.innerHTML = CHECK_SVG;
      else if (i === active)   ic.innerHTML = '<span class="zprep-w-stage-spin"></span>';
      else                     ic.innerHTML = '<span class="zprep-w-stage-dot"></span>';
    });
    if (fill) {
      var pct = (done >= PARSE_STAGES.length) ? 100 : ([12, 50, 84][active] || 0);
      fill.style.width = pct + '%';
    }
  }

  function startParse() {
    $('zprep-w-measurements').innerHTML = parseLoaderHTML();
    setParseStage(0, 0); // Stage 1: Reading — begins now.
    setErr('zprep-w-parse-error', ''); $('zprep-w-compute').disabled = true;

    var payload = { customer: state.customer };
    if (state.leadId) payload.lead_id = state.leadId;
    if (state.cachedNotes) payload.notes = state.cachedNotes;

    // Measured, calm pacing. Each early stage is held for a minimum dwell so
    // the sequence reads as deliberate rather than hurried — but it never fakes
    // duration: completion (stage 3 → editor) only happens once the real parse
    // response is in. If the backend is slower than the dwells, the loader
    // simply waits on it; if it's faster, we let the held stages finish calmly.
    var STAGE_READING_MS = 1900;  // hold "Reading" before advancing to "Parsing"
    var STAGE_PARSING_MS = 1900;  // minimum visible "Parsing" before the editor
    var t0 = Date.now();
    var parsingShownAt = null;

    var stillMounted = function () { return !!document.querySelector('.zprep-w-stage'); };

    // Stage 2: Parsing — after a calm beat on Reading (notes are gathered/
    // assembled before the call, so Reading genuinely precedes Parsing).
    setTimeout(function () {
      if (stillMounted()) { setParseStage(1, 1); parsingShownAt = Date.now(); }
    }, STAGE_READING_MS);

    var finish = function (apply) {
      if (!stillMounted()) { apply(); return; }
      // Ensure Parsing has been visible for its minimum dwell, measured from
      // whenever it actually appeared (it may not have shown yet on a fast net).
      var showParsingThenFinish = function () {
        var shownFor = parsingShownAt ? (Date.now() - parsingShownAt) : 0;
        var wait = Math.max(0, STAGE_PARSING_MS - shownFor);
        setTimeout(function () {
          setParseStage(2, 2);            // Stage 3 done → all checks, bar full
          setTimeout(apply, 600);          // let the final check settle, calmly
        }, wait);
      };
      if (parsingShownAt === null) {
        // Response beat the Reading dwell: show Parsing now, then hold it.
        setParseStage(1, 1); parsingShownAt = Date.now();
      }
      showParsingThenFinish();
    };

    post('zprep_parse_measurements', payload).then(function (res) {
      if (!res || !res.success) {
        finish(function () { $('zprep-w-measurements').innerHTML = ''; setErr('zprep-w-parse-error', (res && res.data && res.data.message) || 'Parse failed.'); });
        return;
      }
      state.leadId = (res.data && res.data.lead_id) || state.leadId;
      state.parsed = (res.data && res.data.parsed) || null;
      // v2.1.16 — did the server fold in salesperson adjustment notes?
      state.appliedAdjustments = !!(res.data && res.data.applied_adjustments);
      state.measurements = prepareEditableRows(state.parsed);
      finish(function () { renderMeasurementsEditor(); $('zprep-w-compute').disabled = false; });
    }).catch(function (err) {
      finish(function () { $('zprep-w-measurements').innerHTML = ''; setErr('zprep-w-parse-error', 'Network error: ' + err); });
    });
  }


  function prepareEditableRows(parsed) {
    if (!parsed || !Array.isArray(parsed.measurements)) return [];
    var map = {};
    parsed.measurements.forEach(function (m) {
      var key = [m.kind, m.shape, m.color, (m.width_in != null ? Number(m.width_in).toFixed(2) : 'null'), (m.height_in != null ? Number(m.height_in).toFixed(2) : 'null'), m.customer_install ? 'ci' : 'std'].join('|');
      if (!map[key]) { map[key] = { kind: m.kind, shape: m.shape, color: m.color, width_in: m.width_in, height_in: m.height_in, customer_install: !!m.customer_install, qty: 0, sides: {}, confidence: m.confidence, source_line: m.source_line || '', notes: m.notes || '' }; }
      map[key].qty += (m.qty || 1);
      var sl = m.side ? ('Side ' + m.side) : '(unspecified)';
      map[key].sides[sl] = (map[key].sides[sl] || 0) + (m.qty || 1);
    });
    return Object.keys(map).map(function (k) { return map[k]; });
  }

  function renderMeasurementsEditor() {
    var rows = state.measurements;
    if (!rows.length) { $('zprep-w-measurements').innerHTML = '<p class="zprep-w-hint">No measurements found in the note.</p>'; return; }
    var html = '';
    // v2.1.16 — let the cutter know the quantities reflect salesperson notes,
    // not just the base measurement block, so an adjusted count isn't a surprise.
    if (state.appliedAdjustments) {
      html += '<p class="zprep-w-hint zprep-w-adj-hint">✎ Salesperson adjustment note(s) applied — quantities reflect the notes on the lead, not just the base estimate. Check the per-row note and edit any value before computing.</p>';
    }
    html += '<div class="zprep-w-m-grid">';
    rows.forEach(function (r, i) {
      var dims = (r.width_in != null && r.height_in != null) ? fmtIn(r.width_in) + '" × ' + fmtIn(r.height_in) + '"' : 'needs dims';
      html += '<div class="zprep-w-m-row" data-idx="' + i + '"><div class="zprep-w-m-top"><span class="zprep-w-m-qty">' + esc(r.qty) + '×</span><span class="zprep-w-m-label">' + esc(kindLabel(r.kind)) + '</span><span class="zprep-w-m-dims">' + esc(dims) + '</span><span class="zprep-w-m-color zprep-w-m-color-' + esc(r.color) + '">' + esc(r.color) + '</span>' + (r.customer_install ? '<span class="zprep-w-flag-sm">CI</span>' : '') + '</div>' + (r.notes ? '<div class="zprep-w-m-notes">' + esc(r.notes) + '</div>' : '') + '</div>';
    });
    $('zprep-w-measurements').innerHTML = html + '</div>';
  }

  /* ── WORKSPACE + ROLL HELPERS ── */
  function getWorkspace() {
    var active = document.querySelector('#zprep-w-workspace-toggle .zprep-w-toggle-btn.active');
    return active ? (active.getAttribute('data-val') || 'flat') : 'flat';
  }
  function getForceRoll() {
    var sel = $('zprep-w-roll-select');
    if (!sel) return { width: 0, color: '' };
    var v = sel.value;
    if (v === '36b') return { width: 36, color: 'black' };
    if (v === '36w') return { width: 36, color: 'white' };
    if (v === '60b') return { width: 60, color: 'black' };
    return { width: 0, color: '' };
  }

  /* ── COMPUTE ── */
  function computeCuts() {
    if (!state.measurements.length) return;
    $('zprep-w-compute').disabled = true; $('zprep-w-compute').textContent = 'Computing…';
    var flatMeasurements = [];
    state.measurements.forEach(function (r) {
      Object.keys(r.sides).forEach(function (sl) {
        flatMeasurements.push({ kind: r.kind, qty: r.sides[sl], width_in: r.width_in, height_in: r.height_in, shape: r.shape, color: r.color, side: sl.replace('Side ', '').replace('(unspecified)', ''), customer_install: r.customer_install, source_line: r.source_line, notes: r.notes });
      });
    });
    state.useLeftovers = !!($('zprep-w-use-leftovers') && $('zprep-w-use-leftovers').checked);
    var sourceJob = (state.customer && state.customer.estimate_number) ? String(state.customer.estimate_number) : '';
    var ws = getWorkspace();
    var roll = getForceRoll();
    post('zprep_compute_cuts', {
      measurements: flatMeasurements,
      use_leftovers: state.useLeftovers ? '1' : '0',
      source_job: sourceJob,
      workspace: ws,
      force_roll: roll.width,
      debug: state.debug ? '1' : '0'
    }).then(function (res) {
      $('zprep-w-compute').disabled = false; $('zprep-w-compute').textContent = 'Compute Cut Plan';
      if (!res || !res.success) { setErr('zprep-w-parse-error', (res && res.data && res.data.message) || 'Compute failed.'); return; }
      state.plan = res.data;
      state.reservedLeftoverIds = (res.data.leftover_reservations || []).map(function (r) { return r.leftover_id; });
      renderPlan();
    }).catch(function (err) { $('zprep-w-compute').disabled = false; $('zprep-w-compute').textContent = 'Compute Cut Plan'; setErr('zprep-w-parse-error', 'Network error: ' + err); });
  }

  /* ── SQUARE FOOTAGE HELPER ── */
  function calcTotalSqFt(plan) {
    var total = 0;
    (plan.pages || []).forEach(function (page) {
      total += (page.roll_width_in * page.sheet_length) / 144;
    });
    return Math.round(total * 10) / 10;
  }
  function calcPageSqFt(page) {
    return Math.round((page.roll_width_in * page.sheet_length) / 144 * 10) / 10;
  }

  /* ── RENDER CUT PLAN ── */
  function renderPlan() {
    var p = state.plan; if (!p) return;
    showView('zprep-w-plan');

    var pages = p.pages || [];
    var totalSqFt = calcTotalSqFt(p);

    // v2.0.3 — Customer card at top of plan view
    var planSummaryHtml = buildCustomerCardHtml();

    // v2.0.3 — "Cut Sheets" instead of "Roll Pages", sq ft instead of dollars
    planSummaryHtml += '<div class="zprep-w-summary-grid"><div><strong>' + (p.summary.total_pieces || 0) + '</strong><span>Pieces</span></div><div><strong>' + pages.length + '</strong><span>Cut Sheet' + (pages.length !== 1 ? 's' : '') + '</span></div><div><strong>' + totalSqFt + '</strong><span>Sq Ft Used</span></div></div>' +
      (p.warnings && p.warnings.length ? '<div class="zprep-w-warnings">' + p.warnings.map(function(w){return '<div class="zprep-w-warn">' + esc(w) + '</div>';}).join('') + '</div>' : '');

    $('zprep-w-plan-summary').innerHTML = planSummaryHtml;

    // v2.1.0 — One uniform label font across all on-screen sheet cards so a
    // 22×17 and a 23×14 read at the same size from sheet to sheet.
    var screenFont = computeGlobalFont(pages);

    var pagesHtml = '';
    pages.forEach(function (page, idx) {
      var svg = renderNestingSVG(page, false, { fontIn: screenFont });
      var sqft = calcPageSqFt(page);
      var delHtml = (page.deliverables || []).map(function(d){ return '<div class="zprep-w-page-del">• ' + esc(d.qty) + ' × ' + esc(d.label) + (d.dims ? ' [' + esc(d.dims) + ']' : '') + '</div>'; }).join('');
      pagesHtml += '<div class="zprep-w-page-card"><div class="zprep-w-page-header"><strong>' + esc(page.roll_width_in) + '" ' + esc(page.color.toUpperCase()) + '</strong><span>Sheet ' + (idx+1) + ' of ' + pages.length + '</span></div><div class="zprep-w-page-meta">Cut ' + fmtIn(page.sheet_length) + '" (' + page.linear_feet.toFixed(1) + ' ft) · ' + page.piece_count + ' pcs · ' + sqft + ' sq ft</div><div class="zprep-w-diagram" tabindex="0" role="button" aria-label="Tap to enlarge">' + svg + '</div><div class="zprep-w-page-cardactions"><button type="button" class="zprep-w-editbtn" data-idx="' + idx + '">✎ Edit layout</button></div><div class="zprep-w-page-deliverables">' + delHtml + '</div></div>';
    });
    $('zprep-w-pages').innerHTML = pagesHtml;
    document.querySelectorAll('#zprep-w-pages .zprep-w-diagram').forEach(function(d){ d.addEventListener('click', function(){ openModal(d.innerHTML); }); });
    document.querySelectorAll('#zprep-w-pages .zprep-w-editbtn').forEach(function(b){ b.addEventListener('click', function(e){ e.stopPropagation(); openSheetEditor(parseInt(b.getAttribute('data-idx'), 10)); }); });

    var delFullHtml = (p.deliverables || []).map(function(d){
      var dimsStr = Object.keys(d.dimensions || {}).map(function(k){ return esc(k) + ' × ' + d.dimensions[k]; }).join(', ');
      var badge = d.not_cut ? '<span class="zprep-w-flag-sm zprep-w-flag-premade">PRE-MADE · NOT CUT</span>' : '';
      var reason = (d.not_cut && d.not_cut_reason) ? '<div class="zprep-w-deliverable-sub zprep-w-deliverable-note">' + esc(d.not_cut_reason) + '</div>' : '';
      return '<label class="zprep-w-deliverable' + (d.not_cut ? ' zprep-w-deliverable-notcut' : '') + '"><input type="checkbox"><div><strong>' + esc(d.qty) + ' × ' + esc(d.label) + '</strong>' + badge + (dimsStr ? '<div class="zprep-w-deliverable-sub">' + dimsStr + '</div>' : '') + reason + '</div></label>';
    }).join('');
    $('zprep-w-deliverables').innerHTML = delFullHtml;

    // v2.0.3 — Material table: sq ft, no dollar amounts
    var costHtml = '<table class="zprep-w-cost-table"><tbody>';
    (p.material_cost && p.material_cost.rolls || []).forEach(function(r){
      var rollW = 36;
      if (r.label.indexOf('60') !== -1) rollW = 60;
      var sqft = Math.round(r.feet * (rollW / 12) * 10) / 10;
      costHtml += '<tr><td>' + esc(r.label) + '</td><td>' + esc(r.feet) + ' ft</td><td>' + sqft + ' sq ft</td></tr>';
    });
    costHtml += '<tr class="zprep-w-cost-total"><td colspan="2">Total</td><td>' + totalSqFt + ' sq ft</td></tr></tbody></table>';
    $('zprep-w-cost').innerHTML = costHtml;

    renderDebugUI();
  }

  /* ═══════════════════════════════════════════════════════════════════
   * DEBUG MODE (v2.1.8)
   *
   * A toggle (off by default; also auto-on with ?zprep_debug=1 in the URL) that,
   * after a Compute, shows the packer's reasoning: trials run, time, the winning
   * search parameters, the score breakdown (sheets / T-junctions / waste / cuts),
   * and per-sheet metrics. A "Copy report" button copies a plain-text summary so
   * the user can paste exactly what happened when reporting a good/bad layout.
   * The panel is injected next to the sheet cards (no PHP-shell change needed).
   * ═══════════════════════════════════════════════════════════════════ */
  function renderDebugUI() {
    // Keep the persistent toggle's checkbox in sync with state (e.g. when
    // ?zprep_debug=1 pre-enabled it).
    var chk = $('zprep-w-debug-check');
    if (chk && chk.checked !== !!state.debug) chk.checked = !!state.debug;

    var pagesEl = $('zprep-w-pages'); if (!pagesEl || !pagesEl.parentNode) return;
    var host = pagesEl.parentNode;

    // Report panel (rebuilt each render). The toggle now lives in the shell
    // markup (under Compute), so it's always reachable — no URL needed.
    var panel = $('zprep-w-debug-panel'); if (panel) panel.remove();
    if (!state.debug) return;
    var dbg = state.plan && state.plan.debug;

    panel = document.createElement('details');
    panel.id = 'zprep-w-debug-panel';
    panel.className = 'zprep-w-debug-panel';
    // Collapsed by default — opens only when the user clicks the summary.
    panel.open = false;

    if (!dbg) {
      panel.innerHTML = '<summary class="zprep-w-debug-head">🔍 Packing report</summary>' +
        '<div class="zprep-w-debug-body">Recompute to generate the report (debug data is produced at Compute time).</div>';
      host.appendChild(panel);
      return;
    }

    // Reasoning HTML (rendered as readable bullets, above the raw metrics).
    var reasoningHtml = '';
    (dbg.groups || []).forEach(function (g) {
      if (g && g.ok && g.reasoning && g.reasoning.length) {
        reasoningHtml += '<div class="zprep-w-debug-rgroup">' + esc(g.group || '') + '</div>';
        g.reasoning.forEach(function (r) {
          reasoningHtml += '<div class="zprep-w-debug-rline"><span class="zprep-w-debug-rtag">' + esc((r.stage || '').toUpperCase()) + '</span>' + esc(r.note) + '</div>';
        });
      }
    });

    var report = buildDebugReport(dbg);
    panel.innerHTML =
      '<summary class="zprep-w-debug-head">🔍 Packing report &amp; reasoning <span class="zprep-w-debug-hint">(tap to open)</span></summary>' +
      '<div class="zprep-w-debug-inner">' +
        (reasoningHtml ? '<div class="zprep-w-debug-section">Reasoning &amp; trade-offs</div>' + reasoningHtml : '') +
        '<div class="zprep-w-debug-section">Full report <button type="button" id="zprep-w-debug-copy" class="zprep-w-debug-copy">Copy report</button></div>' +
        '<pre class="zprep-w-debug-body">' + esc(report) + '</pre>' +
      '</div>';
    host.appendChild(panel);
    $('zprep-w-debug-copy').addEventListener('click', function (e) {
      e.preventDefault();
      var t = report;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(t).then(function(){ flashCopied(); }, function(){ fallbackCopy(t); });
      } else { fallbackCopy(t); }
    });
  }

  function flashCopied() { var b = $('zprep-w-debug-copy'); if (!b) return; var o = b.textContent; b.textContent = 'Copied ✓'; setTimeout(function(){ b.textContent = o; }, 1500); }
  function fallbackCopy(t) { try { var ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); flashCopied(); } catch (e) {} }

  // Build a plain-text packing report the user can paste into a bug note.
  function buildDebugReport(dbg) {
    var L = [];
    L.push(((zprepWidgetData.contract && zprepWidgetData.contract.signature) || 'Prep') + ' — packing report');
    L.push('version: ' + (dbg.version || '?') + '  ·  workspace: ' + (dbg.workspace || '?') + '  ·  force_roll: ' + (dbg.force_roll || 'auto'));
    if (state.customer) L.push('job: ' + (state.customer.name || '') + (state.customer.estimate_number ? '  (Est #' + state.customer.estimate_number + ')' : ''));
    L.push('');
    (dbg.groups || []).forEach(function (g, gi) {
      if (!g || !g.ok) { L.push('group ' + (g && g.group ? g.group : gi) + ': ' + ((g && g.reason) || 'no layout')); L.push(''); return; }
      L.push('── group: ' + g.group + ' ──');
      L.push('pieces: ' + g.piece_count + ' in ' + g.type_count + ' type(s)  ·  usable width: ' + g.usable_w + '"  ·  max len: ' + g.max_len + '"');
      L.push('search: ' + g.trials_run + ' trials in ' + g.elapsed_ms + 'ms (seed ' + g.seed + ')');
      if (g.winner) L.push('winner: trial #' + g.winner.trial + ' [' + g.winner.order_kind + ']  orient=' + g.winner.orient_mode + '  strip-fill=' + (g.winner.strip_fill ? 'on' : 'off'));
      if (g.winner && g.winner.order) L.push('  order: ' + g.winner.order);
      var b = g.score_breakdown || {};
      L.push('score: ' + g.score + '  →  sheets=' + b.sheets + '  T-junctions=' + b.t_junctions + '  waste=' + b.waste_sqin + 'sqin  cuts=' + b.cuts + '  not-long-across=' + b.not_long_across);
      L.push('sheets (' + g.sheets + '):');
      (g.per_sheet || []).forEach(function (s, si) {
        L.push('  #' + (si + 1) + ': ' + s.pieces + 'pc [' + (s.types || []).join('+') + ']  len=' + s.len + '"  T-junc=' + s.t_junctions + '  cuts=' + s.cuts + '  waste=' + s.waste_pct + '%');
      });
      // Reasoning / thinking — why the search chose this layout, sheet by sheet.
      if (g.reasoning && g.reasoning.length) {
        L.push('');
        L.push('reasoning:');
        g.reasoning.forEach(function (r) {
          var tag = (r.stage || '').toUpperCase();
          L.push('  • [' + tag + '] ' + r.note);
        });
      }
      L.push('');
    });
    L.push('— end report —');
    return L.join('\n');
  }

  /**
   * v2.1.0 — Compute one label font size (in SVG inch-units) that fits every
   * piece across an array of pages. Used so labels are a consistent size both
   * within a sheet and across sheets. Mirrors the per-piece fit math in
   * renderNestingSVG, then takes the global minimum.
   */
  function computeGlobalFont(pages) {
    var f = 6.0;
    (pages || []).forEach(function (pg) {
      var is60 = true; // v2.1.4 — all sheets landscape (matches renderNestingSVG)
      (pg.pieces || []).forEach(function (p) {
        var pw = is60 ? p.h : p.w, ph = is60 ? p.w : p.h;
        var chars = String(p.label).length || 1;
        var rotate = ph > pw * 1.8;
        var byH = rotate ? pw * 0.55 : ph * 0.55;
        var byW = (rotate ? ph : pw) * 0.85 / (chars * 0.62);
        var fit = Math.min(byH, byW);
        if (fit < f) f = fit;
      });
    });
    return Math.max(0.8, Math.min(6.0, f));
  }

  /* ================================================================
   * SVG NESTING DIAGRAM — v2.1.0
   *
   * Two changes from v2.0.3:
   *   1. UNIFORM LABEL FONT per sheet. v2.0.3 sized each label to its own
   *      box, so a 22×17 rendered huge while a 23×14 rendered tiny on the
   *      same sheet — visually jarring and inconsistent. Now we compute the
   *      single largest font that fits EVERY label on the sheet (the min of
   *      each piece's max-fitting size) and draw them all at that one size.
   *   2. STANDARDIZED SCALE (print). opts.refDim is the job-wide reference
   *      dimension (the representative workspace/field dimension). When
   *      provided (print path), the SVG is rendered at a fixed inches→paper
   *      ratio so "36 inches wide" looks the same on every page, regardless
   *      of each sheet's length. On screen we still fill the card width.
   *
   * @param {Object}  page
   * @param {boolean} forPrint
   * @param {Object}  [opts]  { refDim?: number, refPaperIn?: number, fontIn?: number }
   * @returns {string} SVG markup
   */
  /* ═══════════════════════════════════════════════════════════════════
   * SHEET LAYOUT EDITOR (v2.1.8)
   *
   * Lets the user fine-tune one sheet's layout by hand: click a piece to lift
   * it, drag to reposition, Ctrl/Alt/right-click to rotate 90° CW, then click
   * (or Save) to keep. Placement SNAPS to a 0.5" grid and to existing piece
   * edges (best-practice alignment), and overlaps are blocked at save. Edits
   * write back to state.plan.pages[idx].pieces and recompute the sheet's
   * length/area, so print, leftovers, and CRM all use the adjusted layout.
   * Cancel/Esc reverts. (Coordinates are plan-space: x = across the width,
   * y = along the length. The editor draws landscape — same mapping as
   * renderNestingSVG — so x→vertical, y→horizontal on screen.)
   *
   * Scope (phase 1): drag/rotate WITHIN one sheet. Moving pieces between sheets
   * laid side-by-side is the next phase.
   * ═══════════════════════════════════════════════════════════════════ */
  var EDIT = null; // active editor state

  function openSheetEditor(idx) {
    if (!state.plan || !state.plan.pages || !state.plan.pages[idx]) return;
    var page = state.plan.pages[idx];
    var rollW = page.roll_width_in;
    var gm = page.grungy_margin || 0.5;
    var usableW = rollW - gm; // pieces live in across ∈ [0, usableW]
    var GRID = 0.5;

    // Deep-copy pieces so Cancel can revert cleanly.
    var working = (page.pieces || []).map(function (p) {
      return { x: +p.x, y: +p.y, w: +p.w, h: +p.h, label: p.label, kind: p.kind, rot: !!p.rot };
    });

    EDIT = { idx: idx, page: page, rollW: rollW, gm: gm, usableW: usableW, grid: GRID,
             pieces: working, sel: -1, maxLen: null };

    // Length cap for this roll (match engine: 36"→60", 60"→48").
    EDIT.maxLen = (rollW >= 60) ? 48 : 60;

    var html =
      '<div class="zprep-ed">' +
        '<div class="zprep-ed-head">' +
          '<strong>Edit layout — Sheet ' + (idx + 1) + ' (' + esc(rollW) + '" ' + esc(String(page.color).toUpperCase()) + ')</strong>' +
          '<div class="zprep-ed-hint">Click a piece to lift it · drag to move · double-click (or Ctrl/Alt/right-click) to rotate 90° · click again to drop</div>' +
        '</div>' +
        '<div id="zprep-ed-canvas" class="zprep-ed-canvas"></div>' +
        '<div class="zprep-ed-actions">' +
          '<button type="button" id="zprep-ed-rotate" class="zprep-w-btn zprep-w-btn-secondary" disabled>↻ Rotate 90°</button>' +
          '<button type="button" id="zprep-ed-reset" class="zprep-w-btn zprep-w-btn-secondary">Reset</button>' +
          '<span class="zprep-ed-spacer"></span>' +
          '<button type="button" id="zprep-ed-cancel" class="zprep-w-btn zprep-w-btn-secondary">Cancel</button>' +
          '<button type="button" id="zprep-ed-save" class="zprep-w-btn zprep-w-btn-primary">Save layout</button>' +
        '</div>' +
        '<div id="zprep-ed-warn" class="zprep-ed-warn"></div>' +
      '</div>';
    openModal(html);

    drawEditor();

    $('zprep-ed-rotate').addEventListener('click', function () { if (EDIT.sel >= 0) rotateSelected(); });
    $('zprep-ed-reset').addEventListener('click', function () {
      EDIT.pieces = (EDIT.page.pieces || []).map(function (p) { return { x:+p.x,y:+p.y,w:+p.w,h:+p.h,label:p.label,kind:p.kind,rot:!!p.rot }; });
      EDIT.sel = -1; drawEditor();
    });
    $('zprep-ed-cancel').addEventListener('click', function () { EDIT = null; closeModal(); });
    $('zprep-ed-save').addEventListener('click', saveEditor);
  }

  function edMetrics() {
    var maxLen = 0, cnt = 0;
    EDIT.pieces.forEach(function (p) { maxLen = Math.max(maxLen, p.y + p.h); cnt++; });
    return { len: Math.round(maxLen * 100) / 100, count: cnt };
  }

  function drawEditor() {
    var pad = 4;
    var m = edMetrics();
    var drawLen = Math.max(m.len, 6);
    var drawW = drawLen, drawH = EDIT.rollW; // landscape
    var svgW = drawW + pad * 2, svgH = drawH + pad * 2;
    var pal = PALETTE[EDIT.page.color] || PALETTE.black;
    var fontIn = Math.max(1.2, Math.min(3.0, EDIT.rollW / 12));

    var s = '<svg id="zprep-ed-svg" viewBox="0 0 ' + svgW + ' ' + svgH + '" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:auto;display:block;touch-action:none;background:' + PALETTE.pageBg + ';">';
    s += '<rect x="' + pad + '" y="' + pad + '" width="' + drawW + '" height="' + drawH + '" fill="' + pal.meshBg + '" stroke="' + pal.cutLine + '" stroke-width="0.2"/>';
    s += '<rect x="' + pad + '" y="' + (pad + EDIT.usableW) + '" width="' + drawW + '" height="' + EDIT.gm + '" fill="' + pal.grungy + '"/>';
    for (var gx = 0; gx <= drawLen + 1e-6; gx += EDIT.grid * 4) {
      s += '<line x1="' + (pad + gx) + '" y1="' + pad + '" x2="' + (pad + gx) + '" y2="' + (pad + EDIT.rollW) + '" stroke="' + pal.cutLine + '" stroke-width="0.03" opacity="0.18"/>';
    }
    for (var gy = 0; gy <= EDIT.rollW + 1e-6; gy += EDIT.grid * 4) {
      s += '<line x1="' + pad + '" y1="' + (pad + gy) + '" x2="' + (pad + drawLen) + '" y2="' + (pad + gy) + '" stroke="' + pal.cutLine + '" stroke-width="0.03" opacity="0.18"/>';
    }
    EDIT.pieces.forEach(function (p, i) {
      var rx = pad + p.y, ry = pad + p.x, rw = p.h, rh = p.w;
      var selected = (i === EDIT.sel);
      var fill = selected ? '#D4881C' : pal.meshBg;
      var fop = selected ? '0.35' : '1';
      s += '<g class="zprep-ed-piece" data-i="' + i + '" style="cursor:grab;">';
      s += '<rect x="' + rx + '" y="' + ry + '" width="' + rw + '" height="' + rh + '" fill="' + fill + '" fill-opacity="' + fop + '" stroke="' + (selected ? '#D4881C' : pal.cutLine) + '" stroke-width="' + (selected ? '0.4' : '0.15') + '"/>';
      var cx = rx + rw / 2, cy = ry + rh / 2, vert = rh > rw * 1.4;
      var t = vert ? ' transform="rotate(-90 ' + cx + ' ' + cy + ')"' : '';
      s += '<text x="' + cx + '" y="' + cy + '" font-family="Inter,sans-serif" font-size="' + fontIn.toFixed(2) + '" font-weight="800" fill="' + pal.label + '" text-anchor="middle" dominant-baseline="middle"' + t + '>' + esc(p.label) + '</text>';
      s += '</g>';
    });
    s += '<text x="' + (pad - 1.6) + '" y="' + (pad + EDIT.rollW / 2) + '" font-family="Inter" font-size="1.4" font-weight="700" fill="' + PALETTE.dim + '" text-anchor="middle" transform="rotate(-90 ' + (pad - 1.6) + ' ' + (pad + EDIT.rollW / 2) + ')">' + fmtIn(EDIT.rollW) + '" wide</text>';
    s += '<text x="' + (pad + drawLen / 2) + '" y="' + (pad - 1.4) + '" font-family="Inter" font-size="1.4" font-weight="700" fill="' + PALETTE.dim + '" text-anchor="middle">Cut ' + fmtIn(m.len) + '"</text>';
    s += '</svg>';

    $('zprep-ed-canvas').innerHTML = s;
    $('zprep-ed-rotate').disabled = (EDIT.sel < 0);

    var svg = $('zprep-ed-svg');
    svg.querySelectorAll('.zprep-ed-piece').forEach(function (g) {
      g.addEventListener('pointerdown', onPiecePointerDown);
      g.addEventListener('dblclick', function (e) { e.preventDefault(); EDIT.sel = parseInt(g.getAttribute('data-i'), 10); rotateSelected(); });
      g.addEventListener('contextmenu', function (e) { e.preventDefault(); EDIT.sel = parseInt(g.getAttribute('data-i'), 10); rotateSelected(); });
    });
  }

  function edPointToPlan(evt) {
    var svg = $('zprep-ed-svg'); if (!svg) return null;
    var pt = svg.createSVGPoint(); pt.x = evt.clientX; pt.y = evt.clientY;
    var ctm = svg.getScreenCTM(); if (!ctm) return null;
    var loc = pt.matrixTransform(ctm.inverse());
    var pad = 4;
    return { along: loc.x - pad, across: loc.y - pad };
  }

  var edDrag = null;
  function onPiecePointerDown(e) {
    e.preventDefault();
    var i = parseInt(e.currentTarget.getAttribute('data-i'), 10);
    EDIT.sel = i;
    var p = EDIT.pieces[i];
    var pl = edPointToPlan(e);
    edDrag = { i: i, grabAlong: pl ? (pl.along - p.y) : 0, grabAcross: pl ? (pl.across - p.x) : 0, moved: false };
    drawEditor();
    window.addEventListener('pointermove', onEdPointerMove);
    window.addEventListener('pointerup', onEdPointerUp);
  }

  function onEdPointerMove(e) {
    if (!edDrag) return;
    var pl = edPointToPlan(e); if (!pl) return;
    var p = EDIT.pieces[edDrag.i];
    var rawx = pl.across - edDrag.grabAcross;
    var rawy = pl.along - edDrag.grabAlong;
    // Edge-snap first (flush contact / alignment wins over grid quantization).
    var snapped = edEdgeSnap(edDrag.i, rawx, rawy);
    var nx = snapped.x, ny = snapped.y;
    // If an axis didn't catch an edge, fall back to the 0.5" grid on that axis.
    if (Math.abs(nx - rawx) < 1e-6) nx = Math.round(rawx / EDIT.grid) * EDIT.grid;
    if (Math.abs(ny - rawy) < 1e-6) ny = Math.round(rawy / EDIT.grid) * EDIT.grid;
    nx = Math.max(0, Math.min(nx, EDIT.usableW - p.w));
    ny = Math.max(0, Math.min(ny, EDIT.maxLen - p.h));
    p.x = Math.round(nx * 100) / 100; p.y = Math.round(ny * 100) / 100;
    edDrag.moved = true;
    drawEditor();
    edShowOverlapWarning();
  }

  function onEdPointerUp() {
    window.removeEventListener('pointermove', onEdPointerMove);
    window.removeEventListener('pointerup', onEdPointerUp);
    edDrag = null;
    edShowOverlapWarning();
  }

  // Snap the moving piece so it sits FLUSH against neighbors (no gap, no
  // overlap) and aligns to shared edges. We consider, on each axis:
  //   • CONTACT: my far edge → neighbor's near edge, and my near edge →
  //     neighbor's far edge (pieces butt together edge-to-edge);
  //   • ALIGN:   my near edge → neighbor's near edge, my far edge → far edge;
  //   • ORIGIN:  my near edge → 0 (sheet edge).
  // The closest candidate within TOL wins on each axis; TOL is larger than the
  // grid step so a deliberate "almost touching" drag clicks into contact rather
  // than leaving a half-grid gap.
  function edEdgeSnap(i, nx, ny) {
    var TOL = 1.0; // inches; > grid (0.5) so contact beats grid quantization
    var p = EDIT.pieces[i];

    // Across axis (x): candidate positions for p.x.
    var xCand = [ 0 ];                                  // sheet left edge
    // Along axis (y): candidate positions for p.y.
    var yCand = [ 0 ];

    EDIT.pieces.forEach(function (q, j) {
      if (j === i) return;
      // Only snap to a neighbor on a given axis if the pieces actually overlap
      // on the OTHER axis (so they could touch), within a little slack.
      var overX = (p.x < q.x + q.w + TOL) && (p.x + p.w > q.x - TOL);
      var overY = (p.y < q.y + q.h + TOL) && (p.y + p.h > q.y - TOL);

      if (overY) {
        // place my left at q.right (contact), my right at q.left (contact),
        // my left at q.left (align), my right at q.right (align)
        xCand.push(q.x + q.w);          // contact: my left edge butts q's right
        xCand.push(q.x - p.w);          // contact: my right edge butts q's left
        xCand.push(q.x);                // align lefts
        xCand.push(q.x + q.w - p.w);    // align rights
      }
      if (overX) {
        yCand.push(q.y + q.h);          // contact: my top butts q's bottom
        yCand.push(q.y - p.h);          // contact: my bottom butts q's top
        yCand.push(q.y);                // align tops
        yCand.push(q.y + q.h - p.h);    // align bottoms
      }
    });

    nx = edClosest(nx, xCand, TOL);
    ny = edClosest(ny, yCand, TOL);
    return { x: nx, y: ny };
  }

  // Return the candidate closest to v within tol, else v unchanged.
  function edClosest(v, cands, tol) {
    var best = v, bestd = tol + 1e-9;
    for (var k = 0; k < cands.length; k++) {
      var c = cands[k];
      if (c < -1e-6) continue;            // never snap to a negative position
      var d = Math.abs(v - c);
      if (d < bestd) { bestd = d; best = c; }
    }
    return best;
  }

  function rotateSelected() {
    var p = EDIT.pieces[EDIT.sel]; if (!p) return;
    var nw = p.h, nh = p.w;
    if (nw > EDIT.usableW + 1e-6) { edWarn('Can\'t rotate: piece would exceed the ' + fmtIn(EDIT.usableW) + '" usable width.'); return; }
    p.w = nw; p.h = nh; p.rot = !p.rot;
    p.x = Math.max(0, Math.min(p.x, EDIT.usableW - p.w));
    p.y = Math.max(0, Math.min(p.y, EDIT.maxLen - p.h));
    p.x = Math.round(p.x * 100) / 100; p.y = Math.round(p.y * 100) / 100;
    drawEditor();
    edShowOverlapWarning();
  }

  function edOverlaps() {
    var bad = [];
    var P = EDIT.pieces;
    for (var a = 0; a < P.length; a++) for (var b = a + 1; b < P.length; b++) {
      var ox = Math.min(P[a].x + P[a].w, P[b].x + P[b].w) - Math.max(P[a].x, P[b].x);
      var oy = Math.min(P[a].y + P[a].h, P[b].y + P[b].h) - Math.max(P[a].y, P[b].y);
      if (ox > 1e-6 && oy > 1e-6) bad.push([a, b]);
    }
    return bad;
  }

  function edShowOverlapWarning() {
    var bad = edOverlaps();
    if (bad.length) edWarn(bad.length + ' overlapping piece' + (bad.length > 1 ? 's' : '') + ' — adjust before saving.');
    else { var w = $('zprep-ed-warn'); if (w) w.textContent = ''; }
  }

  function edWarn(msg) { var w = $('zprep-ed-warn'); if (w) w.textContent = msg; }

  function saveEditor() {
    if (edOverlaps().length) { edWarn('Resolve overlaps before saving.'); return; }
    var oob = EDIT.pieces.some(function (p) {
      return p.x < -1e-6 || p.x + p.w > EDIT.usableW + 1e-6 || p.y < -1e-6 || p.y + p.h > EDIT.maxLen + 1e-6;
    });
    if (oob) { edWarn('Some pieces are out of bounds.'); return; }

    var page = state.plan.pages[EDIT.idx];
    var m = edMetrics();
    page.pieces = EDIT.pieces.map(function (p) { return { x:p.x,y:p.y,w:p.w,h:p.h,label:p.label,kind:p.kind,rot:!!p.rot }; });
    page.piece_count = m.count;
    page.sheet_length = m.len;
    page.linear_feet = Math.round((m.len / 12) * 100) / 100;
    page.edited = true;

    EDIT = null;
    closeModal();
    renderPlan();
  }

  function renderNestingSVG(page, forPrint, opts) {
    opts = opts || {};
    var rollW = page.roll_width_in;
    var sheetLen = page.sheet_length;
    var color = page.color;
    var gm = page.grungy_margin || 0.5;
    var pal = PALETTE[color] || PALETTE.black;
    // v2.1.4 — ALL sheets render landscape: the roll-width dimension (36"/60")
    // is the SHORT/vertical side of the page and the cut-length runs along the
    // WIDE/horizontal side. This maximizes printed area (long sheets fill the
    // page width) and standardizes orientation across 36" and 60" alike, on
    // screen and on print. (Previously only 60" rolls were rotated this way.)
    var is60 = true;

    var pad = forPrint ? 5 : 3.5;

    var drawW, drawH;
    if (is60) { drawW = sheetLen; drawH = rollW; }
    else      { drawW = rollW;    drawH = sheetLen; }
    var svgW = drawW + pad * 2;
    var svgH = drawH + pad * 2;

    var pieces = page.pieces || [];

    // ── UNIFORM LABEL FONT (one size for the whole sheet) ──
    // For each piece, find the largest font (in SVG inch-units) that fits
    // both its width and height (accounting for rotation when tall+narrow),
    // then take the smallest across all pieces so every label fits.
    var CHAR_W = 0.62;          // bold sans-serif advance ratio
    var FONT_MIN = 0.8, FONT_MAX = 6.0;
    var uniformFont = (opts.fontIn != null) ? opts.fontIn : FONT_MAX;
    if (opts.fontIn == null) {
      pieces.forEach(function (p) {
        var pw = is60 ? p.h : p.w;
        var ph = is60 ? p.w : p.h;
        var chars = String(p.label).length || 1;
        var rotate = ph > pw * 1.8;
        var byH, byW;
        if (rotate) { byH = pw * 0.55; byW = (ph * 0.85) / (chars * CHAR_W); }
        else        { byH = ph * 0.55; byW = (pw * 0.85) / (chars * CHAR_W); }
        var fit = Math.min(byH, byW);
        if (fit < uniformFont) uniformFont = fit;
      });
      uniformFont = Math.max(FONT_MIN, Math.min(FONT_MAX, uniformFont));
    }

    // ── Rendered width: standardized scale on print, fill-width on screen ──
    var styleW = 'width:100%';
    if (forPrint && opts.refDim && opts.refPaperIn) {
      // inches of paper per inch of material, fixed for the whole job.
      var ratio = opts.refPaperIn / opts.refDim;
      var paperW = (svgW * ratio);
      styleW = 'width:' + paperW.toFixed(3) + 'in';
    }

    var svg = '<svg viewBox="0 0 ' + svgW + ' ' + svgH + '" xmlns="http://www.w3.org/2000/svg" style="background:' + PALETTE.pageBg + ';' + styleW + ';display:block;" aria-label="Cut plan">';

    // Roll rectangle
    svg += '<rect x="' + pad + '" y="' + pad + '" width="' + drawW + '" height="' + drawH + '" fill="' + pal.meshBg + '" stroke="' + pal.cutLine + '" stroke-width="0.2"/>';

    // Margin zone
    if (is60) {
      svg += '<rect x="' + pad + '" y="' + (pad + rollW - gm) + '" width="' + drawW + '" height="' + gm + '" fill="' + pal.grungy + '"/>';
      svg += '<text x="' + (pad + drawW + 0.5) + '" y="' + (pad + rollW - gm/2) + '" font-family="Inter,Helvetica,sans-serif" font-size="1.2" fill="' + PALETTE.dim + '" opacity="0.75" dominant-baseline="middle">½" margin</text>';
    } else {
      svg += '<rect x="' + (pad + rollW - gm) + '" y="' + pad + '" width="' + gm + '" height="' + drawH + '" fill="' + pal.grungy + '"/>';
    }

    // Pieces — all labels at the single uniform font size.
    pieces.forEach(function (p) {
      var px, py, pw, ph;
      if (is60) { px = pad + p.y; py = pad + p.x; pw = p.h; ph = p.w; }
      else      { px = pad + p.x; py = pad + p.y; pw = p.w; ph = p.h; }

      svg += '<rect x="' + px + '" y="' + py + '" width="' + pw + '" height="' + ph + '" fill="' + pal.meshBg + '" stroke="' + pal.cutLine + '" stroke-width="0.15"/>';

      var label = esc(p.label);
      var cx = px + pw / 2;
      var cy = py + ph / 2;
      var doRotate = ph > pw * 1.8;
      var fs = uniformFont.toFixed(2);

      if (doRotate) {
        svg += '<text x="' + cx + '" y="' + cy + '" font-family="Inter,Helvetica,sans-serif" font-size="' + fs + '" font-weight="900" fill="' + pal.label + '" text-anchor="middle" dominant-baseline="middle" transform="rotate(-90 ' + cx + ' ' + cy + ')">' + label + '</text>';
      } else {
        svg += '<text x="' + cx + '" y="' + cy + '" font-family="Inter,Helvetica,sans-serif" font-size="' + fs + '" font-weight="900" fill="' + pal.label + '" text-anchor="middle" dominant-baseline="middle">' + label + '</text>';
      }
    });

    // Dimension callouts
    var dimFont = forPrint ? 1.4 : 1.6;
    if (is60) {
      svg += '<text x="' + (pad - 1.5) + '" y="' + (pad + rollW/2) + '" font-family="Inter,Helvetica,sans-serif" font-size="' + dimFont + '" font-weight="700" fill="' + PALETTE.dim + '" text-anchor="middle" transform="rotate(-90 ' + (pad-1.5) + ' ' + (pad+rollW/2) + ')">' + fmtIn(rollW) + '" wide</text>';
      svg += '<text x="' + (pad + sheetLen/2) + '" y="' + (pad - 1.5) + '" font-family="Inter,Helvetica,sans-serif" font-size="' + dimFont + '" font-weight="700" fill="' + PALETTE.dim + '" text-anchor="middle">Cut ' + fmtIn(sheetLen) + '"</text>';
    } else {
      svg += '<text x="' + (pad + rollW/2) + '" y="' + (pad - 1.5) + '" font-family="Inter,Helvetica,sans-serif" font-size="' + dimFont + '" font-weight="700" fill="' + PALETTE.dim + '" text-anchor="middle">' + fmtIn(rollW) + '" wide</text>';
      svg += '<text x="' + (pad - 1.5) + '" y="' + (pad + sheetLen/2) + '" font-family="Inter,Helvetica,sans-serif" font-size="' + dimFont + '" font-weight="700" fill="' + PALETTE.dim + '" text-anchor="middle" transform="rotate(-90 ' + (pad-1.5) + ' ' + (pad+sheetLen/2) + ')">Cut ' + fmtIn(sheetLen) + '"</text>';
    }

    // Cut-here dashed line
    if (is60) {
      svg += '<line x1="' + (pad + sheetLen) + '" y1="' + pad + '" x2="' + (pad + sheetLen) + '" y2="' + (pad + rollW) + '" stroke="' + PALETTE.dim + '" stroke-width="0.3" stroke-dasharray="1.5 0.8"/>';
    } else {
      svg += '<line x1="' + pad + '" y1="' + (pad + sheetLen) + '" x2="' + (pad + rollW) + '" y2="' + (pad + sheetLen) + '" stroke="' + PALETTE.dim + '" stroke-width="0.3" stroke-dasharray="1.5 0.8"/>';
    }

    svg += '</svg>';
    return svg;
  }

  function openModal(contentHtml) { $('zprep-w-modal-content').innerHTML = contentHtml; show($('zprep-w-modal'), 'flex'); }
  function closeModal() { EDIT = null; hide($('zprep-w-modal')); }

  /* ── SYNC ── */
  function syncCRM() {
    if (!state.leadId) { $('zprep-w-sync-result').innerHTML = '<span class="zprep-w-err-text">No CRM lead linked.</span>'; show($('zprep-w-sync-result')); return; }
    var body = buildSyncNoteBody();
    $('zprep-w-sync').disabled = true; $('zprep-w-sync').textContent = 'Posting note…';
    var sourceJob = (state.customer && state.customer.estimate_number) ? String(state.customer.estimate_number) : '';
    post('zprep_sync_crm', { lead_id: state.leadId, body: body, reserved_leftover_ids: state.reservedLeftoverIds || [], source_job: sourceJob }).then(function (res) {
      $('zprep-w-sync').disabled = false; $('zprep-w-sync').textContent = '✓ Finished — Send to CRM';
      if (res && res.success) {
        var d = res.data || {};
        // Note always posted here (a failed note comes back as res.success=false).
        var okMsg = '✓ Note posted to CRM lead #' + esc(state.leadId) + '.';
        var html;
        if (d.advance_ok && d.new_stage) {
          // Stage moved (or was already past the cut stage) — all good.
          html = '<span class="zprep-w-ok-text">' + okMsg + ' Pipeline → "' + esc(d.new_stage) + '".</span>';
        } else if (d.advance_status === 'at_last_stage') {
          // Benign: no stage after the current one. Note the fact, no alarm.
          html = '<span class="zprep-w-ok-text">' + okMsg + ' (Already at the final pipeline stage.)</span>';
        } else {
          // v2.2.4: the stage genuinely did NOT move — make it unmissable so the
          // cutter knows to advance the lead by hand instead of assuming it moved.
          html = '<span class="zprep-w-ok-text">' + okMsg + '</span>'
               + '<div class="zprep-w-warn-text zprep-w-sync-warn">⚠ The pipeline stage did <strong>not</strong> advance automatically — please move this lead forward in CRM.</div>';
        }
        $('zprep-w-sync-result').innerHTML = html;
        state.reservedLeftoverIds = [];
      } else { $('zprep-w-sync-result').innerHTML = '<span class="zprep-w-err-text">' + esc((res && res.data && res.data.message) || 'Sync failed.') + '</span>'; }
      show($('zprep-w-sync-result'));
    }).catch(function (err) { $('zprep-w-sync').disabled = false; $('zprep-w-sync').textContent = '✓ Finished — Send to CRM'; $('zprep-w-sync-result').innerHTML = '<span class="zprep-w-err-text">Network error: ' + esc(err) + '</span>'; show($('zprep-w-sync-result')); });
  }

  function buildSyncNoteBody() {
    var p = state.plan; if (!p) return ''; var c = state.customer || {};
    var C = zprepWidgetData.contract || {};
    var lines = [C.cutComplete || '=== Cut Plan Complete ==='];
    if (c.name) lines.push('Customer: ' + c.name);
    if (c.address) lines.push('Address: ' + c.address);
    if (c.estimate_number) lines.push('Estimate #: ' + c.estimate_number);
    lines.push('Cut by: ' + (zprepWidgetData.user && zprepWidgetData.user.name || 'Unknown'));
    lines.push('Date: ' + new Date().toLocaleDateString());
    lines.push('', '── Pieces Cut ──');
    var notCutList = [];
    (p.deliverables || []).forEach(function(d){
      var dims = Object.keys(d.dimensions || {})[0] || '';
      var entry = '• ' + d.qty + ' × ' + d.label + (dims ? ' [' + dims + ']' : '');
      if (d.not_cut) { notCutList.push(entry); } else { lines.push(entry); }
    });
    if (notCutList.length) {
      lines.push('', '── Included (NOT cut from roll stock — pre-made units) ──');
      notCutList.forEach(function(e){ lines.push(e); });
    }
    lines.push('', '── Material Used ──');
    var totalSqFt = calcTotalSqFt(p);
    (p.material_cost && p.material_cost.rolls || []).forEach(function(r){ lines.push(r.label + ': ' + r.feet + ' ft'); });
    lines.push('Total: ' + totalSqFt + ' sq ft');
    lines.push('', (C.signature || 'Prep') + ' v' + zprepWidgetData.version);
    return lines.join('\n');
  }

  /* ── PRINT — v2.1.0: Standardized-scale popup window ──
   * Opens a standalone HTML document with only the cut sheets. Every sheet is
   * drawn at ONE shared inches→paper ratio (keyed to the job's reference
   * dimension), so "36 inches wide" looks identical on every page and the
   * installer can compare sheets at a glance. Long pieces are oriented along
   * the page's vertical axis (the SVG already does this for 36" rolls) so a
   * page can be rotated 90° if desired without changing scale.
   */
  function doPrint() {
    if (!state.plan || !state.plan.pages) return;
    var pages = state.plan.pages;
    var c = state.customer || {};

    // ── Job-wide standardized scale ──
    // refDim: the representative field/workspace dimension we scale against.
    //   Flat table ≈ 108" long working surface; Roller ≈ 66" straight edge.
    // We take the larger of (workspace reference) and (longest sheet drawW)
    // so nothing overflows, then map that to a fixed paper width. Every sheet
    // shares this ratio → consistent perceived scale page to page.
    var ws = getWorkspace();
    var workspaceRef = (ws === 'roller') ? 66 : 108;
    var pad = 5;
    var maxDrawW = 0, maxDrawH = 0;
    pages.forEach(function (pg) {
      // v2.1.4 — every sheet is landscape: width = cut length, height = roll width.
      var dW = pg.sheet_length + pad * 2;
      var dH = pg.roll_width_in + pad * 2;
      if (dW > maxDrawW) maxDrawW = dW;
      if (dH > maxDrawH) maxDrawH = dH;
    });
    // Scale the horizontal axis (cut length) against the workspace length so the
    // longest sheet fills the page width; every sheet shares this one ratio.
    var refDim = Math.max(workspaceRef, maxDrawW);
    // Letter LANDSCAPE: ~11" wide, ~10.3" usable inside .3in margins + padding.
    var refPaperIn = 10.0;

    // ── Single uniform label font across ALL sheets ──
    var globalFont = computeGlobalFont(pages);

    var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Cut Sheets — ' + esc(c.name || 'Prep') + '</title><style>';
    html += buildPrintCSS();
    html += '</style></head><body>';

    // pre-made / pre-made deliverables are excluded from sheets; surface them
    // once, on the final sheet, so the printed packet records they ship with
    // the order without implying they're cut here. (Deliverables live on the
    // plan — `p` does NOT exist in this scope; using it threw a ReferenceError
    // that aborted doPrint before printing, which is why Print did nothing.)
    var planDeliv = (state.plan && state.plan.deliverables) || [];
    var notCutDeliv = planDeliv.filter(function(d){ return d.not_cut; });

    pages.forEach(function (page, idx) {
      var svg = renderNestingSVG(page, true, { refDim: refDim, refPaperIn: refPaperIn, fontIn: globalFont });
      var sqft = calcPageSqFt(page);
      var delHtml = (page.deliverables || []).map(function(d){ return '<label class="zprep-pc-del"><input type="checkbox"> ' + esc(d.qty) + ' × ' + esc(d.label) + (d.dims ? ' [' + esc(d.dims) + ']' : '') + '</label>'; }).join('');

      var premadeHtml = '';
      if (idx === pages.length - 1 && notCutDeliv.length) {
        premadeHtml = '<div class="zprep-print-premade"><strong>INCLUDED — NOT CUT (pre-made units):</strong>' +
          notCutDeliv.map(function(d){
            var dims = Object.keys(d.dimensions || {})[0] || '';
            return '<label class="zprep-pc-del"><input type="checkbox"> ' + esc(d.qty) + ' × ' + esc(d.label) + (dims ? ' [' + esc(dims) + ']' : '') + '</label>';
          }).join('') +
          '<div class="zprep-print-premade-note">Ships with the order. Not cut from roll stock — not the cutter\u2019s responsibility for this job.</div></div>';
      }

      html += '<div class="zprep-print-page">' +
        '<div class="zprep-print-header"><h1>' + esc(zprepWidgetData.letterhead || '') + ' — CUT SHEET</h1></div>' +
        '<div class="zprep-print-job"><div><strong>Customer:</strong> ' + esc(c.name||'') + '</div><div><strong>Est #:</strong> ' + esc(c.estimate_number||'—') + '</div><div><strong>Address:</strong> ' + esc(c.address||'') + '</div><div><strong>Salesperson:</strong> ' + esc(c.salesperson||'') + '</div><div><strong>Phone:</strong> ' + esc(c.phone||'') + '</div><div><strong>Date:</strong> ' + new Date().toLocaleDateString() + '</div></div>' +
        '<div class="zprep-print-roll"><strong>' + page.roll_width_in + '" ' + page.color.toUpperCase() + '</strong> — Sheet ' + (idx+1) + ' of ' + pages.length + ' — Cut ' + fmtIn(page.sheet_length) + '" (' + page.linear_feet.toFixed(1) + ' ft · ' + sqft + ' sq ft)</div>' +
        '<div class="zprep-print-svg">' + svg + '</div>' +
        '<div class="zprep-print-checklist"><strong>CUT LIST:</strong>' + delHtml + '</div>' +
        premadeHtml +
        '<div class="zprep-print-cost">Material: ' + page.linear_feet.toFixed(1) + ' ft of ' + page.roll_width_in + '" ' + page.color + ' — ' + sqft + ' sq ft</div>' +
        '<div class="zprep-print-signoff"><div>Cut by: <span class="zprep-sig-line"></span></div><div>Date: <span class="zprep-sig-line"></span></div></div>' +
        '<div class="zprep-print-footer">v' + zprepWidgetData.version + ' · ' + esc((zprepWidgetData.contract && zprepWidgetData.contract.signature) || 'Prep') + '</div></div>';
    });

    // NOTE: no inline auto-print script here — in an embedded WKWebView,
    // window.print() inside a srcdoc iframe is frequently a silent no-op (it
    // neither prints nor throws), which is exactly why "Print" appeared dead.
    // We instead present the rendered sheets in a full-screen overlay with
    // explicit, always-working actions, and ALSO attempt a real print. The user
    // is never left with silence: even if window.print() is unavailable, they
    // can Open-in-new-tab (OS print/share) or Download the HTML.
    html += '</body></html>';

    showPrintOverlay(html, c);
  }

  // Full-screen, in-widget print surface. Works regardless of webview print
  // support: it renders the sheets in an iframe and offers Print / Open / Save.
  function showPrintOverlay(html, c) {
    var prior = document.getElementById('zprep-print-overlay');
    if (prior && prior.parentNode) prior.parentNode.removeChild(prior);

    var fname = 'cut-sheets-' + ((c && c.name) || 'prep').replace(/\s+/g, '-').toLowerCase() + '.html';

    var overlay = document.createElement('div');
    overlay.id = 'zprep-print-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:2147483600;background:rgba(15,23,42,0.94);display:flex;flex-direction:column;';

    var bar = document.createElement('div');
    bar.style.cssText = 'flex:0 0 auto;display:flex;gap:8px;align-items:center;padding:10px 12px;background:#0f172a;border-bottom:1px solid #334155;flex-wrap:wrap;';
    bar.innerHTML =
      '<strong style="color:#e2e8f0;font:600 14px Inter,system-ui,sans-serif;margin-right:auto;">Cut Sheets — ' + esc((c && c.name) || 'Prep') + '</strong>' +
      '<button id="zprep-po-print"  style="font:600 13px Inter,system-ui,sans-serif;padding:9px 14px;border-radius:8px;border:0;background:#D4881C;color:#fff;cursor:pointer;">🖨 Print</button>' +
      '<button id="zprep-po-open"   style="font:600 13px Inter,system-ui,sans-serif;padding:9px 14px;border-radius:8px;border:1px solid #475569;background:#1e293b;color:#e2e8f0;cursor:pointer;">Open in new tab</button>' +
      '<button id="zprep-po-save"   style="font:600 13px Inter,system-ui,sans-serif;padding:9px 14px;border-radius:8px;border:1px solid #475569;background:#1e293b;color:#e2e8f0;cursor:pointer;">Download</button>' +
      '<button id="zprep-po-close"  style="font:600 13px Inter,system-ui,sans-serif;padding:9px 14px;border-radius:8px;border:1px solid #475569;background:#1e293b;color:#e2e8f0;cursor:pointer;">Close</button>';
    overlay.appendChild(bar);

    var hint = document.createElement('div');
    hint.style.cssText = 'flex:0 0 auto;color:#94a3b8;font:400 12px Inter,system-ui,sans-serif;padding:6px 12px;background:#0f172a;border-bottom:1px solid #1e293b;';
    hint.textContent = 'If Print does nothing on this device, tap “Open in new tab” and use your browser/OS print or share (AirPrint).';
    overlay.appendChild(hint);

    var frame = document.createElement('iframe');
    frame.id = 'zprep-print-frame';
    frame.title = 'Cut sheets preview';
    frame.style.cssText = 'flex:1 1 auto;width:100%;border:0;background:#fff;';
    overlay.appendChild(frame);

    document.body.appendChild(overlay);

    // Load the print HTML into the visible iframe.
    var loaded = false;
    try {
      if ('srcdoc' in frame) { frame.srcdoc = html; }
      else { var d = frame.contentWindow.document; d.open(); d.write(html); d.close(); }
      loaded = true;
    } catch (e) { loaded = false; }

    // Keep a blob URL around for Open / Save (and as a fallback if srcdoc failed).
    var blobUrl = null;
    try { blobUrl = URL.createObjectURL(new Blob([html], { type: 'text/html' })); } catch (e) {}
    if (!loaded && blobUrl) { frame.src = blobUrl; }

    var cleanup = function () {
      if (blobUrl) { try { URL.revokeObjectURL(blobUrl); } catch (e) {} }
      if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
      document.removeEventListener('keydown', onKey);
    };
    var onKey = function (e) { if (e.key === 'Escape') cleanup(); };
    document.addEventListener('keydown', onKey);

    document.getElementById('zprep-po-close').addEventListener('click', cleanup);

    document.getElementById('zprep-po-print').addEventListener('click', function () {
      // Try the most reliable print targets in order. None are guaranteed in a
      // webview, so this is best-effort; Open/Save are the dependable paths.
      var tried = false;
      try { if (frame.contentWindow && typeof frame.contentWindow.print === 'function') { frame.contentWindow.focus(); frame.contentWindow.print(); tried = true; } } catch (e) {}
      if (!tried) { try { if (typeof window.print === 'function') { window.print(); tried = true; } } catch (e) {} }
      if (!tried && blobUrl) { try { window.open(blobUrl, '_blank'); } catch (e) {} }
    });

    document.getElementById('zprep-po-open').addEventListener('click', function () {
      if (!blobUrl) { try { blobUrl = URL.createObjectURL(new Blob([html], { type: 'text/html' })); } catch (e) {} }
      var w = null;
      try { w = window.open(blobUrl || 'about:blank', '_blank'); } catch (e) {}
      if (!w && blobUrl) {
        // Popup blocked: navigate via a transient anchor (OS often intercepts).
        var a = document.createElement('a'); a.href = blobUrl; a.target = '_blank'; a.rel = 'noopener';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
      } else if (w && !blobUrl) {
        try { w.document.open(); w.document.write(html); w.document.close(); } catch (e) {}
      }
    });

    document.getElementById('zprep-po-save').addEventListener('click', function () {
      try {
        if (!blobUrl) blobUrl = URL.createObjectURL(new Blob([html], { type: 'text/html' }));
        var a = document.createElement('a'); a.href = blobUrl; a.download = fname;
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
      } catch (e) {}
    });
  }

  function buildPrintCSS() {
    return 'body{margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#fff;color:#000;}' +
      '.zprep-print-page{padding:.2in .35in;max-width:10.3in;margin:0 auto;page-break-after:always;break-after:page;}' +
      '.zprep-print-page:last-child{page-break-after:avoid;break-after:avoid;}' +
      '.zprep-print-header{border-bottom:3px solid #000;padding-bottom:.08in;margin-bottom:.12in;}' +
      '.zprep-print-header h1{font-size:14pt;font-weight:900;text-transform:uppercase;letter-spacing:.06em;margin:0;}' +
      '.zprep-print-job{display:grid;grid-template-columns:1fr 1fr;gap:2pt 16pt;font-size:10pt;margin-bottom:.1in;line-height:1.5;}' +
      '.zprep-print-roll{font-size:12pt;padding:6pt 10pt;background:#eee;border:2px solid #000;border-radius:4pt;margin-bottom:.12in;}' +
      // v2.1.0: the SVG width is set inline (standardized scale). Centre it and
      // cap height so a very long sheet still fits one page; the installer can
      // rotate the page 90° if they prefer the long axis horizontal.
      '.zprep-print-svg{background:#c8c8c8;padding:.08in;margin-bottom:.1in;border-radius:2pt;text-align:center;}' +
      '.zprep-print-svg svg{height:auto!important;display:inline-block;max-width:100%;max-height:4.5in;}' +
      '.zprep-print-checklist{font-size:11pt;line-height:2;margin-bottom:.1in;}' +
      '.zprep-pc-del{display:block;cursor:default;}' +
      '.zprep-pc-del input[type="checkbox"]{transform:scale(1.6);margin-right:.1in;vertical-align:middle;}' +
      '.zprep-print-cost{font-size:10pt;padding:4pt 0;border-top:1px solid #999;margin-bottom:.15in;}' +
      '.zprep-print-premade{font-size:11pt;line-height:2;margin:.05in 0 .12in;padding:.08in;border:1px dashed #555;border-radius:4pt;}' +
      '.zprep-print-premade-note{font-size:9pt;font-style:italic;color:#555;line-height:1.3;margin-top:4pt;}' +
      '.zprep-print-signoff{display:flex;gap:.5in;font-size:10pt;margin-top:.2in;border-top:2px solid #000;padding-top:.08in;}' +
      '.zprep-sig-line{display:inline-block;width:2in;border-bottom:1px solid #999;margin-left:4pt;}' +
      '.zprep-print-footer{font-size:8pt;color:#888;text-align:right;margin-top:.1in;}' +
      '@page{margin:.3in;size:letter landscape;}';
  }

  // Piece-kind label: prefer the Item Engine vocabulary localized by the server
  // (id => display name); otherwise humanize the token. No hardcoded taxonomy.
  function kindLabel(t) {
    if (!t) return 'Piece';
    var m = zprepWidgetData.kindLabels || {};
    if (m[t]) return m[t];
    return String(t).replace(/[_-]+/g, ' ').replace(/\b\w/g, function (ch) { return ch.toUpperCase(); });
  }

  function resetAll() {
    state = { source:null, match:null, customer:null, leadId:null, cachedNotes:null, parsed:null, measurements:[], plan:null, useLeftovers:false, reservedLeftoverIds:[], pendingLeads:[], batchLeadIds:[], approvedJobs:[], appliedAdjustments:false, debug:false };
    $('zprep-w-search').value = ''; setErr('zprep-w-lookup-error', ''); showView('zprep-w-lookup');
  }

  function init() {
    if (initialized) return; var root = $('zprep-widget'); if (!root) return; initialized = true;
    // Debug mode can be pre-enabled with ?zprep_debug=1 (handy for field reports).
    try { if (/[?&]zprep_debug=1\b/.test(window.location.search)) state.debug = true; } catch (e) {}
    var dchk = $('zprep-w-debug-check');
    if (dchk) {
      dchk.checked = !!state.debug;
      dchk.addEventListener('change', function (e) {
        state.debug = e.target.checked;
        // If a plan is already computed, recompute so the engine returns the
        // trace (and to refresh it); otherwise it'll be included on next Compute.
        if (state.measurements && state.measurements.length) computeCuts();
        else { var p = $('zprep-w-debug-panel'); if (p && !state.debug) p.remove(); }
      });
    }
    var atcReload = $('zprep-w-atc-reload');
    if (atcReload) atcReload.addEventListener('click', loadApprovedToCut);
    loadApprovedToCut();
    $('zprep-w-lookup-btn').addEventListener('click', doLookup);
    $('zprep-w-search').addEventListener('keydown', function(e){ if (e.key==='Enter') doLookup(); });
    $('zprep-w-refresh-notes').addEventListener('click', startParse);
    $('zprep-w-compute').addEventListener('click', computeCuts);
    $('zprep-w-reset').addEventListener('click', resetAll);
    $('zprep-w-sync').addEventListener('click', syncCRM);
    $('zprep-w-back-to-match').addEventListener('click', function(){ showView('zprep-w-match'); });
    $('zprep-w-modal-close').addEventListener('click', closeModal);
    $('zprep-w-modal').addEventListener('click', function(e){ if (e.target===$('zprep-w-modal')||e.target.classList.contains('zprep-w-modal-bg')) closeModal(); });
    document.addEventListener('keydown', function(e){ if (e.key==='Escape' && $('zprep-w-modal').style.display!=='none') closeModal(); });
    // v2.0.3 — Print via clean popup window
    var printBtn = $('zprep-w-print'); if (printBtn) printBtn.addEventListener('click', doPrint);

    // Workspace toggle buttons
    var wsToggle = $('zprep-w-workspace-toggle');
    if (wsToggle) {
      wsToggle.querySelectorAll('.zprep-w-toggle-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          wsToggle.querySelectorAll('.zprep-w-toggle-btn').forEach(function (b) { b.classList.remove('active'); });
          btn.classList.add('active');
          var rollSel = $('zprep-w-roll-select');
          if (btn.getAttribute('data-val') === 'roller' && rollSel && rollSel.value === '0') {
            rollSel.value = '60b';
          }
        });
      });
    }
  }

  if ($('zprep-widget')) { init(); } else {
    document.addEventListener('ts_widgets_rendered', init, { once: true });
    document.addEventListener('DOMContentLoaded', function(){ setTimeout(function(){ if ($('zprep-widget')) init(); }, 500); });
  }
})();
