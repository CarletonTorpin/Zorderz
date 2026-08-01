/**
 * Zorderz Receipts — SPA Dashboard Widget
 *
 * v3.6.2: UPLOAD-FIRST. Once a job is chosen, the photo UPLOADER is the
   primary action at the top of the flow (was buried behind a toggle). Photos
   the tech personally captured appear only as a SECONDARY "or use photos you
   already captured" shortcut, and only when they have their own (never anyone
   else's). The upload path requires an explicit "these are the photos I want"
   confirm before Generate. Uploads are mirrored into the shared library
   (ZDZ_User_Media) as company-wide / customer-tagged (see the PHP handler).
 *
 * v3.4.0: Photo sets respect the Media app's upload-batch tag (server-side,
 *   ZRCPT_Media) and every photo in the chosen set is now TOGGLEABLE — tap to
 *   exclude/include before generating. Batch-tagged sets show a "Tagged set"
 *   chip (+ the upload note). The active set shows ALL its photos, not the
 *   5-thumb preview.
 *
 * v3.1.0: Photo-first, lookup-driven — works like Prep.
 *   The tech types a name / number / phone. We find the job (FreshBooks +
 *   Nutshell), then AUTO-PULL the photos they already captured from the shared
 *   media library and present them as capture "sets" — newest set = the
 *   installation, the set before = the estimate/"before" photos. The install
 *   date is pre-filled from the photo's EXIF date. No upload step by default;
 *   a "Need to upload photos instead?" button reveals the manual path.
 *
 * Flow:
 *   1. Find job  → Smart Lookup (unchanged backend)
 *   2. On select → pull + group the user's photos (zrcpt_match_media)
 *   3. Pick set  → newest pre-selected; date pre-fills from EXIF
 *   4. Generate  → sends selected media IDs (or manually uploaded photos)
 *
 * Globals (wp_localize_script):
 *   zrcptWidgetData.ajaxurl, .nonce, .dashboardUrl, .version
 *   zrcptWidgetData.hasLookup  — smart lookup available
 *   zrcptWidgetData.hasMedia   — shared photo library available to auto-pull
 *   zrcptWidgetData.mode       — receipt mode ('tagged' today)
 *
 * @since 2.5.0
 * @updated 3.1.0 — Photo-first, lookup-driven
 */
(function () {
  'use strict';

  /* ==================================================================
   * STATE
   * ================================================================== */

  var initialized = false;   // Rule 2: Boolean init gate

  /** Manually-uploaded photo data array: [{url, id, thumb, el}] */
  var photoData = [];

  /** Number of photos currently uploading */
  var uploading = 0;

  /** Invoice file reference (null when using lookup) */
  var invoiceFile = null;

  /** Selected lookup match (null when using file upload). For a combined set
   *  this is the PRIMARY invoice (drives customer/photo/Nutshell context). */
  var selectedLookup = null;

  /** v3.3.0 — full set of invoices selected for this receipt (1 or more, when
   *  combining). Each element is a FreshBooks match object. */
  var selectedInvoices = [];

  /** Nutshell install notes from lookup */
  var lookupInstallNotes = null;

  /* v3.1.0 — photo-set (library) state */

  /** Capture sessions pulled from the shared library for the selected job. */
  var mediaSessions = [];

  /** The session id the user has selected as the install set (e.g. "sess-0"). */
  var selectedSessionId = null;

  /** v3.4.0 — per-photo exclusions within the chosen set: media_id -> true.
   *  The escape hatch when any grouping (however smart) disagrees with
   *  reality: excluded photos are filtered out of BOTH photo_data and
   *  media_ids at generate time, so provenance only records what was used.
   *  Reset on every photo-set (re)load and on every lookup reset. */
  var photoExcluded = {};

  /** True once the user has explicitly opened the manual-upload fallback. */
  var manualMode = false;

  /** Did the install date get auto-filled from EXIF (vs. typed)? */
  var dateAutoFilled = false;

  /** v3.6.2 — the tech ticked "these are the photos I want" for an UPLOADED
   *  set. Required before Generate enables on the upload path; not used when a
   *  captured library set is chosen (that path confirms per-photo via toggles).
   *  Reset whenever the upload set changes or the form resets. */
  var uploadConfirmed = false;

  /** v3.8.0 — per-session photo ORDER: session id -> [media_id, ...]. Set the
   *  first time a set is opened (its natural order) and updated by
   *  drag-to-reorder. The order flows into photo_data at generate time, so the
   *  receipt's gallery comes out exactly as arranged here — the same control
   *  the Review & Approve preview already offers, moved up to selection time. */
  var photoOrder = {};

  /** v3.8.0 — true when at least one selected invoice already HAS a receipt,
   *  i.e. this generate is a REDO: it will REPLACE that receipt's content at
   *  its existing link (same URL/share token), and it will need a fresh
   *  Review & Approve before it can be (re)sent. Drives honest button/label
   *  copy throughout the flow. */
  var redoMode = false;

  /** v3.8.0 — lightbox state for the pre-generate photo viewer. */
  var lightbox = { open: false, sessionId: null, index: 0 };

  /** v3.8.1 — once a set is chosen the OTHER set cards collapse behind a
   *  "Change photo set" row, so the path from the chosen photos to the
   *  Generate button is short. True only while the tech has explicitly
   *  re-opened the picker to switch sets. */
  var setPickerExpanded = false;

  /* ==================================================================
   * HELPERS
   * ================================================================== */

  function $(id) { return document.getElementById(id); }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  }

  function hide(el) { if (el) el.style.display = 'none'; }

  // Copy text to the clipboard with a graceful fallback, and flash the button.
  function copyTextToClipboard(text, btn) {
    function flash(ok) {
      if (!btn) return;
      var label = btn.querySelector('.zrcpt-w-item-copy-label');
      var prev = label ? label.textContent : '';
      if (label) label.textContent = ok ? 'Copied!' : 'Press \u2318C';
      btn.classList.add(ok ? 'zrcpt-w-item-copy-ok' : 'zrcpt-w-item-copy-warn');
      setTimeout(function () {
        if (label) label.textContent = prev || 'Copy';
        btn.classList.remove('zrcpt-w-item-copy-ok', 'zrcpt-w-item-copy-warn');
      }, 1600);
    }
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { flash(true); }, function () { fallbackCopy(text, flash); });
        return;
      }
    } catch (e) { /* fall through */ }
    fallbackCopy(text, flash);
  }

  function fallbackCopy(text, flash) {
    try {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'absolute';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      var ok = document.execCommand('copy');
      document.body.removeChild(ta);
      flash(!!ok);
    } catch (e) {
      flash(false);
    }
  }
  function show(el) { if (el) el.style.display = ''; }  // Clear inline style → CSS class takes over

  function ajaxPost(action, formData) {
    if (!formData) formData = new FormData();
    formData.append('action', action);
    formData.append('_nonce', zrcptWidgetData.nonce);
    // v3.0.0: also include nonce as 'nonce' for smart_lookup endpoints
    formData.append('nonce', zrcptWidgetData.nonce);
    return fetch(zrcptWidgetData.ajaxurl, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  }

  function formatFileSize(bytes) {
    if (bytes < 1048576) return (bytes / 1024).toFixed(0) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
  }

  function truncate(str, n) {
    str = String(str || '');
    return str.length > n ? str.slice(0, n - 1) + '\u2026' : str;
  }

  /* ==================================================================
   * PROGRESS STEPS
   * ================================================================== */

  var STEPS = [
    'Sending your data to the AI assistant\u2026',
    'AI is reading the invoice and photos \u2014 this usually takes 1\u20132 minutes\u2026',
    'Still generating \u2014 complex invoices take longer\u2026',
    'Almost done \u2014 building the receipt page\u2026',
    'Wrapping up\u2026',
  ];

  var stepIndex = 0;
  var stepTimer = null;

  function startSteps() {
    stepIndex = 0;
    setStepText(STEPS[0]);
    stepTimer = setInterval(function () {
      stepIndex++;
      if (stepIndex < STEPS.length) {
        setStepText(STEPS[stepIndex]);
      } else {
        clearInterval(stepTimer);
        stepTimer = null;
      }
    }, 20000);
  }

  function stopSteps() {
    if (stepTimer) {
      clearInterval(stepTimer);
      stepTimer = null;
    }
  }

  function setStepText(text) {
    var el = $('zrcpt-w-status-text');
    if (el) el.textContent = text;
  }

  /* ==================================================================
   * HEIC → JPEG CONVERSION (client-side)
   * ================================================================== */

  function isHeic(file) {
    var ext = (file.name || '').split('.').pop().toLowerCase();
    return ext === 'heic' || ext === 'heif';
  }

  function convertHeicToJpeg(file) {
    return createImageBitmap(file).then(function (bmp) {
      var c = document.createElement('canvas');
      c.width = bmp.width;
      c.height = bmp.height;
      c.getContext('2d').drawImage(bmp, 0, 0);
      if (bmp.close) bmp.close();
      return new Promise(function (resolve, reject) {
        c.toBlob(function (blob) {
          if (!blob) { reject(new Error('Canvas conversion failed')); return; }
          var newName = file.name.replace(/\.(heic|heif)$/i, '.jpg');
          resolve(new File([blob], newName, { type: 'image/jpeg' }));
        }, 'image/jpeg', 0.92);
      });
    });
  }

  /* ==================================================================
   * DROPZONE WIRING
   * ================================================================== */

  function wireDropzone(el, fileInput, onFiles) {
    if (!el || !fileInput) return;

    el.addEventListener('click', function () { fileInput.click(); });

    el.addEventListener('dragover', function (e) {
      e.preventDefault();
      el.classList.add('over');
    });

    el.addEventListener('dragleave', function () {
      el.classList.remove('over');
    });

    el.addEventListener('drop', function (e) {
      e.preventDefault();
      el.classList.remove('over');
      if (e.dataTransfer.files.length) onFiles(e.dataTransfer.files);
    });

    fileInput.addEventListener('change', function () {
      if (fileInput.files.length) onFiles(fileInput.files);
    });
  }

  /* ==================================================================
   * INVOICE HANDLING
   * ================================================================== */

  function handleInvoiceFiles(files) {
    if (!files.length) return;
    invoiceFile = files[0];

    // v3.1.0: An uploaded invoice file is a SUPPLEMENT to (or replacement for)
    // the lookup, used only when the tech didn't use Find. It no longer clears
    // the selected job — clearing only happens via the "Change" button.
    var nameEl = $('zrcpt-w-invoice-name');
    if (nameEl) {
      nameEl.textContent = '\uD83D\uDCC4 ' + invoiceFile.name + ' (' + formatFileSize(invoiceFile.size) + ')';
    }
    updateGenerateState();
  }

  /* ==================================================================
   * v3.0.0 — SMART LOOKUP
   * ================================================================== */

  function initLookup() {
    var input = $('zrcpt-w-lookup-input');
    var btn = $('zrcpt-w-lookup-btn');
    if (!input || !btn) return;

    btn.addEventListener('click', function () {
      var q = input.value.trim();
      if (q) runSmartLookup(q);
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        btn.click();
      }
    });
  }

  function runSmartLookup(query) {
    var statusEl = $('zrcpt-w-lookup-status');
    var errEl = $('zrcpt-w-lookup-error');
    var cardsEl = $('zrcpt-w-lookup-cards');

    // v3.0.0 fix: Clear any previous confirmed selection before searching
    clearLookup();

    if (statusEl) { statusEl.textContent = 'Searching FreshBooks\u2026'; show(statusEl); }
    if (errEl) { errEl.textContent = ''; hide(errEl); }
    if (cardsEl) { cardsEl.innerHTML = ''; }

    var fd = new FormData();
    fd.append('query', query);

    ajaxPost('zrcpt_smart_lookup', fd).then(function (res) {
      hide(statusEl);

      if (!res || !res.success) {
        if (errEl) {
          var msg = (res && res.data && res.data.message) || 'Lookup failed.';
          errEl.textContent = msg;
          show(errEl);
        }
        return;
      }

      var matches = (res.data && res.data.matches) || [];
      if (!matches.length) {
        if (errEl) {
          errEl.textContent = 'No matches for \u201C' + query + '\u201D.';
          show(errEl);
        }
        return;
      }

      renderLookupCards(matches);
    }).catch(function (err) {
      hide(statusEl);
      if (errEl) {
        errEl.textContent = 'Network error: ' + err;
        show(errEl);
      }
    });
  }

  // v3.3.0 \u2014 Invoice-only flow with multi-select / combine.
  // Receipts can ONLY be made from invoices. Estimates show as informational
  // (greyed, not selectable). Unreceipted invoices are pre-checked (suggested);
  // an invoice already on a receipt is shown with a link and an explicit
  // "use anyway" override before it can be checked. The tech generates from one
  // or COMBINES several invoices into a single receipt.
  function renderLookupCards(matches) {
    var cardsEl = $('zrcpt-w-lookup-cards');
    if (!cardsEl) return;

    var invoices = matches.filter(function (m) { return m.is_invoice; });
    var nonInvoices = matches.filter(function (m) { return !m.is_invoice; });
    var selectableInvoices = invoices.filter(function (m) { return m.selectable; });

    var html = '';

    // No invoices at all \u2192 cannot generate. Be explicit about why.
    if (invoices.length === 0) {
      if (nonInvoices.some(function (m) { return (m.type === 'estimate'); })) {
        html += '<div class="zrcpt-w-lookup-warn">\u26A0 Found an estimate but no invoice. A receipt can only be made from an invoice \u2014 invoice the job in FreshBooks, then re-run lookup.</div>';
      } else {
        html += '<div class="zrcpt-w-lookup-warn">\u26A0 No matching invoice found. Try the invoice #, customer name, or phone.</div>';
      }
    } else if (invoices.length > 1) {
      html += '<div class="zrcpt-w-lookup-hint2">' + invoices.length + ' invoices for this customer. '
            + (selectableInvoices.length > 1
                ? 'Pick one, or check several to combine into a single receipt.'
                : 'Suggested invoice is checked below.') + '</div>';
    }

    // Render invoices first (selectable), each with a big, obvious checkbox.
    // v3.8.0 \u2014 an invoice that already HAS a receipt gets an explicit "Redo
    // this receipt" button instead of the old tiny "Use anyway" text link.
    // Redoing is honest about what happens: it REPLACES the receipt's content
    // at the SAME link the customer already has, and it will need a fresh
    // Review & Approve before it can be re-sent.
    invoices.forEach(function (m) {
      var i = matches.indexOf(m);
      var amount = m.amount && m.amount !== '0.00' ? ' \u00B7 $' + m.amount : '';
      var already = !!m.receipted;
      var cls = 'zrcpt-w-lookup-card zrcpt-w-inv' + (already ? ' zrcpt-w-inv-receipted' : '');
      html += '<label class="' + cls + '" data-i="' + i + '">';
      html += '<span class="zrcpt-w-inv-check">'
            + '<input type="checkbox" class="zrcpt-w-inv-cb" data-i="' + i + '"'
            + (m.suggested ? ' checked' : '')
            + (already ? ' disabled' : '') + ' />'
            + '</span>';
      html += '<span class="zrcpt-w-inv-body">';
      html += '<span class="zrcpt-w-lookup-card-top"><strong>' + esc(m.customer_name || '(unknown)') + '</strong>';
      html += '<span class="zrcpt-w-badge zrcpt-w-badge-inv">Invoice #' + esc(m.number) + '</span></span>';
      if (m.customer_detail && m.customer_detail.address) {
        html += '<span class="zrcpt-w-lookup-addr">' + esc(m.customer_detail.address) + '</span>';
      }
      var refline = (m.reference ? 'Ref: ' + esc(m.reference) : '') + amount;
      if (refline) html += '<span class="zrcpt-w-lookup-ref">' + refline + '</span>';
      if (already) {
        // v3.9.2 \u2014 calmer: one line + a compact button + one short consequence
        // line. (The v3.8.0 full-width orange bar read as aggressive, and the
        // three-line explainer was too many words.)
        html += '<span class="zrcpt-w-inv-flag">'
              +   '<span class="zrcpt-w-inv-flag-line">\u2713 Already has a receipt \u00B7 '
              +     '<a href="' + esc(m.receipted.permalink) + '" target="_blank" rel="noopener">view \u2197</a>'
              +   '</span>'
              +   '<span class="zrcpt-w-inv-flag-row">'
              +     '<button type="button" class="zrcpt-w-inv-override" data-i="' + i + '">'
              +       '<span class="zrcpt-w-inv-override-icon" aria-hidden="true">\u21BB</span> Redo receipt'
              +     '</button>'
              +     '<span class="zrcpt-w-inv-override-why">New version, same link. You\u2019ll re-approve before re-sending.</span>'
              +   '</span>'
              + '</span>';
      }
      html += '</span></label>';
    });

    // Render estimates / others as informational, greyed, not selectable.
    nonInvoices.forEach(function (m) {
      var i = matches.indexOf(m);
      var label = (m.type === 'estimate') ? 'Estimate' : (m.type || 'Other');
      html += '<div class="zrcpt-w-lookup-card zrcpt-w-noninv" data-i="' + i + '">';
      html += '<div class="zrcpt-w-lookup-card-top"><strong>' + esc(m.customer_name || '(unknown)') + '</strong>';
      html += '<span class="zrcpt-w-badge zrcpt-w-badge-est">' + esc(label) + ' #' + esc(m.number) + '</span></div>';
      if (m.reason) html += '<div class="zrcpt-w-noninv-reason">' + esc(m.reason) + '</div>';
      html += '</div>';
    });

    // The generate-from-selected action (only when at least one invoice exists).
    if (invoices.length > 0) {
      html += '<button type="button" id="zrcpt-w-use-invoices" class="zrcpt-w-lookup-use">Use selected invoice(s) \u2192</button>';
    }

    cardsEl.innerHTML = html;

    // v3.8.0 — "Redo this receipt": arms the invoice's checkbox and flips the
    // card into an unmistakable REDO state (orange, labelled, undoable).
    cardsEl.querySelectorAll('.zrcpt-w-inv-override').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var i = Number(btn.getAttribute('data-i'));
        var cb = cardsEl.querySelector('.zrcpt-w-inv-cb[data-i="' + i + '"]');
        var card = cardsEl.querySelector('.zrcpt-w-inv[data-i="' + i + '"]');
        var flag = card ? card.querySelector('.zrcpt-w-inv-flag') : null;
        if (!cb || !card || !flag) return;

        cb.disabled = false;
        cb.checked = true;
        card.classList.remove('zrcpt-w-inv-receipted');
        card.classList.add('zrcpt-w-inv-redo');

        // Swap the flag block for the armed "redoing" state + an Undo.
        // v3.9.2 — one chip, one short line, small undo. Fewer words.
        var permalink = (matches[i] && matches[i].receipted && matches[i].receipted.permalink) || '';
        flag.innerHTML =
            '<span class="zrcpt-w-inv-redo-on">'
          +   '<span class="zrcpt-w-inv-redo-row">'
          +     '<span class="zrcpt-w-inv-redo-chip">↻ Redoing</span>'
          +     '<span class="zrcpt-w-inv-redo-note">Replaces the receipt at the same link'
          +       (permalink ? ' (<a href="' + esc(permalink) + '" target="_blank" rel="noopener">current ↗</a>)' : '')
          +     '.</span>'
          +   '</span>'
          +   '<button type="button" class="zrcpt-w-inv-redo-undo" data-i="' + i + '">Cancel redo</button>'
          + '</span>';

        var undo = flag.querySelector('.zrcpt-w-inv-redo-undo');
        if (undo) {
          undo.addEventListener('click', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            // Re-render the whole card list back to its initial state; simplest
            // honest reset (nothing else is selected yet at this stage).
            renderLookupCards(matches);
          });
        }
      });
    });

    var useBtn = $('zrcpt-w-use-invoices');
    if (useBtn) {
      useBtn.addEventListener('click', function () {
        var picked = [];
        cardsEl.querySelectorAll('.zrcpt-w-inv-cb:checked').forEach(function (cb) {
          var i = Number(cb.getAttribute('data-i'));
          if (matches[i]) picked.push(matches[i]);
        });
        if (!picked.length) {
          useBtn.textContent = 'Check at least one invoice first';
          setTimeout(function () { useBtn.textContent = 'Use selected invoice(s) \u2192'; }, 2500);
          return;
        }
        selectInvoiceSet(picked);
      });
    }

    // Fast path: exactly one suggested (unreceipted) invoice and nothing else
    // selectable \u2192 proceed automatically, preserving the one-tap flow.
    if (selectableInvoices.length === 1 && invoices.length === 1) {
      selectInvoiceSet([ selectableInvoices[0] ]);
    }
  }

  function selectLookupMatch(match) {
    selectedLookup = match;
    invoiceFile = null; // Clear any manual upload

    // Update confirmed area
    var confirmedEl = $('zrcpt-w-lookup-confirmed');
    if (confirmedEl) {
      var summary = (match.customer_name || '(unknown)') + ' \u00B7 ' +
        (match.type === 'invoice' ? 'Invoice' : 'Estimate') + ' #' + match.number;
      var addr = (match.customer_detail && match.customer_detail.address) ? match.customer_detail.address : '';
      confirmedEl.innerHTML = '<div class="zrcpt-w-lookup-ok">' +
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' +
        '<div class="zrcpt-w-lookup-ok-text"><strong>' + esc(summary) + '</strong>' +
        (addr ? '<span class="zrcpt-w-lookup-ok-addr">' + esc(addr) + '</span>' : '') +
        '</div>' +
        ' <button type="button" id="zrcpt-w-lookup-change" class="zrcpt-w-lookup-link">Change</button>' +
        '</div>';
      show(confirmedEl);

      var changeBtn = $('zrcpt-w-lookup-change');
      if (changeBtn) changeBtn.addEventListener('click', function () {
        clearLookup();
        $('zrcpt-w-lookup-input').value = '';
        $('zrcpt-w-lookup-input').focus();
      });
    }

    // Hide the search cards
    var cardsEl = $('zrcpt-w-lookup-cards');
    if (cardsEl) cardsEl.innerHTML = '';

    // Set invoice link if available. The looked-up invoice IS the invoice now —
    // we use it automatically and give the bot the full line-item context, so
    // the tech doesn't need to upload or paste anything.
    var linkInput = $('zrcpt-w-link');
    if (linkInput && match.invoice_url) {
      linkInput.value = match.invoice_url;
    }

    // v3.2.0: With a job selected from Find, the manual invoice upload/link is no
    // longer needed (the FreshBooks invoice + its line items are pulled
    // automatically). Hide that section to keep the flow clean; it returns via
    // "Change" / clearLookup() or the manual-upload toggle as a fallback.
    var invSection = $('zrcpt-w-invoice-section');
    if (invSection) hide(invSection);

    // Reveal the details block (date). Date will pre-fill from EXIF once the
    // photo set loads.
    show($('zrcpt-w-details'));

    // Pull Nutshell install notes (non-blocking)
    var nsEl = $('zrcpt-w-lookup-nutshell');
    var fd = new FormData();
    fd.append('customer', JSON.stringify({
      name: match.customer_name || '',
      email: (match.customer_detail && match.customer_detail.email) || '',
      phone: (match.customer_detail && match.customer_detail.phone) || '',
      estimate_number: match.type === 'estimate' ? match.number : '',
    }));

    ajaxPost('zrcpt_pull_nutshell_install', fd).then(function (res) {
      if (!res || !res.success) return;
      var notes = (res.data && res.data.install_notes) || [];
      lookupInstallNotes = notes;
      if (nsEl && notes.length) {
        nsEl.innerHTML = '<span class="zrcpt-w-ns-ok">' + notes.length + ' install note' + (notes.length === 1 ? '' : 's') + ' found on Nutshell</span>';
        show(nsEl);
      }
    }).catch(function () { /* non-critical */ });

    // ── The key step: pull the photos the tech already captured. ──
    loadPhotoSets(match);

    updateGenerateState();
  }

  // v3.3.0 — Select a SET of invoices (1 or more) for one receipt. The primary
  // (first) invoice drives customer/photo/Nutshell context via selectLookupMatch;
  // the full set is remembered so generate can merge their line items and save
  // provenance for every source invoice.
  function selectInvoiceSet(invoices) {
    if (!invoices || !invoices.length) return;
    selectedInvoices = invoices.slice();

    // v3.8.0 — REDO detection: if any chosen invoice already has a receipt,
    // this run replaces that receipt (same link) and must be re-approved.
    redoMode = invoices.some(function (m) { return !!m.receipted; });

    selectLookupMatch(invoices[0]);

    var confirmedEl = $('zrcpt-w-lookup-confirmed');

    // When combining, augment the confirmation to show all invoices in the set.
    if (invoices.length > 1 && confirmedEl) {
      var nums = invoices.map(function (m) { return '#' + m.number; }).join(', ');
      var extra = document.createElement('div');
      extra.className = 'zrcpt-w-combine-note';
      extra.textContent = 'Combining ' + invoices.length + ' invoices into one receipt: ' + nums;
      confirmedEl.appendChild(extra);
    }

    // v3.8.0 — make the redo visible on the confirmed-job card too.
    // v3.9.2 — one short line.
    if (redoMode && confirmedEl) {
      var prior = invoices.filter(function (m) { return m.receipted; });
      var note = document.createElement('div');
      note.className = 'zrcpt-w-redo-note';
      note.innerHTML = '<span class="zrcpt-w-inv-redo-chip">↻ Redo</span> '
        + 'Replaces the existing receipt — same link, re-approve before re-sending.'
        + (prior.length === 1 && prior[0].receipted.permalink
            ? ' <a href="' + esc(prior[0].receipted.permalink) + '" target="_blank" rel="noopener">current ↗</a>'
            : '');
      confirmedEl.appendChild(note);
    }

    updateGenerateState();
  }

  /* ==================================================================
   * v3.1.0 — PHOTO SETS (auto-pulled from the shared library)
   * ================================================================== */

  // v3.6.2 \u2014 UPLOAD-FIRST. When a job is chosen we ALWAYS show the photo
  // section with the uploader as the primary action. We then quietly check
  // whether THIS tech has their OWN recent captures; only if they do, we reveal
  // a secondary "or use photos you already captured" shortcut below the
  // uploader. We never surface anyone else's photos, and when the tech has none
  // we show only the uploader (no error noise -- uploading is the expected path).
  function loadPhotoSets(match) {
    var section = $('zrcpt-w-photoset');
    var statusEl = $('zrcpt-w-photoset-status');
    var hintEl = $('zrcpt-w-photoset-hint');
    var sessionsEl = $('zrcpt-w-sessions');
    var emptyEl = $('zrcpt-w-photoset-empty');
    var libraryEl = $('zrcpt-w-library');

    // The uploader is always the primary path; reveal the section immediately.
    if (section) show(section);
    if (sessionsEl) sessionsEl.innerHTML = '';
    if (libraryEl) hide(libraryEl);
    if (emptyEl) hide(emptyEl);
    mediaSessions = [];
    selectedSessionId = null;
    photoExcluded = {};
    photoOrder = {};   // v3.8.0
    setPickerExpanded = false; // v3.8.1

    // No shared library on this site -> uploader is the only path. Nothing more
    // to check; the upload-primary block is already visible.
    if (!zrcptWidgetData.hasMedia) {
      updateGenerateState();
      return;
    }

    if (statusEl) { statusEl.textContent = 'Checking for photos you already captured\u2026'; show(statusEl); }

    var fd = new FormData();
    // Pass GPS if the customer record happened to carry coordinates (rare today).
    if (match.customer_detail && match.customer_detail.gps_lat && match.customer_detail.gps_lng) {
      fd.append('near_lat', match.customer_detail.gps_lat);
      fd.append('near_lng', match.customer_detail.gps_lng);
    }

    ajaxPost('zrcpt_match_media', fd).then(function (res) {
      hide(statusEl);
      // Any failure / unavailable / no own-photos -> uploader only (no error
      // noise; uploading is the expected primary action anyway).
      if (!res || !res.success) { return; }
      var data = res.data || {};

      // IMPORTANT: these sessions are ALWAYS the current user's OWN captures.
      // ZRCPT_Media scopes the query to get_current_user_id(), so we never show
      // photos another user took.
      mediaSessions = data.sessions || [];
      photoExcluded = {};
      photoOrder = {};   // v3.8.0

      if (!data.available || !mediaSessions.length) {
        // No photos of their own -> leave the uploader as the sole path.
        return;
      }

      // The tech HAS their own captures -> reveal the secondary shortcut.
      if (libraryEl) show(libraryEl);
      if (hintEl) {
        hintEl.textContent = (mediaSessions.length === 1
          ? 'You have one photo set from the app. Tap it to review the photos — everything starts included; leave out or reorder any before generating.'
          : 'You have ' + mediaSessions.length + ' photo sets from the app. Tap one to review its photos — everything starts included; leave out or reorder any before generating.');
      }
      renderSessions(mediaSessions);
      // NOTE: unlike before, we do NOT auto-select a set. Uploading is the
      // default; using a captured set is a deliberate tap, and only then do we
      // ask the tech to confirm which photos to include.
    }).catch(function () {
      hide(statusEl);
      // Network hiccup -> uploader only.
    });
  }

  // v3.6.2 - short "upload here, then generate" line in the photo section's
  // empty slot (used when a captured-set selection is explicitly cleared).
  function showNoPhotos(message) {
    var emptyEl = $('zrcpt-w-photoset-empty');
    if (emptyEl) {
      emptyEl.textContent = message || 'Upload the installation photos above, then generate the receipt.';
      show(emptyEl);
    }
    updateGenerateState();
  }

  function roleLabel(role) {
    if (role === 'install') return 'Installation set';
    if (role === 'before') return 'Before / estimate set';
    return 'Earlier set';
  }

  /* v3.4.0 — how many of this set's photos are still included, and the line
   * that tells the tech (lives under the active set's photo grid). */
  function sessUsedCount(s) {
    return (s.photos || []).filter(function (p) { return !photoExcluded[p.media_id]; }).length;
  }

  function sessCountLabel(s) {
    var total = (s.photos || []).length;
    var used = sessUsedCount(s);
    if (used === total) {
      return 'All ' + total + ' photo' + (total === 1 ? '' : 's') + ' included — tap a ✓ to leave one out';
    }
    if (used === 0) {
      return '⚠ Every photo is left out — tap a ＋ to include photos (or pick another set)';
    }
    return used + ' of ' + total + ' photos included · left-out photos won’t be on the receipt';
  }

  /* ==================================================================
   * v3.8.0 — BATCH REVIEW at selection time.
   * The same control the Review & Approve preview already gives (remove +
   * drag-to-reorder) now lives HERE, before anything is generated. The active
   * set shows every photo BIG (mobile-first, 2-up portrait), all included by
   * default; the tech taps a photo to see it full-screen, taps the ✓ to leave
   * one out, and presses-and-drags to reorder. The final order + selection is
   * exactly what the generator receives.
   * ================================================================== */

  /* Photos of a session in the tech's chosen ORDER (default: session order).
   * Any drag persists into photoOrder[sessionId]; stale/missing ids heal. */
  function orderedSessionPhotos(s) {
    var photos = (s && s.photos) ? s.photos.slice() : [];
    var order = s ? photoOrder[s.id] : null;
    if (!order || !order.length) return photos;
    var byId = {};
    photos.forEach(function (p) { byId[p.media_id] = p; });
    var out = [];
    order.forEach(function (mid) {
      if (byId[mid]) { out.push(byId[mid]); delete byId[mid]; }
    });
    photos.forEach(function (p) { if (byId[p.media_id]) out.push(p); }); // new/unknown → append
    return out;
  }

  /* Ensure photoOrder[sessionId] exists (seeded from natural order). */
  function ensureOrder(s) {
    if (!s) return;
    if (!photoOrder[s.id] || !photoOrder[s.id].length) {
      photoOrder[s.id] = (s.photos || []).map(function (p) { return p.media_id; });
    }
  }

  /* Broken-thumbnail healing: thumb 404/HEIC → try the full file URL → if that
   * also fails, show a clean placeholder instead of the browser's broken "?". */
  function wireThumbFallback(img, fullUrl) {
    if (!img) return;
    // onerror PROPERTY (not addEventListener): re-wiring the same element —
    // e.g. the lightbox's single <img> as the tech browses — replaces the
    // handler instead of stacking one per photo.
    img.onerror = function () {
      if (fullUrl && img.src !== fullUrl && img.getAttribute('data-tried-full') !== '1') {
        img.setAttribute('data-tried-full', '1');
        img.src = fullUrl;
        return;
      }
      img.onerror = null;
      var cell = img.closest('.zrcpt-w-ph, .zrcpt-w-sess-thumb, .zrcpt-w-lb-imgwrap');
      if (cell) cell.classList.add('zrcpt-w-ph-broken');
      // Neutral inline SVG placeholder (camera glyph) — never the broken icon.
      img.src = 'data:image/svg+xml;utf8,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">' +
        '<rect width="48" height="48" rx="6" fill="#E2E8F0"/>' +
        '<path d="M16 18h4l2-3h4l2 3h4a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H16a2 2 0 0 1-2-2V20a2 2 0 0 1 2-2z" fill="none" stroke="#94A3B8" stroke-width="2"/>' +
        '<circle cx="24" cy="25" r="4" fill="none" stroke="#94A3B8" stroke-width="2"/></svg>'
      );
    };
  }

  function sessThumbsHTML(s, isActive) {
    if (!isActive) {
      var thumbs = (s.photos || []).slice(0, 5).map(function (p) {
        return '<div class="zrcpt-w-sess-thumb"><img src="' + esc(p.thumb || p.url) + '" data-full="' + esc(p.url || '') + '" alt="" loading="lazy" /></div>';
      }).join('');
      var moreCount = (s.photos || []).length - 5;
      if (moreCount > 0) {
        thumbs += '<div class="zrcpt-w-sess-thumb zrcpt-w-sess-more">+' + moreCount + '</div>';
      }
      return '<div class="zrcpt-w-sess-thumbs">' + thumbs + '</div>' +
        '<div class="zrcpt-w-sess-open-hint">Tap to review these photos</div>';
    }

    ensureOrder(s);
    var cells = orderedSessionPhotos(s).map(function (p, idx) {
      var off = !!photoExcluded[p.media_id];
      return '<div class="zrcpt-w-ph zrcpt-w-ph-lg' + (off ? ' zrcpt-w-ph-off' : '') + '" data-mid="' + p.media_id + '" data-idx="' + idx + '"' +
        ' role="button" tabindex="0" aria-label="Photo ' + (idx + 1) + (off ? ' (left out)' : '') + ' — tap to view large">' +
        '<img src="' + esc(p.thumb || p.url) + '" data-full="' + esc(p.url || '') + '" alt="" loading="lazy" draggable="false" />' +
        '<span class="zrcpt-w-ph-num" aria-hidden="true">' + (idx + 1) + '</span>' +
        '<button type="button" class="zrcpt-w-ph-toggle' + (off ? ' zrcpt-w-ph-toggle-off' : '') + '" data-mid="' + p.media_id + '"' +
          ' aria-pressed="' + (off ? 'false' : 'true') + '"' +
          ' aria-label="' + (off ? 'Left out — tap to include this photo' : 'Included — tap to leave this photo out') + '"' +
          ' title="' + (off ? 'Left out — tap to include' : 'Included — tap to leave out') + '">' +
          (off ? '+' : '✓') +
        '</button>' +
        (off ? '<span class="zrcpt-w-ph-offlabel" aria-hidden="true">Left out</span>' : '') +
      '</div>';
    }).join('');
    return '<div class="zrcpt-w-sess-photos zrcpt-w-sess-photos-lg" data-sid="' + esc(s.id) + '">' + cells + '</div>' +
      '<div class="zrcpt-w-sess-count" data-role="count">' + esc(sessCountLabel(s)) + '</div>' +
      '<div class="zrcpt-w-sess-help">Tap a photo to see it big · tap the <strong>✓</strong> to leave one out · press &amp; drag to reorder</div>';
  }

  function renderSessions(sessions) {
    var el = $('zrcpt-w-sessions');
    if (!el) return;

    // v3.8.1 — COLLAPSE the unchosen sets. Once a set is active, only IT
    // renders; the rest tuck behind one "Change photo set" row so the tech
    // isn't scrolling past every old collection to reach Generate. Re-opening
    // the picker (setPickerExpanded) shows them all again; choosing any set
    // re-collapses.
    var activeSession = selectedSessionId ? findSession(selectedSessionId) : null;
    var collapsed = !!(activeSession && !setPickerExpanded);
    var toRender = collapsed ? [ activeSession ] : sessions;
    var hiddenCount = collapsed ? (sessions.length - 1) : 0;

    var html = '';
    toRender.forEach(function (s) {
      var isActive = (s.id === selectedSessionId);
      var roleCls = 'zrcpt-w-sess-role-' + esc(s.role);

      // v3.4.0 — batch-tagged sets (grouped by a Media-app upload, not by the
      // time heuristic) get a chip so it's obvious WHY they're one set; the
      // upload note (when typed) is shown truncated, full text in the title.
      var tag = '';
      if (s.is_batch) {
        tag = '<span class="zrcpt-w-sess-tag" title="' +
          esc(s.batch_note || 'These photos were uploaded together in the Media app') + '">🏷 Tagged set' +
          (s.batch_note ? ' · ' + esc(truncate(s.batch_note, 26)) : '') + '</span>';
      }

      html += '<div class="zrcpt-w-sess' + (isActive ? ' zrcpt-w-sess-active' : '') + '" data-sid="' + esc(s.id) + '" role="button" tabindex="0">' +
        '<div class="zrcpt-w-sess-head">' +
          '<span class="zrcpt-w-sess-radio" aria-hidden="true"></span>' +
          '<div class="zrcpt-w-sess-meta">' +
            '<span class="zrcpt-w-sess-role ' + roleCls + '">' + esc(roleLabel(s.role)) + '</span>' +
            tag +
            '<span class="zrcpt-w-sess-date">' + esc(s.date_display) + ' · ' + s.photo_count + ' photo' + (s.photo_count === 1 ? '' : 's') +
              (s.has_gps ? ' · 📍 located' : '') + '</span>' +
          '</div>' +
        '</div>' +
        sessThumbsHTML(s, isActive) +
      '</div>';
    });

    // v3.8.1 — the collapsed picker's expander / the expanded picker's note.
    if (collapsed && hiddenCount > 0) {
      html += '<button type="button" id="zrcpt-w-sess-expand" class="zrcpt-w-sess-expand">' +
        '▸ Change photo set <span class="zrcpt-w-sess-expand-n">' + hiddenCount + ' other set' + (hiddenCount === 1 ? '' : 's') + ' hidden</span>' +
      '</button>';
    } else if (activeSession && setPickerExpanded && sessions.length > 1) {
      html += '<button type="button" id="zrcpt-w-sess-collapse" class="zrcpt-w-sess-expand">' +
        '▾ Done — hide the other sets' +
      '</button>';
    }

    el.innerHTML = html;

    // v3.8.1 — expander wiring.
    var expandBtn = $('zrcpt-w-sess-expand');
    if (expandBtn) {
      expandBtn.addEventListener('click', function () {
        setPickerExpanded = true;
        renderSessions(mediaSessions);
      });
    }
    var collapseBtn = $('zrcpt-w-sess-collapse');
    if (collapseBtn) {
      collapseBtn.addEventListener('click', function () {
        setPickerExpanded = false;
        renderSessions(mediaSessions);
      });
    }

    el.querySelectorAll('.zrcpt-w-sess').forEach(function (card) {
      var sid = card.getAttribute('data-sid');
      card.addEventListener('click', function (e) {
        if (e.target.closest('.zrcpt-w-ph, .zrcpt-w-ph-toggle')) return; // photos handle themselves
        selectSession(sid, false);
      });
      card.addEventListener('keydown', function (e) {
        if (e.target.closest('.zrcpt-w-ph, .zrcpt-w-ph-toggle')) return;
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectSession(sid, false); }
      });

      // v3.8.0 — thumbnail healing everywhere (compact strips AND big grid).
      card.querySelectorAll('img[data-full]').forEach(function (img) {
        wireThumbFallback(img, img.getAttribute('data-full') || '');
      });

      // v3.8.0 — the big-grid interactions of the ACTIVE set.
      var grid = card.querySelector('.zrcpt-w-sess-photos-lg');
      if (grid) {
        // (a) ✓ toggle → include/exclude (never opens the viewer).
        grid.querySelectorAll('.zrcpt-w-ph-toggle').forEach(function (tg) {
          tg.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            togglePhotoInSet(parseInt(tg.getAttribute('data-mid'), 10), card);
          });
        });
        // (b) tap/Enter on the photo → full-screen viewer.
        grid.querySelectorAll('.zrcpt-w-ph-lg').forEach(function (cell) {
          cell.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
              e.preventDefault();
              openLightbox(sid, parseInt(cell.getAttribute('data-idx'), 10) || 0);
            }
          });
        });
        // (c) press-and-drag → reorder (also decides tap-vs-drag for (b)).
        wireSetReorder(grid, sid, card);
      }
    });
  }

  /* v3.8.0 — flip one photo in/out of the chosen set, then re-render the
   * active card (the cell shows ✓/＋, "Left out" label, dimming, and the count
   * line updates). Excluded photos keep their spot in the order. */
  function togglePhotoInSet(mid, card) {
    if (!mid) return;
    if (photoExcluded[mid]) delete photoExcluded[mid];
    else photoExcluded[mid] = true;
    renderSessions(mediaSessions);
    updateGenerateState();
    syncLightboxToggle();
  }

  /* ------------------------------------------------------------------
   * v3.8.0 — press-and-drag reorder on the selection grid. Pointer-based
   * (mouse + touch), same feel as the Review & Approve preview: an orange
   * edge marks the drop side; release commits the new order. A short press
   * that never crosses the threshold is a TAP → opens the viewer. Vertical
   * page scrolling on touch keeps working (touch-action: pan-y): a drag only
   * "lifts" on clear horizontal intent or after a long-press (350 ms).
   * ------------------------------------------------------------------ */
  function wireSetReorder(grid, sid, card) {
    var dragEl = null, targetEl = null, dropAfter = false;
    var startX = 0, startY = 0, dragging = false, pointerId = null;
    var pressTimer = null, suppressTap = false;
    var THRESH = 8;

    function clearMarks() {
      grid.querySelectorAll('.zrcpt-w-drop-before,.zrcpt-w-drop-after').forEach(function (c) {
        c.classList.remove('zrcpt-w-drop-before', 'zrcpt-w-drop-after');
      });
    }
    function cellFromPoint(x, y) {
      var e = document.elementFromPoint(x, y);
      return e ? e.closest('.zrcpt-w-ph-lg') : null;
    }
    function lift() {
      if (dragging || !dragEl) return;
      dragging = true;
      suppressTap = true;
      dragEl.classList.add('zrcpt-w-ph-dragging');
      try { grid.setPointerCapture(pointerId); } catch (err) {}
      if (navigator.vibrate) { try { navigator.vibrate(10); } catch (err) {} }
    }
    function reset() {
      if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; }
      if (dragEl) dragEl.classList.remove('zrcpt-w-ph-dragging');
      clearMarks();
      try { grid.releasePointerCapture(pointerId); } catch (err) {}
      dragEl = null; targetEl = null; dropAfter = false; dragging = false; pointerId = null;
    }

    grid.addEventListener('pointerdown', function (e) {
      if (e.button != null && e.button !== 0) return;
      if (e.target.closest('.zrcpt-w-ph-toggle')) return;   // toggles never drag
      var cell = e.target.closest('.zrcpt-w-ph-lg');
      if (!cell) return;
      dragEl = cell;
      pointerId = e.pointerId;
      startX = e.clientX; startY = e.clientY;
      dragging = false; suppressTap = false;
      // Long-press lifts even without horizontal movement (mobile-friendly).
      pressTimer = setTimeout(function () { pressTimer = null; lift(); }, 350);
    });

    grid.addEventListener('pointermove', function (e) {
      if (!dragEl || e.pointerId !== pointerId) return;
      var dx = e.clientX - startX, dy = e.clientY - startY;
      if (!dragging) {
        if (Math.abs(dx) < THRESH && Math.abs(dy) < THRESH) return;
        // Clear horizontal intent lifts immediately; vertical intent = scroll.
        if (Math.abs(dx) > Math.abs(dy)) {
          if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; }
          lift();
        } else {
          // The user is scrolling the page — give the press up entirely.
          reset();
          return;
        }
      }
      if (!dragging) return;
      e.preventDefault();
      var over = cellFromPoint(e.clientX, e.clientY);
      clearMarks();
      if (over && over !== dragEl && over.closest('.zrcpt-w-sess-photos-lg') === grid) {
        var r = over.getBoundingClientRect();
        dropAfter = (e.clientX - r.left) > r.width / 2;
        over.classList.add(dropAfter ? 'zrcpt-w-drop-after' : 'zrcpt-w-drop-before');
        targetEl = over;
      } else {
        targetEl = null;
      }
    });

    grid.addEventListener('pointerup', function (e) {
      if (!dragEl || e.pointerId !== pointerId) return;
      var wasTap = !dragging && !suppressTap;
      var tappedCell = dragEl;
      var commit = dragging && targetEl && targetEl !== dragEl;
      var fromMid = commit ? parseInt(dragEl.getAttribute('data-mid'), 10) : 0;
      var toMid = commit ? parseInt(targetEl.getAttribute('data-mid'), 10) : 0;
      var after = dropAfter;
      reset();

      if (commit && fromMid && toMid) {
        commitReorder(sid, fromMid, toMid, after, card);
      } else if (wasTap && tappedCell) {
        openLightbox(sid, parseInt(tappedCell.getAttribute('data-idx'), 10) || 0);
      }
    });

    grid.addEventListener('pointercancel', function (e) {
      if (!dragEl || e.pointerId !== pointerId) return;
      reset();
    });

    // While a drag is LIVE, stop the browser from turning finger movement into
    // page scroll (which would fire pointercancel and kill a long-press drag).
    // Normal scrolling is untouched — this only bites after a lift.
    grid.addEventListener('touchmove', function (e) {
      if (dragging) e.preventDefault();
    }, { passive: false });
  }

  /* Move fromMid next to toMid (before/after) in photoOrder[sid], re-render. */
  function commitReorder(sid, fromMid, toMid, after, card) {
    var s = findSession(sid);
    if (!s) return;
    ensureOrder(s);
    var order = photoOrder[sid].slice();
    var fi = order.indexOf(fromMid);
    if (fi === -1) return;
    order.splice(fi, 1);
    var ti = order.indexOf(toMid);
    if (ti === -1) { order.splice(fi, 0, fromMid); }
    else { order.splice(after ? ti + 1 : ti, 0, fromMid); }
    photoOrder[sid] = order;
    renderSessions(mediaSessions);
    // Quiet confirmation on the count line.
    var freshCard = document.querySelector('.zrcpt-w-sess[data-sid="' + sid + '"]');
    var count = freshCard ? freshCard.querySelector('[data-role="count"]') : null;
    if (count) count.textContent = sessCountLabel(s) + ' · order updated';
  }

  /* ------------------------------------------------------------------
   * v3.8.0 — FULL-SCREEN photo viewer (pre-generate). Big image, ‹ › nav,
   * swipe on touch, and the include/leave-out toggle right there. Built once,
   * on demand; lives on <body> so it truly fills a phone screen.
   * ------------------------------------------------------------------ */
  function buildLightbox() {
    if ($('zrcpt-w-lb')) return;
    var lb = document.createElement('div');
    lb.id = 'zrcpt-w-lb';
    lb.className = 'zrcpt-w-lb';
    lb.style.display = 'none';
    lb.innerHTML =
        '<div class="zrcpt-w-lb-backdrop"></div>'
      + '<div class="zrcpt-w-lb-panel" role="dialog" aria-modal="true" aria-label="Photo viewer">'
      +   '<button type="button" class="zrcpt-w-lb-close" aria-label="Close">✕</button>'
      +   '<div class="zrcpt-w-lb-imgwrap"><img class="zrcpt-w-lb-img" id="zrcpt-w-lb-img" alt="" draggable="false" /></div>'
      +   '<button type="button" class="zrcpt-w-lb-nav zrcpt-w-lb-prev" aria-label="Previous photo">‹</button>'
      +   '<button type="button" class="zrcpt-w-lb-nav zrcpt-w-lb-next" aria-label="Next photo">›</button>'
      +   '<div class="zrcpt-w-lb-bar">'
      +     '<span class="zrcpt-w-lb-count" id="zrcpt-w-lb-count"></span>'
      +     '<button type="button" class="zrcpt-w-lb-toggle" id="zrcpt-w-lb-toggle"></button>'
      +   '</div>'
      + '</div>';
    document.body.appendChild(lb);

    lb.querySelector('.zrcpt-w-lb-backdrop').addEventListener('click', closeLightbox);
    lb.querySelector('.zrcpt-w-lb-close').addEventListener('click', closeLightbox);
    lb.querySelector('.zrcpt-w-lb-prev').addEventListener('click', function () { stepLightbox(-1); });
    lb.querySelector('.zrcpt-w-lb-next').addEventListener('click', function () { stepLightbox(1); });
    lb.querySelector('#zrcpt-w-lb-toggle').addEventListener('click', function () {
      var p = lightboxPhoto();
      if (p) togglePhotoInSet(p.media_id, null);
    });

    document.addEventListener('keydown', function (e) {
      if (!lightbox.open) return;
      if (e.key === 'Escape') closeLightbox();
      else if (e.key === 'ArrowLeft') stepLightbox(-1);
      else if (e.key === 'ArrowRight') stepLightbox(1);
    });

    // Swipe left/right to change photos.
    var sx = null, sy = null;
    var wrap = lb.querySelector('.zrcpt-w-lb-imgwrap');
    wrap.addEventListener('pointerdown', function (e) { sx = e.clientX; sy = e.clientY; });
    wrap.addEventListener('pointerup', function (e) {
      if (sx === null) return;
      var dx = e.clientX - sx, dy = e.clientY - sy;
      sx = null; sy = null;
      if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) stepLightbox(dx < 0 ? 1 : -1);
    });
  }

  function lightboxPhoto() {
    var s = findSession(lightbox.sessionId);
    if (!s) return null;
    var photos = orderedSessionPhotos(s);
    return photos[lightbox.index] || null;
  }

  function openLightbox(sid, index) {
    buildLightbox();
    lightbox.open = true;
    lightbox.sessionId = sid;
    lightbox.index = index || 0;
    var lb = $('zrcpt-w-lb');
    if (lb) lb.style.display = '';
    document.body.classList.add('zrcpt-w-modal-open');
    paintLightbox();
  }

  function closeLightbox() {
    if (!lightbox.open) return;
    lightbox.open = false;
    var lb = $('zrcpt-w-lb');
    if (lb) lb.style.display = 'none';
    // Don't strip the body lock if the Approve modal is open underneath.
    var approveModal = $('zrcpt-w-approve-modal');
    if (!approveModal || approveModal.style.display === 'none') {
      document.body.classList.remove('zrcpt-w-modal-open');
    }
  }

  function stepLightbox(delta) {
    var s = findSession(lightbox.sessionId);
    if (!s) return;
    var n = orderedSessionPhotos(s).length;
    if (!n) return;
    lightbox.index = (lightbox.index + delta + n) % n;
    paintLightbox();
  }

  function paintLightbox() {
    var p = lightboxPhoto();
    var s = findSession(lightbox.sessionId);
    if (!p || !s) { closeLightbox(); return; }
    var img = $('zrcpt-w-lb-img');
    if (img) {
      img.removeAttribute('data-tried-full');
      var wrap = img.closest('.zrcpt-w-lb-imgwrap');
      if (wrap) wrap.classList.remove('zrcpt-w-ph-broken');
      wireThumbFallback(img, p.url || '');   // (re)arm BEFORE src so errors catch
      img.src = p.url || p.thumb || '';
    }
    var count = $('zrcpt-w-lb-count');
    if (count) count.textContent = (lightbox.index + 1) + ' / ' + orderedSessionPhotos(s).length;
    syncLightboxToggle();
  }

  /* Keep the viewer's include/leave-out button truthful after any toggle. */
  function syncLightboxToggle() {
    if (!lightbox.open) return;
    var p = lightboxPhoto();
    var tg = $('zrcpt-w-lb-toggle');
    if (!p || !tg) return;
    var off = !!photoExcluded[p.media_id];
    tg.classList.toggle('zrcpt-w-lb-toggle-off', off);
    tg.innerHTML = off
      ? '＋ Left out — tap to include'
      : '✓ Included — tap to leave out';
  }

  function selectSession(sessionId, isAuto) {
    selectedSessionId = sessionId;
    setPickerExpanded = false;   // v3.8.1 — choosing a set collapses the rest

    // v3.4.0 — re-render so the ACTIVE card swaps its 5-thumb preview for the
    // full toggleable photo grid (and the previous card swaps back).
    renderSessions(mediaSessions);

    // Selecting a library set clears any manual photo uploads (one source wins).
    if (photoData.length) {
      photoData = [];
      var thumbsEl = $('zrcpt-w-thumbs');
      if (thumbsEl) thumbsEl.innerHTML = '';
      updatePhotoCount();
    }

    // Pre-fill install date from this set's EXIF capture date.
    var session = findSession(sessionId);
    if (session && session.date_input) {
      var dateInput = $('zrcpt-w-date');
      // Only auto-fill if the user hasn't manually typed a date.
      if (dateInput && (!dateInput.value || dateAutoFilled)) {
        dateInput.value = session.date_input;
        dateAutoFilled = true;
        var srcEl = $('zrcpt-w-date-source');
        if (srcEl) srcEl.textContent = '(from photo date)';
      }
    }

    updateGenerateState();

    if (!isAuto) {
      var detailsEl = $('zrcpt-w-details');
      if (detailsEl) detailsEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

  function findSession(sessionId) {
    for (var i = 0; i < mediaSessions.length; i++) {
      if (mediaSessions[i].id === sessionId) return mediaSessions[i];
    }
    return null;
  }

  function selectedSessionMediaIds() {
    var s = findSession(selectedSessionId);
    if (!s || !s.photos) return [];
    // v3.4.0 — exclusions filtered here so provenance (media_ids) only ever
    // records photos that were actually used.
    // v3.8.0 — in the tech's chosen order.
    return orderedSessionPhotos(s).map(function (p) { return p.media_id; })
      .filter(function (mid) { return mid && !photoExcluded[mid]; });
  }

  // Full photo objects for the selected library set: { url, media_id }.
  // We send these through photo_data (with their resolved file_url) rather than
  // id-only via media_ids, because the generator resolves photo_data['url']
  // directly. media_id is the ZDZ_User_Media ROW id (NOT a wp attachment id), so
  // the server's id->attachment lookup can't resolve it — only the url can.
  function selectedSessionPhotos() {
    var s = findSession(selectedSessionId);
    if (!s || !s.photos) return [];
    // v3.4.0 — tap-excluded photos never reach the generator.
    // v3.8.0 — in the tech's chosen (dragged) order: the receipt's gallery
    // comes out exactly as arranged on screen.
    return orderedSessionPhotos(s).filter(function (p) { return p && p.url && !photoExcluded[p.media_id]; });
  }

  /* v3.6.2 — INVOICE-FILE FALLBACK (no-job path).
   * The photo uploader is always the primary photo step once a job is chosen
   * (see loadPhotoSets). 'Manual mode' now means only the no-lookup path where
   * the tech attaches an invoice FILE instead of using Find; that's what
   * zrcpt-w-manual now contains. Kept so the Generate button reveals on the
   * no-job path and the invoice-file dropzone is available. */
  function openManual(forced) {
    manualMode = true;
    var manual = $('zrcpt-w-manual');
    if (manual) show(manual);
    updateGenerateState();
  }

  function closeManual() {
    manualMode = false;
    var manual = $('zrcpt-w-manual');
    if (manual) hide(manual);
    updateGenerateState();
  }

  function clearLookup() {
    selectedLookup = null;
    selectedInvoices = [];
    lookupInstallNotes = null;
    mediaSessions = [];
    selectedSessionId = null;
    photoExcluded = {};
    photoOrder = {};      // v3.8.0
    redoMode = false;     // v3.8.0
    setPickerExpanded = false; // v3.8.1
    closeLightbox();      // v3.8.0
    dateAutoFilled = false;

    hide($('zrcpt-w-lookup-confirmed'));
    hide($('zrcpt-w-lookup-nutshell'));
    hide($('zrcpt-w-photoset'));
    hide($('zrcpt-w-details'));
    // Restore the invoice-file fallback (hidden while a job was selected).
    var invSection = $('zrcpt-w-invoice-section');
    if (invSection) show(invSection);

    var sessionsEl = $('zrcpt-w-sessions');
    if (sessionsEl) sessionsEl.innerHTML = '';
    var libraryEl = $('zrcpt-w-library');
    if (libraryEl) hide(libraryEl);
    hide($('zrcpt-w-photoset-empty'));

    var cardsEl = $('zrcpt-w-lookup-cards');
    if (cardsEl) cardsEl.innerHTML = '';

    var errEl = $('zrcpt-w-lookup-error');
    if (errEl) { errEl.textContent = ''; hide(errEl); }

    closeManual();

    var srcEl = $('zrcpt-w-date-source');
    if (srcEl) srcEl.textContent = '';

    updateGenerateState();
  }

  /* ==================================================================
   * PHOTO UPLOAD
   * ================================================================== */

  function handlePhotoFiles(files) {
    for (var i = 0; i < files.length; i++) {
      processAndUpload(files[i]);
    }
  }

  function processAndUpload(file) {
    if (isHeic(file)) {
      convertHeicToJpeg(file)
        .then(function (jpgFile) { uploadPhoto(jpgFile); })
        .catch(function () { uploadPhoto(file); });
    } else {
      uploadPhoto(file);
    }
  }

  function uploadPhoto(file) {
    var idx = photoData.length;
    var obj = { url: null, id: null, thumb: null, el: null };
    photoData.push(obj);
    updatePhotoCount();
    updateGenerateState();

    // Create thumbnail placeholder
    var thumbsEl = $('zrcpt-w-thumbs');
    if (!thumbsEl) return;

    var thumbEl = document.createElement('div');
    thumbEl.className = 'zrcpt-w-thumb loading';
    thumbEl.innerHTML = '<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" alt="Uploading..." />';
    thumbsEl.appendChild(thumbEl);
    obj.el = thumbEl;

    // Show local preview if possible
    if (file.type && file.type.startsWith('image/')) {
      var reader = new FileReader();
      reader.onload = function (e) {
        var img = thumbEl.querySelector('img');
        if (img) img.src = e.target.result;
      };
      reader.readAsDataURL(file);
    }

    uploading++;
    updateGenerateState();

    var fd = new FormData();
    fd.append('action', 'zrcpt_upload_photo');
    fd.append('_nonce', zrcptWidgetData.nonce);
    fd.append('photo', file);

    // v3.6.2 — send the selected job's customer so the server can tag this
    // upload in the shared library (saved company-wide / "For Everybody" and
    // attributed to the customer). Empty when no job is selected yet — the
    // server then saves it untagged, still company-wide.
    if (selectedLookup) {
      fd.append('customer_name', selectedLookup.customer_name || '');
      fd.append('customer_id', selectedLookup.customer_id || '');
    }

    fetch(zrcptWidgetData.ajaxurl, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        uploading--;
        updateGenerateState();

        if (res.success) {
          obj.url = res.data.url;
          obj.id = res.data.id;
          obj.thumb = res.data.thumbnail || res.data.url;
          // v3.6.2 — ZDZ_User_Media row id when the photo was mirrored into the
          // shared library (company-wide). 0/absent on older themes.
          obj.libraryId = res.data.library_id || 0;

          thumbEl.className = 'zrcpt-w-thumb';
          var imgEl = thumbEl.querySelector('img');
          if (imgEl) imgEl.src = obj.thumb;

          // Add remove button
          var rmBtn = document.createElement('button');
          rmBtn.className = 'zrcpt-w-thumb-rm';
          rmBtn.type = 'button';
          rmBtn.innerHTML = '&times;';
          rmBtn.addEventListener('click', function () {
            photoData.splice(photoData.indexOf(obj), 1);
            thumbEl.remove();
            updatePhotoCount();
            updateGenerateState();
          });
          thumbEl.appendChild(rmBtn);

          updatePhotoCount();
        } else {
          // v3.0.0: Show error instead of silently removing
          thumbEl.className = 'zrcpt-w-thumb error';
          var imgEl = thumbEl.querySelector('img');
          if (imgEl) imgEl.alt = 'Upload failed';
          thumbEl.title = (res.data && typeof res.data === 'string') ? res.data : 'Upload failed — tap to dismiss';
          thumbEl.style.cursor = 'pointer';
          thumbEl.addEventListener('click', function () {
            thumbEl.remove();
            var idx = photoData.indexOf(obj);
            if (idx > -1) photoData.splice(idx, 1);
            updatePhotoCount();
            updateGenerateState();
          });
          // Also log for debugging
          console.error('ZRCPT photo upload failed:', res);
          updatePhotoCount();
        }
      })
      .catch(function (err) {
        uploading--;
        // v3.0.0: Show error instead of silently removing
        thumbEl.className = 'zrcpt-w-thumb error';
        thumbEl.title = 'Network error — tap to dismiss';
        thumbEl.style.cursor = 'pointer';
        thumbEl.addEventListener('click', function () {
          thumbEl.remove();
          var idx = photoData.indexOf(obj);
          if (idx > -1) photoData.splice(idx, 1);
          updatePhotoCount();
          updateGenerateState();
        });
        console.error('ZRCPT photo upload network error:', err);
        updatePhotoCount();
        updateGenerateState();
      });
  }

  function updatePhotoCount() {
    var countEl = $('zrcpt-w-photo-count');
    var ready = photoData.filter(function (p) { return p.url; }).length;
    if (countEl) {
      if (ready === 0 && uploading === 0) {
        countEl.textContent = '';
        countEl.classList.remove('uploading');
      } else if (uploading > 0) {
        countEl.textContent = ready + ' photo' + (ready !== 1 ? 's' : '') + ' ready · ' + uploading + ' uploading…';
        countEl.classList.add('uploading');
      } else {
        countEl.textContent = ready + ' photo' + (ready !== 1 ? 's' : '') + ' ready ✔';
        countEl.classList.remove('uploading');
      }
    }
    // v3.6.2 — show the confirm checkbox only once an upload is ready, and keep
    // its label honest about the count. Any change to the uploaded set clears a
    // prior confirmation so the tech re-affirms the exact set.
    var wrap = $('zrcpt-w-upload-confirm-wrap');
    var chk = $('zrcpt-w-upload-confirm');
    var txt = $('zrcpt-w-upload-confirm-text');
    if (wrap) {
      if (ready > 0) {
        wrap.style.display = '';
        if (txt) txt.textContent = 'These ' + ready + ' photo' + (ready !== 1 ? 's are' : ' is') + ' the ' + (ready !== 1 ? 'ones' : 'one') + ' I want to use for this receipt.';
      } else {
        wrap.style.display = 'none';
        if (chk) chk.checked = false;
        uploadConfirmed = false;
      }
    }
  }

  function updateGenerateState() {
    var btn = $('zrcpt-w-generate');
    if (!btn) return;

    var readyPhotos = photoData.filter(function (p) { return p.url; }).length;
    var hasLibraryPhotos = !!selectedSessionId && selectedSessionMediaIds().length > 0;
    var usingUpload = readyPhotos > 0;
    var hasPhotos = hasLibraryPhotos || usingUpload;

    // A "source" is the job (lookup) or, as a fallback, an uploaded invoice file.
    var hasSource = selectedLookup || invoiceFile;

    // Reveal the Generate button only once a job is chosen (or manual mode), so
    // the widget reads as a clean step-by-step flow.
    if (hasSource || manualMode) {
      btn.style.display = '';
    } else {
      btn.style.display = 'none';
    }

    // v3.8.0 — honest button copy: a redo is not a plain generate. Keep the
    // leading SVG icon, swap only the text node.
    var wantLabel = redoMode ? 'Redo Receipt (same link)' : 'Generate Receipt';
    if (btn.getAttribute('data-label') !== wantLabel) {
      btn.setAttribute('data-label', wantLabel);
      var icon = btn.querySelector('svg');
      btn.textContent = '';
      if (icon) btn.appendChild(icon);
      btn.appendChild(document.createTextNode(' ' + wantLabel));
      btn.classList.toggle('zrcpt-w-btn-redo', redoMode);
    }

    // v3.6.2 — on the UPLOAD path the tech must tick the confirm box. A chosen
    // library set already confirms its photos per-photo, so it doesn't gate here.
    var confirmOk = hasLibraryPhotos || !usingUpload || uploadConfirmed;
    btn.disabled = uploading > 0 || !hasPhotos || !hasSource || !confirmOk;
  }

  /* ==================================================================
   * VIEW MANAGEMENT
   * ================================================================== */

  // v3.3.3 — CLS guard. Swapping the tall input view for the short success/error
  // card shrank the widget, which shoved every dashboard widget below it upward
  // (the layout shift). Before swapping, pin the panel's CURRENT height as a
  // min-height so the widget never collapses; release it when we return to input.
  function pinStageHeight() {
    var panel = $('zrcpt-w-tab-input');
    if (!panel) return;
    var h = panel.offsetHeight;
    if (h > 0) panel.style.minHeight = h + 'px';
  }
  function releaseStageHeight() {
    var panel = $('zrcpt-w-tab-input');
    if (panel) panel.style.minHeight = '';
  }

  function showInputView() {
    releaseStageHeight();
    var inputEl = $('zrcpt-w-input');
    if (inputEl) { inputEl.style.opacity = ''; inputEl.style.pointerEvents = ''; }
    show($('zrcpt-w-input'));
    hide($('zrcpt-w-status'));
    hide($('zrcpt-w-success'));
    hide($('zrcpt-w-error'));
  }

  function showStatusView() {
    // v3.0.0: DON'T hide the input view — overlay the status on top.
    // This prevents the widget from collapsing in height and confusing the user.
    var inputEl = $('zrcpt-w-input');
    if (inputEl) {
      inputEl.style.opacity = '0.15';
      inputEl.style.pointerEvents = 'none';
    }
    show($('zrcpt-w-status'));
    hide($('zrcpt-w-success'));
    hide($('zrcpt-w-error'));

    // Scroll the status into view so user sees the progress
    var statusEl = $('zrcpt-w-status');
    if (statusEl) statusEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  /* v3.8.1 — post-swap landing. The v3.3.3 pin stopped the page from jumping,
   * but it left the panel frozen at the FULL form height: the user — parked at
   * the bottom by the Generate button — stared at dead space and had to scroll
   * way back up to find "Receipt Generated". Now the swap happens under the
   * pin (nothing moves), the viewport is anchored onto the result card, and
   * only THEN does the pin release — the leftover space collapses below the
   * user's anchor point, so nothing they can see shifts. */
  function landOnResultCard() {
    var panel = $('zrcpt-w-tab-input');
    if (!panel) { return; }
    // Next frame: layout reflects the swap; anchor the viewport on the card.
    requestAnimationFrame(function () {
      try {
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } catch (e) {
        panel.scrollIntoView();
      }
      // Release the height pin once we're anchored. The empty pinned space
      // sits BELOW the result card, so collapsing it never moves the card.
      setTimeout(releaseStageHeight, 900);
    });
  }

  function showSuccessView(data) {
    pinStageHeight(); // hold the widget's height so the page below doesn't jump
    var inputEl = $('zrcpt-w-input');
    if (inputEl) { inputEl.style.opacity = ''; inputEl.style.pointerEvents = ''; }
    hide($('zrcpt-w-input'));
    hide($('zrcpt-w-status'));
    show($('zrcpt-w-success'));
    hide($('zrcpt-w-error'));
    landOnResultCard(); // v3.8.1 — take the user TO the confirmation

    var addrEl = $('zrcpt-w-res-address');
    var dateEl = $('zrcpt-w-res-date');
    var ventsEl = $('zrcpt-w-res-vents');
    var photosEl = $('zrcpt-w-res-photos');
    var linkEl = $('zrcpt-w-view-link');
    var fbEl = $('zrcpt-w-res-fb');
    var fbTextEl = $('zrcpt-w-res-fb-text');

    if (addrEl) addrEl.textContent = data.address || '--';
    if (dateEl) dateEl.textContent = data.install_date || '--';
    if (ventsEl) ventsEl.textContent = data.vents || '--';
    if (photosEl) photosEl.textContent = data.photo_count || '0';
    if (linkEl) linkEl.href = data.permalink || '#';

    // v3.8.0 \u2014 say plainly whether this was a REDO (same link) or a new
    // receipt, and what happens next: NOTHING has been emailed. The receipt
    // needs Review & Approve, then a deliberate Send. One tap takes them there.
    var titleEl = document.querySelector('.zrcpt-w-success-title');
    if (titleEl) {
      titleEl.textContent = data.updated ? 'Receipt Updated' : 'Receipt Generated';
    }
    var nextEl = $('zrcpt-w-res-next');
    if (!nextEl) {
      nextEl = document.createElement('div');
      nextEl.id = 'zrcpt-w-res-next';
      nextEl.className = 'zrcpt-w-res-next';
      var panel = document.querySelector('.zrcpt-w-success-panel');
      var anchorBtn = $('zrcpt-w-view-link');
      if (panel && anchorBtn) panel.insertBefore(nextEl, anchorBtn);
    }
    if (nextEl) {
      var prevSent = data.state && data.state.prev_sent_at ? data.state.prev_sent_at : '';
      var lines = '';
      if (data.updated) {
        lines += '<div class="zrcpt-w-res-next-line">\u21bb This <strong>replaced the existing receipt</strong> \u2014 same link the customer already has.</div>';
      }
      lines += '<div class="zrcpt-w-res-next-line">\u2709 <strong>No email has been sent.</strong> Review &amp; approve the receipt, then send it from History.'
        + (prevSent ? ' (Previously sent ' + esc(prevSent) + ' \u2014 it needs a fresh approval before it can be re-sent.)' : '')
        + '</div>';
      nextEl.innerHTML = lines;
      show(nextEl);
    }
    var reviewBtn = $('zrcpt-w-review-now');
    if (!reviewBtn) {
      reviewBtn = document.createElement('button');
      reviewBtn.id = 'zrcpt-w-review-now';
      reviewBtn.type = 'button';
      reviewBtn.className = 'zrcpt-w-btn zrcpt-w-btn-primary zrcpt-w-btn-full';
      var panel2 = document.querySelector('.zrcpt-w-success-panel');
      var anchorBtn2 = $('zrcpt-w-view-link');
      if (panel2 && anchorBtn2) panel2.insertBefore(reviewBtn, anchorBtn2);
    }
    if (reviewBtn) {
      reviewBtn.textContent = 'Review & approve now \u2192';
      reviewBtn.onclick = function () {
        if (data.post_id) openApproveModal(parseInt(data.post_id, 10));
      };
    }
    // The view link becomes the secondary action.
    if (linkEl) {
      linkEl.classList.remove('zrcpt-w-btn-primary');
      linkEl.classList.add('zrcpt-w-btn-secondary');
      linkEl.style.marginTop = '8px';
    }

    if (fbEl && fbTextEl) {
      if (data.fb_linked) {
        fbTextEl.textContent = '\u2713 Linked to ' + (data.fb_doc_type || 'document') + ' #' + (data.fb_doc_number || '?');
        show(fbEl);
      } else if (data.fb_error) {
        fbTextEl.textContent = data.fb_error;
        show(fbEl);
      }
    }
  }

  function showErrorView(data) {
    pinStageHeight(); // hold the widget's height so the page below doesn't jump
    var inputEl = $('zrcpt-w-input');
    if (inputEl) { inputEl.style.opacity = ''; inputEl.style.pointerEvents = ''; }
    hide($('zrcpt-w-input'));
    hide($('zrcpt-w-status'));
    hide($('zrcpt-w-success'));
    show($('zrcpt-w-error'));
    landOnResultCard(); // v3.8.1 — same landing for the error card

    var msgEl = $('zrcpt-w-error-msg');
    if (msgEl) {
      msgEl.textContent = typeof data === 'string' ? data : (data.message || 'Something went wrong.');
    }
  }

  function resetForm() {
    invoiceFile = null;
    photoData = [];
    uploading = 0;
    uploadConfirmed = false;
    selectedLookup = null;
    lookupInstallNotes = null;
    mediaSessions = [];
    selectedSessionId = null;
    photoExcluded = {};   // v3.4.0
    photoOrder = {};      // v3.8.0
    redoMode = false;     // v3.8.0
    setPickerExpanded = false; // v3.8.1
    dateAutoFilled = false;

    var thumbsEl = $('zrcpt-w-thumbs');
    if (thumbsEl) thumbsEl.innerHTML = '';

    var invNameEl = $('zrcpt-w-invoice-name');
    if (invNameEl) invNameEl.textContent = '';

    var countEl = $('zrcpt-w-photo-count');
    if (countEl) countEl.textContent = '';

    // v3.1.0: Don't pre-fill today's date — it should come from the photo's EXIF
    // once a set is chosen. Leave it blank so the source label stays accurate.
    var dateInput = $('zrcpt-w-date');
    if (dateInput) dateInput.value = '';
    var srcEl = $('zrcpt-w-date-source');
    if (srcEl) srcEl.textContent = '';

    var linkInput = $('zrcpt-w-link');
    if (linkInput) linkInput.value = '';

    var lookupInput = $('zrcpt-w-lookup-input');
    if (lookupInput) lookupInput.value = '';

    clearLookup(); // also hides photoset/details/manual and resets the toggle

    hide($('zrcpt-w-res-fb'));

    updateGenerateState();
    showInputView();

    var lookupEl = $('zrcpt-w-lookup-input');
    if (lookupEl) setTimeout(function () { lookupEl.focus(); }, 60);
  }

  /* ==================================================================
   * GENERATE RECEIPT
   * ================================================================== */

  function generateReceipt() {
    // A job (lookup) or, as a fallback, an uploaded invoice file is required.
    if (!invoiceFile && !selectedLookup) {
      var errHint = $('zrcpt-w-lookup-error');
      if (errHint) {
        errHint.textContent = 'Find the job first (or upload an invoice file).';
        show(errHint);
      }
      return;
    }

    var dateVal = ($('zrcpt-w-date') || {}).value || '';
    if (!dateVal) {
      var dateInput = $('zrcpt-w-date');
      if (dateInput) {
        dateInput.classList.add('zrcpt-w-input-error');
        setTimeout(function () { dateInput.classList.remove('zrcpt-w-input-error'); }, 3000);
      }
      return;
    }

    // Photos can come from the library (selected set) OR manual upload.
    var libraryPhotos = selectedSessionId ? selectedSessionPhotos() : [];
    var readyPhotos = photoData.filter(function (p) { return p.url; });

    if (!libraryPhotos.length && !readyPhotos.length) {
      // Nudge toward the photo set first, then the upload fallback.
      var emptyEl = $('zrcpt-w-photoset-empty');
      if (mediaSessions.length) {
        var hintEl = $('zrcpt-w-photoset-hint');
        if (hintEl) {
          hintEl.textContent = '\u26A0 Pick the installation photo set above (or upload photos).';
        }
      } else if (emptyEl) {
        emptyEl.textContent = 'Add at least one photo — pick a set from your library or upload below.';
        show(emptyEl);
      }
      return;
    }

    // Build FormData
    var fd = new FormData();
    fd.append('action', 'zrcpt_generate');
    fd.append('_nonce', zrcptWidgetData.nonce);
    fd.append('install_date', dateVal);
    fd.append('invoice_url', ($('zrcpt-w-link') || {}).value || '');
    fd.append('mode', zrcptWidgetData.mode || 'tagged');

    // Photo payload. BOTH sources go through photo_data:
    //   • Manually uploaded photos: { url, id } — id is a real wp attachment id
    //     (safe to re-parent onto the receipt post).
    //   • Library set photos: { url, library:true } and NO id — the url is the
    //     resolved ZDZ_User_Media file_url, and library:true ensures the server
    //     does not re-parent them (they stay in the user's "My Photos"). We omit
    //     id deliberately: media_id is a ZDZ_User_Media row id, not a wp
    //     attachment id, so sending it would only mislead the id->attachment
    //     lookup. (This is the fix for "Could not resolve any photo URLs": the
    //     old code sent library photos as id-only media_ids and dropped the url.)
    var photoPayload = readyPhotos.map(function (p) {
      return { url: p.url, id: p.id };
    }).concat(libraryPhotos.map(function (p) {
      // Send the real WP attachment id (NOT media_id, the row id) so the server
      // records provenance (_source_media_ids) and treats these as library
      // captures. library:true routes them to $library_ids, so they are NOT
      // re-parented — they stay in the tech's My Photos. url is included as a
      // resolution fallback and is what the generator embeds.
      return { url: p.url, id: (p.attachment_id || 0), library: true };
    }));
    fd.append('photo_data', JSON.stringify(photoPayload));

    // Include lookup data if available. v3.3.0: when combining several invoices
    // into one receipt, merge every selected invoice's line items, and send the
    // full set of invoice numbers/urls so the server can verify each is an
    // invoice and save provenance for all of them. The primary (first) invoice
    // still supplies the customer block + invoice_url.
    if (selectedLookup) {
      var setInvoices = (selectedInvoices && selectedInvoices.length) ? selectedInvoices : [ selectedLookup ];

      var mergedLines = [];
      setInvoices.forEach(function (inv) {
        (inv.lines || []).forEach(function (ln) { mergedLines.push(ln); });
      });

      fd.append('lookup_data', JSON.stringify({
        type: selectedLookup.type,
        number: selectedLookup.number,
        customer_id: selectedLookup.customer_id,
        customer_name: selectedLookup.customer_name,
        reference: selectedLookup.reference || '',
        invoice_url: selectedLookup.invoice_url || '',
        lines: mergedLines,
        customer_detail: selectedLookup.customer_detail || {},
      }));

      // The authoritative invoice set: numbers (digits) the server re-checks are
      // invoices, plus a parallel list of {number,type,url} for provenance.
      fd.append('invoice_numbers', JSON.stringify(
        setInvoices.map(function (inv) { return String(inv.number); })
      ));
      fd.append('invoice_set', JSON.stringify(
        setInvoices.map(function (inv) {
          return { number: String(inv.number), type: inv.type, url: inv.invoice_url || '' };
        })
      ));

      if (lookupInstallNotes && lookupInstallNotes.length) {
        fd.append('install_notes', JSON.stringify(lookupInstallNotes));
      }
    }

    // Fallback: include invoice file if uploaded
    if (invoiceFile) {
      fd.append('invoice_file', invoiceFile);
    }

    // Switch to status view
    showStatusView();
    startSteps();

    var genBtn = $('zrcpt-w-generate');
    if (genBtn) genBtn.disabled = true;

    // Send request
    fetch(zrcptWidgetData.ajaxurl, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        stopSteps();
        if (res.success) {
          showSuccessView(res.data);
          loadHistory();
        } else {
          showErrorView(res.data || 'Unknown error.');
        }
      })
      .catch(function (err) {
        stopSteps();
        showErrorView('Network error: ' + (err.message || 'Please try again.'));
      })
      .finally(function () {
        if (genBtn) genBtn.disabled = false;
      });
  }

  /* ==================================================================
   * HISTORY TAB
   * ================================================================== */

  function loadHistory() {
    var fd = new FormData();
    fd.append('action', 'zrcpt_widget_stats');
    fd.append('nonce', zrcptWidgetData.nonce);

    fetch(zrcptWidgetData.ajaxurl, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.success) return;
        var d = res.data;

        var totalEl = $('zrcpt-w-total');
        var monthEl = $('zrcpt-w-month');
        var latestEl = $('zrcpt-w-latest');

        if (totalEl) totalEl.textContent = d.total || '0';
        if (monthEl) monthEl.textContent = d.this_month || '0';
        if (latestEl) latestEl.textContent = d.latest_date || '\u2014';

        var recentEl = $('zrcpt-w-recent');
        if (!recentEl) return;

        if (!d.recent || d.recent.length === 0) {
          recentEl.innerHTML = '<div class="zrcpt-w-empty">No receipts yet. Generate your first one!</div>';
          return;
        }

        var html = '';
        d.recent.forEach(function (item) {
          var displayTitle = item.address || item.title || 'Receipt';

          // v3.6.0 — status badge: Sent > Approved > Draft.
          // v3.8.0 \u2014 a REDONE receipt (regenerated after it was sent) reads
          // "Redone \u2014 approve & re-send" so nobody thinks the customer
          // already has the new version.
          var badge = '';
          if (item.sent) {
            badge = '<span class="zrcpt-w-badge zrcpt-w-badge-sent">Sent</span>';
          } else if (item.approved) {
            badge = '<span class="zrcpt-w-badge zrcpt-w-badge-approved">Approved</span>';
          } else if (item.prev_sent_at) {
            badge = '<span class="zrcpt-w-badge zrcpt-w-badge-redone">Redone \u2014 approve &amp; re-send</span>';
          } else {
            badge = '<span class="zrcpt-w-badge zrcpt-w-badge-draft">Needs approval</span>';
          }

          // Meta line: date + (who approved / when sent).
          var sub = esc(item.date);
          if (item.sent && item.sent_at) {
            sub += ' \u00b7 Sent ' + esc(item.sent_at);
          } else if (item.approved && item.approved_by) {
            sub += ' \u00b7 Approved by ' + esc(item.approved_by);
          } else if (item.prev_sent_at) {
            sub += ' \u00b7 Previously sent ' + esc(item.prev_sent_at) + ' \u2014 this redo has NOT gone out yet';
          }

          // Primary action button depends on state.
          var action;
          if (item.sent) {
            // Already sent — allow re-opening to view/re-send.
            action = '<button type="button" class="zrcpt-w-item-action zrcpt-w-item-action-ghost" data-act="review" data-id="' + item.id + '">View</button>';
          } else if (item.approved) {
            action = '<button type="button" class="zrcpt-w-item-action zrcpt-w-item-action-send" data-act="review" data-id="' + item.id + '">Send via email</button>';
          } else {
            action = '<button type="button" class="zrcpt-w-item-action zrcpt-w-item-action-approve" data-act="review" data-id="' + item.id + '">Review &amp; approve</button>';
          }

          // v3.6.1 — a clickable + copyable link to the receipt page (NOT the
          // customer email — only the public receipt URL is ever shown).
          var linkRow = '';
          if (item.permalink) {
            linkRow = '<div class="zrcpt-w-item-link">'
              + '<a href="' + esc(item.permalink) + '" target="_blank" rel="noopener" class="zrcpt-w-item-link-a" title="' + esc(item.permalink) + '">'
              + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>'
              + '<span class="zrcpt-w-item-link-text">' + esc(item.permalink) + '</span>'
              + '</a>'
              + '<button type="button" class="zrcpt-w-item-copy" data-copy="' + esc(item.permalink) + '" title="Copy link" aria-label="Copy receipt link">'
              + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>'
              + '<span class="zrcpt-w-item-copy-label">Copy</span>'
              + '</button>'
              + '</div>';
          }

          // v3.9.0 — deletion state + controls.
          //   • Pending request → amber line with the reason; requester/admin
          //     can cancel it.
          //   • Admins → direct "Delete" (moves to Trash; restorable from
          //     Manage Receipts in wp-admin).
          //   • Everyone else → "Request deletion" (reason required, goes to
          //     the admin).
          var isAdmin = !!zrcptWidgetData.isAdmin;
          var delReqRow = '';
          var delBtn = '';
          if (item.del_req) {
            badge += ' <span class="zrcpt-w-badge zrcpt-w-badge-delreq">Deletion requested</span>';
            delReqRow = '<div class="zrcpt-w-delreq-line">🗑 Deletion requested by ' + esc(item.del_req.by_name)
              + (item.del_req.at ? ' on ' + esc(item.del_req.at) : '')
              + ': “' + esc(item.del_req.reason) + '”'
              + ((item.del_req.mine || isAdmin)
                  ? ' <button type="button" class="zrcpt-w-delreq-cancel" data-id="' + item.id + '">Cancel request</button>'
                  : '')
              + (isAdmin ? ' <span class="zrcpt-w-delreq-adminhint">Approve it from wp-admin → Manage Receipts (or Delete here).</span>' : '')
              + '</div>';
          }
          if (isAdmin) {
            delBtn = '<button type="button" class="zrcpt-w-item-del" data-id="' + item.id + '" title="Delete this receipt (moves to Trash; restorable in wp-admin)">Delete</button>';
          } else if (!item.del_req) {
            delBtn = '<button type="button" class="zrcpt-w-item-reqdel" data-id="' + item.id + '">Request deletion</button>';
          }

          html += '<div class="zrcpt-w-item" data-item-id="' + item.id + '">'
            + '  <div class="zrcpt-w-item-icon">'
            + '    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            + '      <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/>'
            + '      <path d="M14 8H8"/><path d="M16 12H8"/><path d="M13 16H8"/>'
            + '    </svg>'
            + '  </div>'
            + '  <div class="zrcpt-w-item-info">'
            + '    <div class="zrcpt-w-item-title">' + esc(displayTitle) + ' ' + badge + '</div>'
            + '    <div class="zrcpt-w-item-meta">' + sub + '</div>'
            + '         ' + linkRow
            + '         ' + delReqRow
            + '    <div class="zrcpt-w-delreq-form" data-role="delreq-form" style="display:none;"></div>'
            + '  </div>'
            + '  <div class="zrcpt-w-item-actions">' + action + delBtn + '</div>'
            + '</div>';
        });

        recentEl.innerHTML = html;

        // Wire the per-row action buttons to open the approve/send modal.
        recentEl.querySelectorAll('.zrcpt-w-item-action[data-act="review"]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            openApproveModal(parseInt(btn.getAttribute('data-id'), 10));
          });
        });

        // Wire the per-row copy-link buttons.
        recentEl.querySelectorAll('.zrcpt-w-item-copy').forEach(function (btn) {
          btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            copyTextToClipboard(btn.getAttribute('data-copy'), btn);
          });
        });

        // v3.9.0 — deletion controls.
        recentEl.querySelectorAll('.zrcpt-w-item-del').forEach(function (btn) {
          btn.addEventListener('click', function () {
            adminDeleteReceipt(parseInt(btn.getAttribute('data-id'), 10), btn);
          });
        });
        recentEl.querySelectorAll('.zrcpt-w-item-reqdel').forEach(function (btn) {
          btn.addEventListener('click', function () {
            openDeletionRequestForm(parseInt(btn.getAttribute('data-id'), 10));
          });
        });
        recentEl.querySelectorAll('.zrcpt-w-delreq-cancel').forEach(function (btn) {
          btn.addEventListener('click', function () {
            cancelDeletionRequest(parseInt(btn.getAttribute('data-id'), 10), btn);
          });
        });
      })
      .catch(function () {
        var recentEl = $('zrcpt-w-recent');
        if (recentEl) {
          recentEl.innerHTML = '<div class="zrcpt-w-empty">Could not load receipts.</div>';
        }
      });
  }

  /* ==================================================================
   * v3.9.0 — DELETION (History list)
   * Admins delete directly (server moves the receipt to Trash — its customer
   * link dies instantly, and it stays restorable from wp-admin → Manage
   * Receipts). Everyone else files a deletion REQUEST with a required reason;
   * the admin gets an email + a pending queue in wp-admin and approves or
   * declines. Requester (or an admin) can cancel a pending request.
   * ================================================================== */

  function historyItemEl(postId) {
    return document.querySelector('.zrcpt-w-item[data-item-id="' + postId + '"]');
  }

  // Small inline status line inside a History row (replaces alert()s).
  function setItemNote(itemEl, text, kind) {
    if (!itemEl) return;
    var note = itemEl.querySelector('[data-role="row-note"]');
    if (!note) {
      note = document.createElement('div');
      note.setAttribute('data-role', 'row-note');
      note.className = 'zrcpt-w-item-note';
      var info = itemEl.querySelector('.zrcpt-w-item-info');
      if (info) info.appendChild(note);
    }
    note.className = 'zrcpt-w-item-note' + (kind ? ' zrcpt-w-item-note-' + kind : '');
    note.textContent = text || '';
    note.style.display = text ? '' : 'none';
  }

  function adminDeleteReceipt(postId, btn) {
    if (!postId) return;
    if (!window.confirm('Delete this receipt?\n\nIts customer link stops working immediately. It moves to Trash and can be restored from wp-admin → Manage Receipts.')) {
      return;
    }
    if (btn) { btn.disabled = true; btn.textContent = 'Deleting…'; }
    var fd = new FormData();
    fd.append('action', 'zrcpt_admin_delete_receipt');
    fd.append('nonce', zrcptWidgetData.nonce);
    fd.append('post_id', postId);
    fetch(zrcptWidgetData.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.success) {
          loadHistory(); // the trashed receipt drops out of the list
        } else {
          if (btn) { btn.disabled = false; btn.textContent = 'Delete'; }
          setItemNote(historyItemEl(postId), (res && res.data && res.data.message) || 'Could not delete the receipt.', 'err');
        }
      })
      .catch(function () {
        if (btn) { btn.disabled = false; btn.textContent = 'Delete'; }
        setItemNote(historyItemEl(postId), 'Network error — the receipt was NOT deleted.', 'err');
      });
  }

  function openDeletionRequestForm(postId) {
    var itemEl = historyItemEl(postId);
    if (!itemEl) return;
    var wrap = itemEl.querySelector('[data-role="delreq-form"]');
    if (!wrap) return;
    if (wrap.style.display !== 'none') { wrap.style.display = 'none'; wrap.innerHTML = ''; return; }

    wrap.innerHTML =
        '<div class="zrcpt-w-delreq-form-title">Ask the admin to delete this receipt</div>'
      + '<textarea class="zrcpt-w-delreq-reason" rows="3" maxlength="500" placeholder="Why should this receipt be deleted? (required — the admin sees this)"></textarea>'
      + '<div class="zrcpt-w-delreq-form-btns">'
      + '  <button type="button" class="zrcpt-w-delreq-send">Send request to admin</button>'
      + '  <button type="button" class="zrcpt-w-delreq-dismiss">Never mind</button>'
      + '</div>';
    wrap.style.display = '';

    var ta = wrap.querySelector('.zrcpt-w-delreq-reason');
    if (ta) setTimeout(function () { ta.focus(); }, 40);

    wrap.querySelector('.zrcpt-w-delreq-dismiss').addEventListener('click', function () {
      wrap.style.display = 'none';
      wrap.innerHTML = '';
    });
    wrap.querySelector('.zrcpt-w-delreq-send').addEventListener('click', function () {
      var reason = ta ? ta.value.replace(/^\s+|\s+$/g, '') : '';
      if (reason.length < 5) {
        setItemNote(itemEl, 'Please give a short reason (at least 5 characters) — the admin needs it to decide.', 'err');
        if (ta) ta.focus();
        return;
      }
      var sendBtn = wrap.querySelector('.zrcpt-w-delreq-send');
      if (sendBtn) { sendBtn.disabled = true; sendBtn.textContent = 'Sending…'; }
      var fd = new FormData();
      fd.append('action', 'zrcpt_request_deletion');
      fd.append('nonce', zrcptWidgetData.nonce);
      fd.append('post_id', postId);
      fd.append('reason', reason);
      fetch(zrcptWidgetData.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res && res.success) {
            loadHistory(); // row re-renders with the "Deletion requested" state
          } else {
            if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = 'Send request to admin'; }
            setItemNote(itemEl, (res && res.data && res.data.message) || 'Could not send the request.', 'err');
          }
        })
        .catch(function () {
          if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = 'Send request to admin'; }
          setItemNote(itemEl, 'Network error — the request was NOT sent.', 'err');
        });
    });
  }

  function cancelDeletionRequest(postId, btn) {
    if (!postId) return;
    if (btn) { btn.disabled = true; btn.textContent = 'Cancelling…'; }
    var fd = new FormData();
    fd.append('action', 'zrcpt_cancel_delete_request');
    fd.append('nonce', zrcptWidgetData.nonce);
    fd.append('post_id', postId);
    fetch(zrcptWidgetData.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.success) {
          loadHistory();
        } else {
          if (btn) { btn.disabled = false; btn.textContent = 'Cancel request'; }
          setItemNote(historyItemEl(postId), (res && res.data && res.data.message) || 'Could not cancel the request.', 'err');
        }
      })
      .catch(function () {
        if (btn) { btn.disabled = false; btn.textContent = 'Cancel request'; }
        setItemNote(historyItemEl(postId), 'Network error — the request still stands.', 'err');
      });
  }

  /* ==================================================================
   * v3.6.0 — APPROVE & SEND MODAL
   * The receipt maker reviews the generated receipt (must scroll through the
   * whole thing) and ticks an explicit checkbox before Approve enables. That
   * is their sign-off. Sending the receipt to the customer is a separate,
   * deliberate action that only unlocks after approval. The approval is bound
   * to the exact receipt HTML (via a SHA-256 hash) so a later regenerate forces
   * a fresh review.
   * ================================================================== */

  var approveState = {
    postId: null,
    htmlHash: null,      // sha256 of the previewed receipt HTML
    scrolledToEnd: false,
    approved: false,
    canSend: false,
    busy: false,
    photos: [],          // v3.6.4 — receipt photo URLs (for the remove grid)
    removeBusy: false,   // v3.6.4 — a removal request is in flight
    reorderBusy: false   // v3.6.6 — a reorder request is in flight
  };

  // Compute a SHA-256 hex digest in the browser. Used to bind the approval to
  // the exact HTML the approver reviewed. Falls back gracefully if SubtleCrypto
  // is unavailable (older/non-secure contexts) — in that case we send an empty
  // hash and the server keeps its own hash; approval still records, and the
  // server's stale-content check still protects sending.
  function sha256Hex(str) {
    try {
      if (window.crypto && window.crypto.subtle && window.TextEncoder) {
        var data = new TextEncoder().encode(str);
        return window.crypto.subtle.digest('SHA-256', data).then(function (buf) {
          var bytes = new Uint8Array(buf);
          var hex = '';
          for (var i = 0; i < bytes.length; i++) {
            hex += bytes[i].toString(16).padStart(2, '0');
          }
          return hex;
        });
      }
    } catch (e) { /* fall through */ }
    return Promise.resolve('');
  }

  function modalEl(id) { return document.getElementById(id); }

  function setModalMsg(text, kind) {
    var el = modalEl('zrcpt-w-modal-msg');
    if (!el) return;
    if (!text) { el.style.display = 'none'; el.textContent = ''; return; }
    el.textContent = text;
    el.className = 'zrcpt-w-modal-msg' + (kind ? ' zrcpt-w-modal-msg-' + kind : '');
    el.style.display = '';
  }

  /* ==================================================================
   * v3.6.5 — REMOVE PHOTOS directly on the receipt preview
   * The preview iframe is same-origin, so once it lays out we inject a corner
   * × onto each photo in the receipt's OWN gallery (.gallery-item). Tapping ×
   * opens a CENTERED "Delete this photo?" dialog (never clipped). Confirming
   * removes that photo server-side; we then reload the preview with the updated
   * receipt, re-inject the ×s, and re-arm the approval gate so the reviewer
   * approves the updated receipt normally. One unified view — no side gallery.
   * ================================================================== */

  // The photo URL currently queued for deletion (the centered dialog acts on it).
  var pendingRemoveUrl = null;

  // Inject a removal × onto every gallery photo inside the same-origin preview
  // iframe document. Safe to call repeatedly (it clears prior injections first).
  // Skipped entirely once the receipt is approved (content is locked).
  function injectPhotoRemovers(doc) {
    if (!doc || !doc.body) return;

    // v3.9.2 — the receipt's own FULL-SCREEN LIGHTBOX must never open inside
    // this small preview. Its inline script fills the page with a black
    // overlay whose close control lays out off-screen in the iframe — tapping
    // a photo (or a grip, without dragging) turned the whole review black and
    // "locked" it. Neutralize every path to it: no-op the global open/close
    // functions, strip lightbox onclick attributes, and hide any lightbox
    // container outright. (Runs even when approved — viewing must stay safe.)
    try {
      var w = doc.defaultView;
      if (w) {
        w.openLightbox = function () {};
        w.closeLightbox = function () {};
      }
    } catch (e) { /* no-op */ }
    try {
      doc.querySelectorAll('[onclick]').forEach(function (el) {
        var oc = el.getAttribute('onclick') || '';
        if (/lightbox/i.test(oc)) { el.removeAttribute('onclick'); }
      });
    } catch (e) { /* no-op */ }

    if (approveState.approved) return;  // locked: no removal UI

    // Inject a tiny style once so the × reads clearly over any photo.
    if (!doc.getElementById('zrcpt-rm-style')) {
      var st = doc.createElement('style');
      st.id = 'zrcpt-rm-style';
      st.textContent =
        // v3.9.2 — belt-and-braces: whatever markup variant the bot used for
        // its lightbox, it never displays inside the preview.
        '#lightbox,.lightbox,[id*="lightbox" i],[class*="lightbox" i]{display:none !important;}' +
        '.gallery-item{position:relative;cursor:grab;-webkit-user-select:none;user-select:none;' +
        'touch-action:none;transition:opacity .15s ease,transform .15s ease,box-shadow .15s ease;}' +
        '.gallery-item img{pointer-events:none;-webkit-user-drag:none;user-drag:none;}' +
        '.zrcpt-rm-x{position:absolute;top:6px;right:6px;width:30px;height:30px;' +
        'display:flex;align-items:center;justify-content:center;padding:0;margin:0;' +
        'font:700 19px/1 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;' +
        'color:#fff;background:rgba(15,23,42,.62);border:1.5px solid rgba(255,255,255,.9);' +
        'border-radius:9999px;cursor:pointer;z-index:6;-webkit-tap-highlight-color:transparent;' +
        'transition:background .15s ease,transform .15s ease;}' +
        '.zrcpt-rm-x:hover{background:#DC2626;transform:scale(1.08);}' +
        // v3.9.2 — subtler: it's a HINT that photos drag, not a button (tapping
        // it did nothing but LOOKED tappable, which read as broken).
        '.zrcpt-drag-badge{position:absolute;top:6px;left:6px;width:20px;height:20px;' +
        'display:flex;align-items:center;justify-content:center;padding:0;z-index:5;' +
        'color:rgba(255,255,255,.85);background:rgba(15,23,42,.32);border-radius:9999px;pointer-events:none;' +
        'font:600 10px/1 -apple-system,BlinkMacSystemFont,sans-serif;}' +
        '.gallery-item.zrcpt-dragging{opacity:.45;transform:scale(.96);cursor:grabbing;' +
        'box-shadow:0 8px 24px rgba(0,0,0,.35);}' +
        '.gallery-item.zrcpt-drop-before{box-shadow:-4px 0 0 0 #e87722;}' +
        '.gallery-item.zrcpt-drop-after{box-shadow:4px 0 0 0 #e87722;}' +
        '.gallery-item.zrcpt-rm-busy{opacity:.5;pointer-events:none;}';
      (doc.head || doc.body).appendChild(st);
    }

    var items = doc.querySelectorAll('.gallery-item');
    items.forEach(function (item) {
      // Don't double-inject.
      if (item.querySelector('.zrcpt-rm-x')) return;
      var img = item.querySelector('img');
      var url = img ? (img.getAttribute('src') || '') : '';
      if (!url) return;

      var btn = doc.createElement('button');
      btn.type = 'button';
      btn.className = 'zrcpt-rm-x';
      btn.setAttribute('aria-label', 'Remove this photo');
      btn.setAttribute('title', 'Remove this photo');
      btn.textContent = '×';
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (approveState.removeBusy) return;
        openDeleteConfirm(url, img ? img.getAttribute('src') : '');
      });
      item.appendChild(btn);

      // v3.6.6 — a small drag affordance (grip) so it's obvious photos can be
      // reordered. The whole tile is the drag handle (pointer-based below).
      var grip = doc.createElement('span');
      grip.className = 'zrcpt-drag-badge';
      grip.setAttribute('aria-hidden', 'true');
      grip.textContent = '\u2630'; // trigram/hamburger = drag grip
      item.appendChild(grip);
    });

    // Wire pointer-based drag-to-reorder across all gallery items (works for
    // mouse AND touch; HTML5 DnD is unreliable on touch + inside an iframe).
    wirePhotoReorder(doc);
  }

  // Re-inject into whatever the preview iframe currently shows.
  function injectPhotoRemoversIntoPreview() {
    var frame = modalEl('zrcpt-w-preview-frame');
    if (!frame) return;
    var doc = null;
    try { doc = frame.contentDocument || (frame.contentWindow && frame.contentWindow.document); } catch (e) {}
    if (doc) injectPhotoRemovers(doc);
  }

  /* ------------------------------------------------------------------
   * v3.6.6 — Drag-to-reorder photos (pointer-based; mouse + touch).
   * Each .gallery-item in the preview is a drag handle. While dragging we show
   * a drop indicator on the item under the pointer and, on release, move the
   * dragged item there, recompute the URL order, and auto-save it.
   * ------------------------------------------------------------------ */
  function wirePhotoReorder(doc) {
    if (approveState.approved) return;              // locked once approved
    var gallery = doc.querySelector('.gallery');
    if (!gallery || gallery.getAttribute('data-zrcpt-reorder') === '1') return;
    gallery.setAttribute('data-zrcpt-reorder', '1'); // wire once per render

    var dragEl = null, targetEl = null, dropAfter = false;
    var startX = 0, startY = 0, dragging = false, pointerId = null;
    var DRAG_THRESHOLD = 6; // px before a press becomes a drag (so taps/clicks pass through)

    function clearDropMarks() {
      doc.querySelectorAll('.zrcpt-drop-before,.zrcpt-drop-after').forEach(function (el) {
        el.classList.remove('zrcpt-drop-before', 'zrcpt-drop-after');
      });
    }

    function cellFromPoint(x, y) {
      var el = doc.elementFromPoint(x, y);
      return el ? el.closest('.gallery-item') : null;
    }

    function onPointerDown(e) {
      // Only primary button / single touch; ignore the × button.
      if (e.button != null && e.button !== 0) return;
      if (e.target && e.target.closest && e.target.closest('.zrcpt-rm-x')) return;
      if (approveState.removeBusy || approveState.reorderBusy) return;
      var cell = e.target && e.target.closest ? e.target.closest('.gallery-item') : null;
      if (!cell) return;
      dragEl = cell;
      pointerId = e.pointerId;
      startX = e.clientX; startY = e.clientY;
      dragging = false;
      // Don't preventDefault yet — let a plain click (×) still work.
    }

    function onPointerMove(e) {
      if (!dragEl || e.pointerId !== pointerId) return;
      if (!dragging) {
        if (Math.hypot(e.clientX - startX, e.clientY - startY) < DRAG_THRESHOLD) return;
        // Threshold crossed → begin dragging.
        dragging = true;
        dragEl.classList.add('zrcpt-dragging');
        try { gallery.setPointerCapture(pointerId); } catch (err) {}
      }
      e.preventDefault();
      var over = cellFromPoint(e.clientX, e.clientY);
      clearDropMarks();
      if (over && over !== dragEl) {
        var r = over.getBoundingClientRect();
        // Horizontal grid: decide left/right half. (Works for wrapped rows too.)
        dropAfter = (e.clientX - r.left) > r.width / 2;
        over.classList.add(dropAfter ? 'zrcpt-drop-after' : 'zrcpt-drop-before');
        targetEl = over;
      } else {
        targetEl = null;
      }
    }

    function finishDrag(commit) {
      if (dragEl) dragEl.classList.remove('zrcpt-dragging');
      clearDropMarks();
      try { gallery.releasePointerCapture(pointerId); } catch (err) {}

      if (commit && dragging && dragEl && targetEl && targetEl !== dragEl) {
        // Move dragEl to the chosen side of targetEl.
        if (dropAfter) {
          targetEl.parentNode.insertBefore(dragEl, targetEl.nextSibling);
        } else {
          targetEl.parentNode.insertBefore(dragEl, targetEl);
        }
        // Compute the new URL order from the DOM and persist it.
        var order = [];
        gallery.querySelectorAll('.gallery-item img').forEach(function (img) {
          var u = img.getAttribute('src');
          if (u) order.push(u);
        });
        submitPhotoReorder(order);
      }
      dragEl = null; targetEl = null; dropAfter = false; dragging = false; pointerId = null;
    }

    function onPointerUp(e) {
      if (!dragEl || e.pointerId !== pointerId) return;
      finishDrag(true);
    }
    function onPointerCancel(e) {
      if (!dragEl || e.pointerId !== pointerId) return;
      finishDrag(false);
    }

    gallery.addEventListener('pointerdown', onPointerDown);
    gallery.addEventListener('pointermove', onPointerMove);
    gallery.addEventListener('pointerup', onPointerUp);
    gallery.addEventListener('pointercancel', onPointerCancel);
  }

  // Persist a new photo order to the receipt, then refresh the preview (and
  // re-arm the approval gate, since the content changed). Auto-saves on drop.
  function submitPhotoReorder(order) {
    if (!approveState.postId || approveState.reorderBusy) return;
    if (!order || !order.length) return;
    approveState.reorderBusy = true;
    setModalMsg('Saving photo order…', '');

    var fd = new FormData();
    fd.append('action', 'zrcpt_reorder_photos');
    fd.append('nonce', zrcptWidgetData.nonce);
    fd.append('post_id', approveState.postId);
    fd.append('order', JSON.stringify(order));

    fetch(zrcptWidgetData.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        approveState.reorderBusy = false;
        if (!res || !res.success) {
          setModalMsg((res && res.data && res.data.message) || 'Could not save the new order.', 'err');
          // Reload to snap back to the true server order.
          if (approveState.postId) reopenPreviewFromServer();
          return;
        }
        var d = res.data;
        if (d.reordered === false) { setModalMsg('', ''); return; }
        setModalMsg('Photo order saved. Re-approve the updated receipt below.', 'ok');
        reloadApproveAfterEdit(d);
      })
      .catch(function () {
        approveState.reorderBusy = false;
        setModalMsg('Network error saving the new order.', 'err');
        if (approveState.postId) reopenPreviewFromServer();
      });
  }

  // Re-fetch the receipt detail and re-render the preview (used to recover the
  // true server state after a failed reorder).
  function reopenPreviewFromServer() {
    var pid = approveState.postId;
    if (!pid) return;
    var fd = new FormData();
    fd.append('action', 'zrcpt_receipt_detail');
    fd.append('nonce', zrcptWidgetData.nonce);
    fd.append('post_id', pid);
    fetch(zrcptWidgetData.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) { if (res && res.success) reloadApproveAfterEdit(res.data); })
      .catch(function () {});
  }


  // ----- Centered "Delete this photo?" dialog -----
  function openDeleteConfirm(url, thumbUrl) {
    pendingRemoveUrl = url;
    var dlg = modalEl('zrcpt-w-delconfirm');
    var thumb = modalEl('zrcpt-w-delconfirm-thumb');
    if (thumb) {
      thumb.innerHTML = thumbUrl ? '<img src="' + esc(thumbUrl) + '" alt="" />' : '';
    }
    if (dlg) dlg.style.display = '';
  }

  function closeDeleteConfirm() {
    pendingRemoveUrl = null;
    var dlg = modalEl('zrcpt-w-delconfirm');
    if (dlg) dlg.style.display = 'none';
  }

  function confirmDeletePhoto() {
    if (!pendingRemoveUrl) { closeDeleteConfirm(); return; }
    var url = pendingRemoveUrl;
    closeDeleteConfirm();
    submitPhotoRemoval([url]);
  }

  // Remove the given photo URL(s) from the receipt, then reload the modal's
  // receipt view so the reviewer sees (and approves) the updated version.
  function submitPhotoRemoval(urls) {
    if (!approveState.postId || approveState.removeBusy) return;
    if (!urls || !urls.length) return;
    approveState.removeBusy = true;

    // Visually mark the matching photo(s) in the preview as busy.
    var frame = modalEl('zrcpt-w-preview-frame');
    try {
      var doc = frame && (frame.contentDocument || (frame.contentWindow && frame.contentWindow.document));
      if (doc) {
        doc.querySelectorAll('.gallery-item img').forEach(function (img) {
          if (urls.indexOf(img.getAttribute('src')) !== -1) {
            var cell = img.closest('.gallery-item');
            if (cell) cell.classList.add('zrcpt-rm-busy');
          }
        });
      }
    } catch (e) {}

    setModalMsg('Removing photo…', '');

    var fd = new FormData();
    fd.append('action', 'zrcpt_remove_photos');
    fd.append('nonce', zrcptWidgetData.nonce);
    fd.append('post_id', approveState.postId);
    fd.append('urls', JSON.stringify(urls));

    fetch(zrcptWidgetData.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        approveState.removeBusy = false;
        if (!res || !res.success) {
          injectPhotoRemoversIntoPreview(); // clears busy state via re-render path
          setModalMsg((res && res.data && res.data.message) || 'Could not remove the photo.', 'err');
          return;
        }
        var d = res.data;
        setModalMsg((d.removed_count === 1 ? 'Photo removed.' : (d.removed_count + ' photos removed.'))
          + ' Re-approve the updated receipt below.', 'ok');
        reloadApproveAfterEdit(d);
      })
      .catch(function () {
        approveState.removeBusy = false;
        setModalMsg('Network error removing the photo.', 'err');
      });
  }

  // Re-render the modal's receipt preview from a fresh detail payload (after a
  // removal), and reset the approval gate to "needs review".
  function reloadApproveAfterEdit(d) {
    approveState.approved = false;
    approveState.canSend = !!(d.state && d.state.can_send);
    approveState.scrolledToEnd = false;
    approveState.photos = (d.photos || []).slice();

    // Reset consent + buttons to the un-approved baseline.
    var chk = modalEl('zrcpt-w-approve-check');
    if (chk) { chk.checked = false; chk.disabled = true; }
    var approveBtn = modalEl('zrcpt-w-approve-btn');
    if (approveBtn) { approveBtn.disabled = true; approveBtn.style.display = ''; }
    var sendBtn = modalEl('zrcpt-w-send-btn');
    if (sendBtn) { sendBtn.style.display = 'none'; }
    var banner = modalEl('zrcpt-w-approved-banner');
    if (banner) { banner.style.display = 'none'; banner.textContent = ''; }
    var instruct = modalEl('zrcpt-w-modal-instruct');
    if (instruct) instruct.style.display = '';
    var checkWrap = modalEl('zrcpt-w-approve-check-wrap');
    if (checkWrap) { checkWrap.style.display = ''; checkWrap.classList.remove('zrcpt-w-approve-check-ready'); }

    // Re-render the preview with the new HTML + re-hash it. armScrollGate's
    // onFrameLoad re-injects the ×s once the new doc lays out.
    var frame = modalEl('zrcpt-w-preview-frame');
    if (frame) {
      frame.removeAttribute('sandbox');     // same-origin: allow injection
      frame.style.height = '';
      frame.srcdoc = d.html || '';
      frame.style.display = '';
    }
    sha256Hex(d.html || '').then(function (hex) { approveState.htmlHash = hex; });

    armScrollGate();
  }


  function openApproveModal(postId) {
    if (!postId) return;
    approveState = { postId: postId, htmlHash: null, scrolledToEnd: false, approved: false, canSend: false, busy: false, photos: [], removeBusy: false, reorderBusy: false };

    var modal = modalEl('zrcpt-w-approve-modal');
    if (!modal) return;

    // Reset UI to the "reviewing" baseline.
    var chk = modalEl('zrcpt-w-approve-check');
    if (chk) { chk.checked = false; chk.disabled = true; }
    var approveBtn = modalEl('zrcpt-w-approve-btn');
    if (approveBtn) { approveBtn.disabled = true; approveBtn.style.display = ''; }
    var sendBtn = modalEl('zrcpt-w-send-btn');
    if (sendBtn) { sendBtn.style.display = 'none'; sendBtn.disabled = false; }
    var banner = modalEl('zrcpt-w-approved-banner');
    if (banner) { banner.style.display = 'none'; banner.textContent = ''; }
    var hint = modalEl('zrcpt-w-scroll-hint');
    if (hint) hint.style.display = '';
    var instruct = modalEl('zrcpt-w-modal-instruct');
    if (instruct) instruct.style.display = '';
    var checkWrap = modalEl('zrcpt-w-approve-check-wrap');
    if (checkWrap) checkWrap.classList.remove('zrcpt-w-approve-check-ready');
    setModalMsg('');

    var frame = modalEl('zrcpt-w-preview-frame');
    if (frame) { frame.style.display = 'none'; frame.removeAttribute('srcdoc'); frame.style.height = ''; }
    var loading = modalEl('zrcpt-w-preview-loading');
    if (loading) { loading.style.display = ''; loading.textContent = 'Loading receipt…'; }

    // v3.6.5 — ensure the centered delete-photo dialog starts hidden.
    var dc0 = modalEl('zrcpt-w-delconfirm');
    if (dc0) dc0.style.display = 'none';
    pendingRemoveUrl = null;

    var sc0 = modalEl('zrcpt-w-sendconfirm');
    if (sc0) sc0.style.display = 'none';
    modal.style.display = '';
    document.body.classList.add('zrcpt-w-modal-open');

    // Fetch the receipt HTML + current state.
    var fd = new FormData();
    fd.append('action', 'zrcpt_receipt_detail');
    fd.append('nonce', zrcptWidgetData.nonce);
    fd.append('post_id', postId);

    fetch(zrcptWidgetData.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.success) {
          if (loading) loading.textContent = (res && res.data && res.data.message) || 'Could not load this receipt.';
          return;
        }
        var d = res.data;
        var sub = modalEl('zrcpt-w-modal-sub');
        if (sub) sub.textContent = (d.address || d.title || 'Receipt') + (d.install_date ? ' · ' + d.install_date : '');

        // v3.6.5 — Render the receipt into a SAME-ORIGIN iframe via srcdoc (no
        // sandbox) so the parent can inject the per-photo × removal controls into
        // the receipt's own gallery. The stored receipt HTML is our own
        // first-party content (authored by our generator), and its inline
        // <script> only powers the lightbox; this is the same trust level as the
        // public receipt page the customer already views.
        if (frame) {
          frame.removeAttribute('sandbox');
          frame.srcdoc = d.html || '<p style="font-family:sans-serif;padding:24px;">This receipt has no content.</p>';
          frame.style.display = '';
        }
        if (loading) loading.style.display = 'none';

        // Hash the exact HTML we displayed so approval binds to it.
        sha256Hex(d.html || '').then(function (hex) { approveState.htmlHash = hex; });

        // v3.6.5 — photos are removed directly on the receipt preview (a corner ×
        // is injected onto each gallery photo once the iframe lays out; see
        // armScrollGate -> injectPhotoRemovers). Just remember the list + whether
        // removal is allowed (not once approved).
        approveState.photos = (d.photos || []).slice();

        // Reflect existing state.
        var st = d.state || {};
        approveState.approved = !!st.approved;
        approveState.canSend = !!st.can_send;

        if (st.approved) {
          // Already approved — show the audit line, skip the gate, reveal Send.
          showApprovedState(st);
        } else {
          // Needs review: arm the scroll gate on the preview.
          armScrollGate();
        }
      })
      .catch(function () {
        if (loading) loading.textContent = 'Network error loading the receipt.';
      });
  }

  // Replace a button's text label while preserving its leading SVG icon.
  function setSendBtnLabel(btn, label) {
    var icon = btn.querySelector('svg');
    btn.textContent = '';
    if (icon) btn.appendChild(icon);
    btn.appendChild(document.createTextNode(' ' + label));
  }

  // Show the "already approved" layout: audit banner + Send button (no gate).
  function showApprovedState(st) {
    var instruct = modalEl('zrcpt-w-modal-instruct');
    if (instruct) instruct.style.display = 'none';
    var hint = modalEl('zrcpt-w-scroll-hint');
    if (hint) hint.style.display = 'none';
    var checkWrap = modalEl('zrcpt-w-approve-check-wrap');
    if (checkWrap) checkWrap.style.display = 'none';
    var approveBtn = modalEl('zrcpt-w-approve-btn');
    if (approveBtn) approveBtn.style.display = 'none';

    var banner = modalEl('zrcpt-w-approved-banner');
    if (banner) {
      var line = '✓ Approved';
      if (st.approved_by) line += ' by ' + st.approved_by;
      if (st.approved_at) line += ' on ' + st.approved_at;
      if (st.sent) {
        line += '  ·  ✉ Sent' + (st.sent_at ? ' ' + st.sent_at : '');
      }
      banner.textContent = line;
      banner.style.display = '';
    }

    var sendBtn = modalEl('zrcpt-w-send-btn');
    if (sendBtn) {
      sendBtn.style.display = '';
      sendBtn.disabled = false;
      // Make the label clear when it's a re-send, keeping the icon intact.
      setSendBtnLabel(sendBtn, st.sent ? 'Re-send via email' : 'Send via email');
    }
  }

  // Module-level handle so we can detach the previous gate's listeners every
  // time we open a new receipt (prevents stale listeners AND, more importantly,
  // prevents a previous receipt's "already satisfied" state from leaking in).
  var scrollGateCleanup = null;

  // Arm the scroll-to-end gate. THE SCROLLER IS THE OUTER .zrcpt-w-preview
  // CONTAINER (the iframe is sized to its own content, so the container is what
  // overflows and scrolls). We enable the confirmation checkbox only once the
  // user has actually scrolled that container to its bottom. We re-arm fresh on
  // every open. This is the fix for the bug where every receipt after the first
  // could be approved without scrolling.
  function armScrollGate() {
    var preview = modalEl('zrcpt-w-preview');
    var frame = modalEl('zrcpt-w-preview-frame');
    if (!preview || !frame) return;

    // Tear down any previous gate first.
    if (typeof scrollGateCleanup === 'function') { scrollGateCleanup(); scrollGateCleanup = null; }

    // Reset the gate state for this receipt and make sure the container starts
    // at the top so a leftover scroll position can't count as "at the bottom".
    approveState.scrolledToEnd = false;
    try { preview.scrollTop = 0; } catch (e) {}

    function markScrolledToEnd() {
      if (approveState.scrolledToEnd) return;
      approveState.scrolledToEnd = true;
      var chk = modalEl('zrcpt-w-approve-check');
      if (chk) chk.disabled = false;
      var checkWrap = modalEl('zrcpt-w-approve-check-wrap');
      if (checkWrap) checkWrap.classList.add('zrcpt-w-approve-check-ready');
      var hint = modalEl('zrcpt-w-scroll-hint');
      if (hint) hint.style.display = 'none';
      var endMark = modalEl('zrcpt-w-preview-end');
      if (endMark) endMark.classList.add('zrcpt-w-preview-end-reached');
    }

    function atBottom() {
      // True when the container is scrolled within 24px of its own bottom.
      return preview.scrollTop + preview.clientHeight >= preview.scrollHeight - 24;
    }

    function onScroll() { if (atBottom()) markScrolledToEnd(); }

    preview.addEventListener('scroll', onScroll, { passive: true });

    // Size the iframe to its content height (so the OUTER container scrolls),
    // then decide whether there's anything to scroll. We re-measure after the
    // iframe's document lays out, and a couple of times after, because receipt
    // images can change the height as they load.
    // Returns the measured content height in px, or 0 if the content isn't laid
    // out yet (no doc / empty body). Only a NON-ZERO return means we have a real
    // measurement we can trust for the short-content decision.
    function measureContentHeight() {
      try {
        var doc = frame.contentDocument || (frame.contentWindow && frame.contentWindow.document);
        if (!doc || !doc.body) return 0;
        var body = doc.body, html = doc.documentElement;
        var h = Math.max(
          body.scrollHeight || 0,
          html ? html.scrollHeight : 0,
          body.offsetHeight || 0,
          html ? html.offsetHeight : 0
        );
        return h > 0 ? h : 0;
      } catch (e) { /* same-origin srcdoc — shouldn't throw */ }
      return 0;
    }

    function evaluateGate() {
      var contentH = measureContentHeight();
      // Until we have a real measurement, do NOTHING. This is the guard that
      // stops an early tick (before srcdoc lays out, iframe still at its empty
      // CSS height) from wrongly concluding "short → auto-pass".
      if (contentH <= 0) return;

      // Size the OUTER container's scroller to the real content height.
      frame.style.height = contentH + 'px';

      // v3.6.5 — (re)inject the × controls; images may have just laid out, and
      // re-running is a no-op for photos that already have their ×.
      injectPhotoRemoversIntoPreview();

      // Now decide using the container's real overflow.
      var visible = preview.clientHeight;
      if (contentH <= visible + 8) {
        // Content genuinely fits — nothing to scroll, so the gate is satisfied.
        markScrolledToEnd();
      } else if (atBottom()) {
        markScrolledToEnd();
      }
      // Otherwise: there IS more to read → gate stays locked until the user
      // scrolls the container to the bottom (handled by onScroll).
    }

    var measureTimers = [];
    function scheduleMeasures() {
      [0, 120, 350, 800].forEach(function (ms) {
        measureTimers.push(setTimeout(function () {
          evaluateGate();
        }, ms));
      });
    }

    function onFrameLoad() {
      // Couldn't access the doc at all → don't trap the user (rare; same-origin
      // so this normally works).
      var doc = null;
      try { doc = frame.contentDocument || (frame.contentWindow && frame.contentWindow.document); } catch (e) {}
      if (!doc) { markScrolledToEnd(); return; }
      // v3.6.5 — inject the per-photo × controls into the receipt's own gallery.
      injectPhotoRemovers(doc);
      scheduleMeasures();
    }

    frame.addEventListener('load', onFrameLoad);

    // In case the iframe content is already present (load fired before we
    // attached), kick a measurement now too.
    scheduleMeasures();

    // Expose teardown.
    scrollGateCleanup = function () {
      preview.removeEventListener('scroll', onScroll);
      frame.removeEventListener('load', onFrameLoad);
      measureTimers.forEach(function (t) { clearTimeout(t); });
      measureTimers = [];
    };
  }

  function closeApproveModal() {
    if (typeof scrollGateCleanup === 'function') { scrollGateCleanup(); scrollGateCleanup = null; }
    var modal = modalEl('zrcpt-w-approve-modal');
    if (modal) modal.style.display = 'none';
    document.body.classList.remove('zrcpt-w-modal-open');
    var checkWrap = modalEl('zrcpt-w-approve-check-wrap');
    if (checkWrap) checkWrap.style.display = '';
    // Reset the preview frame height so the next open measures cleanly.
    var frame = modalEl('zrcpt-w-preview-frame');
    if (frame) { frame.style.height = ''; }
  }

  function submitApproval() {
    if (approveState.busy) return;
    var chk = modalEl('zrcpt-w-approve-check');
    if (!chk || !chk.checked) {
      setModalMsg('Please tick the box to confirm you approve this receipt.', 'err');
      return;
    }
    if (!approveState.scrolledToEnd) {
      setModalMsg('Please scroll through the whole receipt first.', 'err');
      return;
    }
    // The approval must bind to the exact HTML we showed. If this browser
    // couldn't hash it (no SubtleCrypto — e.g. an insecure http context), don't
    // pretend; tell the user plainly instead of letting the server reject it
    // with a misleading "the receipt changed" message.
    if (!approveState.htmlHash) {
      setModalMsg('This browser can\'t approve receipts here. Please open the dashboard over a secure (https) connection and try again.', 'err');
      return;
    }
    approveState.busy = true;
    setModalMsg('');
    var approveBtn = modalEl('zrcpt-w-approve-btn');
    if (approveBtn) { approveBtn.disabled = true; }

    var fd = new FormData();
    fd.append('action', 'zrcpt_approve_receipt');
    fd.append('nonce', zrcptWidgetData.nonce);
    fd.append('post_id', approveState.postId);
    fd.append('confirm', '1');
    fd.append('html_hash', approveState.htmlHash || '');

    fetch(zrcptWidgetData.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        approveState.busy = false;
        if (!res || !res.success) {
          setModalMsg((res && res.data && res.data.message) || 'Could not approve. Please try again.', 'err');
          if (approveBtn) approveBtn.disabled = false;
          return;
        }
        approveState.approved = true;
        setModalMsg(res.data.message || 'Receipt approved.', 'ok');
        showApprovedState(res.data.state || { approved: true });
        loadHistory(); // refresh badges behind the modal
      })
      .catch(function () {
        approveState.busy = false;
        setModalMsg('Network error. Please try again.', 'err');
        if (approveBtn) approveBtn.disabled = false;
      });
  }

  function openSendConfirm() {
    if (approveState.busy) return;
    setModalMsg('');
    var cf = modalEl('zrcpt-w-sendconfirm');
    if (cf) cf.style.display = '';
    // Focus the cancel button so an accidental Enter doesn't fire the send.
    var cancel = modalEl('zrcpt-w-sendconfirm-cancel');
    if (cancel) setTimeout(function () { cancel.focus(); }, 30);
  }

  function closeSendConfirm() {
    var cf = modalEl('zrcpt-w-sendconfirm');
    if (cf) cf.style.display = 'none';
  }

  function submitSend() {
    if (approveState.busy) return;
    approveState.busy = true;
    setModalMsg('');
    var sendBtn = modalEl('zrcpt-w-send-btn');
    if (sendBtn) sendBtn.disabled = true;

    var fd = new FormData();
    fd.append('action', 'zrcpt_send_receipt');
    fd.append('nonce', zrcptWidgetData.nonce);
    fd.append('post_id', approveState.postId);

    fetch(zrcptWidgetData.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        approveState.busy = false;
        if (!res || !res.success) {
          setModalMsg((res && res.data && res.data.message) || 'Could not send. Please try again.', 'err');
          if (sendBtn) sendBtn.disabled = false;
          return;
        }
        setModalMsg(res.data.message || 'Receipt sent to the customer.', 'ok');
        showApprovedState(res.data.state || { approved: true, sent: true });
        loadHistory();
        // Auto-close shortly after a successful send.
        setTimeout(function () { closeApproveModal(); }, 1400);
      })
      .catch(function () {
        approveState.busy = false;
        setModalMsg('Network error. Please try again.', 'err');
        if (sendBtn) sendBtn.disabled = false;
      });
  }

  function initApproveModal() {
    var chk = modalEl('zrcpt-w-approve-check');
    if (chk) {
      chk.addEventListener('change', function () {
        var approveBtn = modalEl('zrcpt-w-approve-btn');
        if (approveBtn) approveBtn.disabled = !(chk.checked && approveState.scrolledToEnd);
      });
    }
    var approveBtn = modalEl('zrcpt-w-approve-btn');
    if (approveBtn) approveBtn.addEventListener('click', submitApproval);

    // Clicking "Send via email" opens a confirm step rather than sending.
    var sendBtn = modalEl('zrcpt-w-send-btn');
    if (sendBtn) sendBtn.addEventListener('click', openSendConfirm);
    var sendYes = modalEl('zrcpt-w-sendconfirm-yes');
    if (sendYes) sendYes.addEventListener('click', function () { closeSendConfirm(); submitSend(); });
    var sendNo = modalEl('zrcpt-w-sendconfirm-cancel');
    if (sendNo) sendNo.addEventListener('click', closeSendConfirm);
    // v3.9.2 — the send confirm is a centered overlay now; its backdrop cancels.
    var sendBd = modalEl('zrcpt-w-sendconfirm-backdrop');
    if (sendBd) sendBd.addEventListener('click', closeSendConfirm);

    // v3.6.5 — centered "Delete this photo?" dialog controls.
    var delYes = modalEl('zrcpt-w-delconfirm-yes');
    if (delYes) delYes.addEventListener('click', confirmDeletePhoto);
    var delNo = modalEl('zrcpt-w-delconfirm-cancel');
    if (delNo) delNo.addEventListener('click', closeDeleteConfirm);
    var delBackdrop = modalEl('zrcpt-w-delconfirm-backdrop');
    if (delBackdrop) delBackdrop.addEventListener('click', closeDeleteConfirm);

    var closeBtn = modalEl('zrcpt-w-modal-close');
    if (closeBtn) closeBtn.addEventListener('click', closeApproveModal);
    var cancelBtn = modalEl('zrcpt-w-modal-cancel');
    if (cancelBtn) cancelBtn.addEventListener('click', closeApproveModal);
    var backdrop = modalEl('zrcpt-w-modal-backdrop');
    if (backdrop) backdrop.addEventListener('click', closeApproveModal);
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      var modal = modalEl('zrcpt-w-approve-modal');
      if (!modal || modal.style.display === 'none') return;
      // v3.6.5 — Escape closes the delete-photo dialog first (if open), so it
      // doesn't dismiss the whole review modal out from under the user.
      var dc = modalEl('zrcpt-w-delconfirm');
      if (dc && dc.style.display !== 'none') { closeDeleteConfirm(); return; }
      var sc = modalEl('zrcpt-w-sendconfirm');
      if (sc && sc.style.display !== 'none') { closeSendConfirm(); return; }
      closeApproveModal();
    });
  }

  /* ==================================================================
   * TAB SWITCHING
   * ================================================================== */

  function initTabs() {
    var widget = $('zrcpt-widget');
    if (!widget) return;

    var tabs = widget.querySelectorAll('.zrcpt-w-tab');
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        var target = tab.getAttribute('data-tab');

        tabs.forEach(function (t) { t.classList.remove('zrcpt-w-tab-active'); });
        tab.classList.add('zrcpt-w-tab-active');

        var inputPanel = $('zrcpt-w-tab-input');
        var historyPanel = $('zrcpt-w-tab-history');

        if (target === 'input') {
          show(inputPanel);
          hide(historyPanel);
        } else if (target === 'history') {
          hide(inputPanel);
          show(historyPanel);
          loadHistory();
        }
      });
    });
  }

  /* ==================================================================
   * INIT (HARDENED)
   * ================================================================== */

  function init() {
    if (initialized) return;
    initialized = true;

    // Tab switching
    initTabs();

    // v3.0.0: Smart Lookup
    initLookup();

    // Wire invoice dropzone
    wireDropzone(
      $('zrcpt-w-invoice-drop'),
      $('zrcpt-w-invoice-file'),
      handleInvoiceFiles
    );

    // Wire photo dropzone
    wireDropzone(
      $('zrcpt-w-photos-drop'),
      $('zrcpt-w-photos-file'),
      handlePhotoFiles
    );

    // Generate button
    var genBtn = $('zrcpt-w-generate');
    if (genBtn) {
      genBtn.addEventListener('click', function (e) {
        e.preventDefault();
        generateReceipt();
      });
    }

    // Retry button (error view)
    var retryBtn = $('zrcpt-w-retry');
    if (retryBtn) {
      retryBtn.addEventListener('click', function () {
        showInputView();
      });
    }

    // New Receipt button (success view)
    var newAfterBtn = $('zrcpt-w-new-after');
    if (newAfterBtn) {
      newAfterBtn.addEventListener('click', function () {
        resetForm();
      });
    }

    // v3.6.2 — the old "Need to upload photos instead?" toggle and its "Hide"
    // button are gone: the uploader is now the primary photo step, always
    // visible once a job is chosen (see loadPhotoSets), so there is nothing to
    // toggle. The invoice-file fallback (zrcpt-w-manual) is shown only on the
    // no-lookup path via openManual()/clearLookup().

    // v3.1.0 — if the user types a date themselves, stop auto-overwriting it
    // from the photo EXIF and drop the "from photo date" label.
    var dateInput = $('zrcpt-w-date');
    if (dateInput) {
      dateInput.addEventListener('input', function () {
        dateAutoFilled = false;
        var srcEl = $('zrcpt-w-date-source');
        if (srcEl) srcEl.textContent = '';
      });
    }

    // v3.1.0 — NOTE: we intentionally do NOT pre-fill today's date. The install
    // date should come from the chosen photo set's EXIF capture date; the field
    // stays blank until a set is picked (or the user types one).

    // v3.6.2 — the upload-path "these are the photos I want" confirm gate.
    var upConfirm = $('zrcpt-w-upload-confirm');
    if (upConfirm) {
      upConfirm.addEventListener('change', function () {
        uploadConfirmed = !!upConfirm.checked;
        updateGenerateState();
      });
    }

    // v3.6.0 — wire the approve/send modal controls.
    initApproveModal();

    // Load history stats on init
    loadHistory();
  }

  /* ==================================================================
   * THREE-TIER INITIALIZATION
   * ================================================================== */

  // 1. Check if already in DOM
  if ($('zrcpt-widget')) {
    init();
  } else {
    // 2. Wait for theme to inject widget HTML
    document.addEventListener('ts_widgets_rendered', init, { once: true });
    // 3. Fallback safety net
    document.addEventListener('DOMContentLoaded', function () {
      setTimeout(function () { if ($('zrcpt-widget')) init(); }, 500);
    });
  }

})();
