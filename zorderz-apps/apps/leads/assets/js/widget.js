/**
 * Zorderz Leads — Full-Parity Dashboard Widget JS
 * Module: Zorderz Leads
 *
 * Full-featured controller for the widget rendered on the Zorderz
 * SPA dashboard. Provides 100% parity with the backend WP Admin dashboard.
 * Permission-aware: reads zlWidgetData.features to gate UI elements.
 *
 * v1.5.0 — Full-parity widget with complete generation pipeline,
 *           batch history, lead cards, and hardened AJAX patterns.
 *
 * Hardening: Re-entry guards, boolean init gate, session lock pattern,
 *            custom modals (no confirm/alert/prompt), retry logic.
 *
 * Dependencies:
 *   - zlWidgetData (localized via wp_localize_script):
 *     { ajaxurl, nonce, version, features, salespeople, lookback, adminUrl,
 *       userRole, isSales }
 */
(function () {
    'use strict';

    /* ================================================================
     *  STATE
     * ================================================================ */
    var initDone       = false;
    var isRunning      = false;
    var currentBatchId = null;
    var pollTimerId    = null;  // v1.7.0 — background generation polling timer

    /* ================================================================
     *  HELPERS
     * ================================================================ */

    /**
     * getElementById shorthand.
     * @param {string} id
     * @returns {HTMLElement|null}
     */
    function $(id) {
        return document.getElementById(id);
    }

    /**
     * Basic XSS escape for untrusted strings before HTML insertion.
     * @param {*} str
     * @returns {string}
     */
    function escHtml(str) {
        if (str === null || str === undefined) {
            return '';
        }
        var s = String(str);
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return s.replace(/[&<>"']/g, function (c) {
            return map[c];
        });
    }

    /**
     * Relative date formatting.
     * Returns "Today", "Yesterday", "Xd ago", or "Mon D".
     * @param {string} dateStr
     * @returns {string}
     */
    function formatDate(dateStr) {
        if (!dateStr) {
            return '';
        }
        var d;
        try {
            d = new Date(dateStr);
            if (isNaN(d.getTime())) {
                return escHtml(dateStr);
            }
        } catch (e) {
            return escHtml(dateStr);
        }
        var now    = new Date();
        var today  = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var target = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        var diff   = Math.floor((today - target) / 86400000);

        if (diff === 0) { return 'Today'; }
        if (diff === 1) { return 'Yesterday'; }
        if (diff > 1 && diff <= 30) { return diff + 'd ago'; }

        var months = [
            'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
            'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'
        ];
        return months[d.getMonth()] + ' ' + d.getDate();
    }

    /**
     * Capitalize first letter.
     * @param {string} str
     * @returns {string}
     */
    function ucfirst(str) {
        if (!str) { return ''; }
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    /**
     * Permission check — returns true when the feature string is present
     * in zlWidgetData.features.
     * @param {string} feature
     * @returns {boolean}
     */
    function hasPerm(feature) {
        if (typeof zlWidgetData === 'undefined' || !zlWidgetData.features) {
            return false;
        }
        return zlWidgetData.features.indexOf(feature) !== -1;
    }

    /**
     * v1.7.0 — Check if the current user is in the sales view.
     * @returns {boolean}
     */
    function isSalesView() {
        return typeof zlWidgetData !== 'undefined' && zlWidgetData.isSales === true;
    }

    /**
     * Safely parse a JSON string. Returns null on failure.
     * @param {string} str
     * @returns {*}
     */
    function safeJSON(str) {
        if (!str) { return null; }
        try {
            return JSON.parse(str);
        } catch (e) {
            return null;
        }
    }

    /* ================================================================
     *  CUSTOM MODAL  (replaces confirm / alert / prompt)
     * ================================================================ */

    /**
     * Render a modal overlay inside the widget viewport.
     *
     * @param {Object}   opts
     * @param {string}   opts.title
     * @param {string}   opts.message
     * @param {boolean}  [opts.input=false]         Show a text input.
     * @param {string}   [opts.inputPlaceholder='']
     * @param {string}   [opts.confirmText='Confirm']
     * @param {string}   [opts.cancelText='Cancel']
     * @param {Function} [opts.onConfirm]           Receives inputValue when input is shown.
     * @param {Function} [opts.onCancel]
     */
    function showModal(opts) {
        opts = opts || {};
        var title            = opts.title || '';
        var message          = opts.message || '';
        var showInput        = !!opts.input;
        var inputPlaceholder = opts.inputPlaceholder || '';
        var confirmText      = opts.confirmText || 'Confirm';
        var cancelText       = opts.cancelText || 'Cancel';
        var onConfirm        = typeof opts.onConfirm === 'function' ? opts.onConfirm : function () {};
        var onCancel         = typeof opts.onCancel  === 'function' ? opts.onCancel  : function () {};

        // Tear down any previous modal
        removeModal();

        // Overlay
        var overlay = document.createElement('div');
        overlay.id = 'zl-modal-overlay';
        overlay.style.cssText =
            'position:fixed;top:0;left:0;width:100%;height:100%;' +
            'background:rgba(0,0,0,0.5);z-index:999999;display:flex;' +
            'align-items:center;justify-content:center;';

        // Dialog box
        var box = document.createElement('div');
        box.id = 'zl-modal-box';
        box.style.cssText =
            'background:#fff;border-radius:8px;padding:24px;max-width:420px;' +
            'width:90%;box-shadow:0 4px 24px rgba(0,0,0,0.2);' +
            'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;';

        var html = '';
        if (title) {
            html += '<h3 style="margin:0 0 12px;font-size:16px;color:#1d2327;">' +
                    escHtml(title) + '</h3>';
        }
        if (message) {
            html += '<p style="margin:0 0 16px;font-size:14px;color:#50575e;line-height:1.5;">' +
                    escHtml(message) + '</p>';
        }
        if (showInput) {
            html += '<input type="text" id="zl-modal-input" placeholder="' +
                    escHtml(inputPlaceholder) +
                    '" style="width:100%;padding:8px 10px;border:1px solid #c3c4c7;' +
                    'border-radius:4px;font-size:14px;margin-bottom:16px;box-sizing:border-box;" />';
        }
        html += '<div style="display:flex;justify-content:flex-end;gap:8px;">';
        html += '<button id="zl-modal-cancel" style="padding:6px 16px;border:1px solid #c3c4c7;' +
                'background:#f0f0f1;border-radius:4px;cursor:pointer;font-size:13px;color:#50575e;">' +
                escHtml(cancelText) + '</button>';
        html += '<button id="zl-modal-confirm" style="padding:6px 16px;border:none;' +
                'background:#2271b1;color:#fff;border-radius:4px;cursor:pointer;font-size:13px;">' +
                escHtml(confirmText) + '</button>';
        html += '</div>';

        box.innerHTML = html;
        overlay.appendChild(box);
        document.body.appendChild(overlay);

        /* ---- internal callbacks ----------------------------------- */
        function doConfirm() {
            var val = '';
            if (showInput) {
                var inp = $('zl-modal-input');
                val = inp ? inp.value : '';
            }
            removeModal();
            document.removeEventListener('keydown', onKey);
            onConfirm(val);
        }

        function doCancel() {
            removeModal();
            document.removeEventListener('keydown', onKey);
            onCancel();
        }

        function onKey(e) {
            if (e.key === 'Escape') { doCancel(); }
        }

        /* ---- bind ------------------------------------------------- */
        var confirmBtn = $('zl-modal-confirm');
        var cancelBtn  = $('zl-modal-cancel');
        if (confirmBtn) { confirmBtn.addEventListener('click', doConfirm); }
        if (cancelBtn)  { cancelBtn.addEventListener('click', doCancel); }

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) { doCancel(); }
        });

        document.addEventListener('keydown', onKey);

        // Focus the input when present, otherwise focus Confirm
        if (showInput) {
            var inp = $('zl-modal-input');
            if (inp) {
                inp.focus();
                inp.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); doConfirm(); }
                });
            }
        } else if (confirmBtn) {
            confirmBtn.focus();
        }
    }

    /**
     * Remove an open modal overlay from the DOM.
     */
    function removeModal() {
        var el = $('zl-modal-overlay');
        if (el && el.parentNode) { el.parentNode.removeChild(el); }
    }

    /* ================================================================
     *  PROGRESS UI
     * ================================================================ */

    /**
     * Show the progress panel with a label and percentage bar.
     * @param {string} label
     * @param {number} pct  0-100
     */
    function showProgress(label, pct) {
        var panel = $('zl-progress-panel');
        if (!panel) { return; }
        panel.style.display = 'block';

        var labelEl = $('zl-progress-label');
        if (labelEl) { labelEl.textContent = label || ''; }

        var p = Math.max(0, Math.min(100, Math.round(pct || 0)));

        var pctEl = $('zl-progress-pct');
        if (pctEl) { pctEl.textContent = p + '%'; }

        var barEl = $('zl-progress-bar');
        if (barEl) { barEl.style.width = p + '%'; }
    }

    /**
     * Hide the progress panel.
     */
    function hideProgress() {
        var panel = $('zl-progress-panel');
        if (panel) { panel.style.display = 'none'; }
    }

    /**
     * Append a timestamped message to the log div.
     * Auto-scrolls to bottom and trims to 200 lines.
     * @param {string} text
     */
    function logMsg(text) {
        var log = $('zl-log');
        if (!log) { return; }
        log.style.display = 'block';

        var line = document.createElement('div');
        line.className = 'zl-w-log-line';

        var ts = new Date();
        var hh = String(ts.getHours()).padStart(2, '0');
        var mm = String(ts.getMinutes()).padStart(2, '0');
        var ss = String(ts.getSeconds()).padStart(2, '0');
        line.textContent = '[' + hh + ':' + mm + ':' + ss + '] ' + text;
        log.appendChild(line);

        // Trim to 200 lines
        while (log.children.length > 200) {
            log.removeChild(log.firstChild);
        }

        // Auto-scroll
        log.scrollTop = log.scrollHeight;
    }

    /**
     * Clear all lines from the log div.
     */
    function clearLog() {
        var log = $('zl-log');
        if (!log) { return; }
        log.innerHTML = '';
        log.style.display = 'none';
    }

    /* ================================================================
     *  AJAX HELPER  (fetch + retry + timeout)
     * ================================================================ */

    var AJAX_TIMEOUT_MS = 360000;   // 6 minutes
    var AJAX_MAX_RETRIES = 2;

    /**
     * POST to WordPress admin-ajax.php.
     *
     * Includes:
     *  - FormData encoding of action, nonce, and arbitrary data
     *  - 6-minute abort-controller timeout
     *  - Up to 2 retries on 502 / 503 / 504 / timeout / network error
     *  - Exponential backoff (1 s, 2 s)
     *
     * @param {string} action  WordPress AJAX action name
     * @param {Object} [data]  Additional key/value pairs
     * @returns {Promise<Object>}
     */
    function ajaxPost(action, data) {

        function attempt(retryCount) {
            return new Promise(function (resolve, reject) {

                /* ---- build FormData -------------------------------- */
                var fd = new FormData();
                fd.append('action', action);
                fd.append('nonce', zlWidgetData.nonce);

                if (data && typeof data === 'object') {
                    var keys = Object.keys(data);
                    for (var i = 0; i < keys.length; i++) {
                        var k = keys[i];
                        var v = data[k];
                        if (v === null || v === undefined) { continue; }
                        if (typeof v === 'object') {
                            fd.append(k, JSON.stringify(v));
                        } else {
                            fd.append(k, v);
                        }
                    }
                }

                /* ---- abort controller / timeout -------------------- */
                var controller = null;
                var signal     = undefined;
                var timerId    = null;

                if (typeof AbortController !== 'undefined') {
                    controller = new AbortController();
                    signal     = controller.signal;
                    timerId    = setTimeout(function () { controller.abort(); }, AJAX_TIMEOUT_MS);
                }

                /* ---- fetch ----------------------------------------- */
                var fetchOpts = {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: fd
                };
                if (signal) { fetchOpts.signal = signal; }

                fetch(zlWidgetData.ajaxurl, fetchOpts)
                    .then(function (response) {
                        if (timerId) { clearTimeout(timerId); }

                        /* retryable status codes */
                        var code = response.status;
                        if ((code === 502 || code === 503 || code === 504) &&
                            retryCount < AJAX_MAX_RETRIES) {
                            var delay = Math.pow(2, retryCount) * 1000;
                            logMsg('HTTP ' + code + ' — retrying in ' + (delay / 1000) + 's ...');
                            return wait(delay).then(function () {
                                return attempt(retryCount + 1);
                            }).then(resolve, reject);
                        }

                        if (!response.ok) {
                            throw new Error('HTTP ' + code + ': ' + response.statusText);
                        }
                        return response.json();
                    })
                    .then(function (result) {
                        if (result !== undefined) { resolve(result); }
                    })
                    .catch(function (err) {
                        if (timerId) { clearTimeout(timerId); }

                        var retryable = (
                            err.name === 'AbortError' ||
                            err.message === 'Failed to fetch' ||
                            (err.message && err.message.indexOf('NetworkError') !== -1)
                        );
                        if (retryable && retryCount < AJAX_MAX_RETRIES) {
                            var delay = Math.pow(2, retryCount) * 1000;
                            logMsg('Network error (' + err.message + ') — retrying in ' +
                                   (delay / 1000) + 's ...');
                            return wait(delay).then(function () {
                                return attempt(retryCount + 1);
                            }).then(resolve, reject);
                        }

                        reject(err);
                    });
            });
        }

        return attempt(0);
    }

    /**
     * Simple setTimeout-based Promise delay.
     * @param {number} ms
     * @returns {Promise<void>}
     */
    function wait(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    /* ================================================================
     *  UI STATE HELPERS
     * ================================================================ */

    /**
     * Disable every interactive element inside the widget.
     */
    function disableActions() {
        var els = document.querySelectorAll(
            '#zl-widget-wrap button, #zl-widget-wrap select, #zl-widget-wrap input');
        for (var i = 0; i < els.length; i++) { els[i].disabled = true; }
    }

    /**
     * Re-enable interactive elements and re-apply permission gating.
     */
    function enableActions() {
        var els = document.querySelectorAll(
            '#zl-widget-wrap button, #zl-widget-wrap select, #zl-widget-wrap input');
        for (var i = 0; i < els.length; i++) { els[i].disabled = false; }
        applyPermissions();
    }

    /* ================================================================
     *  STATS
     * ================================================================ */

    /**
     * v2.1.0: Fetch summary stats and update the action-oriented tiles:
     * Ready to Email · Not Yet Contacted · New This Week · Contacted This Week.
     */
    function loadStats() {
        ajaxPost('zl_widget_summary', {})
            .then(function (resp) {
                if (!resp || !resp.success || !resp.data) { return; }
                var d = resp.data;

                var map = {
                    'zl-stat-ready':          d.ready_to_email,
                    'zl-stat-untouched':      d.not_contacted,
                    'zl-stat-newweek':        d.new_this_week,
                    'zl-stat-contactedweek':  d.contacted_this_week
                };
                var ids = Object.keys(map);
                for (var i = 0; i < ids.length; i++) {
                    var el = $(ids[i]);
                    if (!el) { continue; }
                    var val = (map[ids[i]] !== undefined && map[ids[i]] !== null)
                        ? parseInt(map[ids[i]], 10) : 0;
                    if (isNaN(val)) { val = 0; }
                    animateCount(el, val);
                }
            })
            .catch(function (err) {
                logMsg('Stats load error: ' + err.message);
            });
    }

    /**
     * v2.1.0: Small count-up animation for stat tiles. Respects
     * prefers-reduced-motion (sets the final value immediately).
     */
    function animateCount(el, target) {
        var reduce = window.matchMedia &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduce || target <= 0) { el.textContent = String(target); return; }
        var start = 0;
        var dur   = 500;
        var t0    = null;
        function step(ts) {
            if (t0 === null) { t0 = ts; }
            var p = Math.min(1, (ts - t0) / dur);
            // ease-out
            var eased = 1 - Math.pow(1 - p, 3);
            el.textContent = String(Math.round(start + (target - start) * eased));
            if (p < 1) { requestAnimationFrame(step); }
            else { el.textContent = String(target); }
        }
        requestAnimationFrame(step);
    }

    /* ================================================================
     *  v2.1.0: NO-LAYOUT-SHIFT SUBMIT HELPERS  (Prompt 7)
     *
     *  Adopts the theme's forthcoming NO-SUBMIT-SHIFT contract. These
     *  helpers keep a button's box size while it works (spinner via the
     *  .is-busy / .ts-btn-busy CSS) and reveal status text in a slot that
     *  ALREADY occupies space (so showing it pushes nothing). When the
     *  theme ships its canonical hooks, the same class names apply and the
     *  theme's (unscoped) rules take precedence.
     * ================================================================ */

    /**
     * Put a button into the busy state without resizing it.
     * @param {HTMLElement} btn
     */
    function setBtnBusy(btn) {
        if (!btn) { return; }
        // Lock current box size so the spinner swap can't reflow neighbors.
        var r = btn.getBoundingClientRect();
        if (r && r.width)  { btn.style.minWidth  = Math.ceil(r.width)  + 'px'; }
        if (r && r.height) { btn.style.minHeight = Math.ceil(r.height) + 'px'; }
        btn.classList.add('is-busy', 'ts-btn-busy');
        btn.setAttribute('aria-busy', 'true');
        btn.disabled = true;
    }

    /**
     * Clear the busy state.
     * @param {HTMLElement} btn
     */
    function clearBtnBusy(btn) {
        if (!btn) { return; }
        btn.classList.remove('is-busy', 'ts-btn-busy');
        btn.removeAttribute('aria-busy');
        btn.disabled = false;
        btn.style.minWidth = '';
        btn.style.minHeight = '';
    }

    /**
     * Show a message in a pre-reserved inline status slot (no reflow). The
     * slot is created adjacent to `anchorEl` once and reused. Also mirrors
     * the message into a visually-hidden live region for screen readers.
     * @param {HTMLElement} anchorEl  Element after which the slot sits.
     * @param {string}      msg
     * @param {string}      [tone]    'ok' | 'err' | '' (neutral)
     */
    function showInlineStatus(anchorEl, msg, tone) {
        if (!anchorEl) { return; }
        var slot = anchorEl.parentNode &&
            anchorEl.parentNode.querySelector(':scope > .zl-inline-status');
        if (!slot) {
            slot = document.createElement('div');
            slot.className = 'zl-inline-status';
            // Reserve space immediately so first reveal doesn't shift.
            if (anchorEl.nextSibling) {
                anchorEl.parentNode.insertBefore(slot, anchorEl.nextSibling);
            } else {
                anchorEl.parentNode.appendChild(slot);
            }
        }
        slot.textContent = msg || '';
        slot.style.color = (tone === 'err')
            ? 'var(--sys-color-error, #EF4444)'
            : (tone === 'ok' ? 'var(--sys-color-success, #10B981)' : 'var(--sys-text-sec, #475569)');
        // Reveal via opacity only (class drives the transition).
        if (msg) { slot.classList.add('is-visible'); }
        else { slot.classList.remove('is-visible'); }

        // Screen-reader live region (created once, appended to the widget).
        var live = document.getElementById('zl-sr-live');
        if (!live) {
            live = document.createElement('div');
            live.id = 'zl-sr-live';
            live.className = 'zl-sr-live';
            live.setAttribute('aria-live', 'polite');
            live.setAttribute('role', 'status');
            var wrap = $('zl-widget-wrap');
            if (wrap) { wrap.appendChild(live); }
        }
        if (live && msg) { live.textContent = msg; }
    }

    /**
     * Fetch batch list via zl_widget_batches and render rows.
     */
    function loadBatches() {
        if (!hasPerm('view_batch_history')) { return; }

        var container = $('zl-batch-list');
        if (!container) { return; }
        container.innerHTML =
            '<div class="zl-w-loading">Loading batches&hellip;</div>';

        ajaxPost('zl_widget_batches', {})
            .then(function (resp) {
                if (!resp || !resp.success || !resp.data) {
                    container.innerHTML =
                        '<div class="zl-w-empty">No batches found.</div>';
                    return;
                }
                // v2.0.0 FIX: API returns { batches: [...], total, page, ... }
                var batches = resp.data.batches || resp.data;
                if (!Array.isArray(batches) || batches.length === 0) {
                    container.innerHTML =
                        '<div class="zl-w-empty">No batches found.</div>';
                    return;
                }
                renderBatchList(batches, container);

                // v2.0.0: AUTO-RESUME — If any batch is still running/generating,
                // automatically resume polling for it. This is the fix for
                // "can't check on a batch after navigating away."
                if (!isRunning) {
                    for (var ri = 0; ri < batches.length; ri++) {
                        var bs = (batches[ri].status || '').toLowerCase();
                        if (bs === 'generating' || bs === 'running' || bs === 'in_progress') {
                            var resumeId = batches[ri].id || batches[ri].batch_id;
                            if (resumeId) {
                                currentBatchId = resumeId;
                                isRunning = true;
                                disableActions();
                                showProgress('Batch #' + resumeId + ' is still running...', 0);
                                logMsg('Resuming progress tracking for batch #' + resumeId);
                                pollBatchProgress(resumeId);
                                break; // Only resume the first (most recent) running batch
                            }
                        }
                    }
                }
            })
            .catch(function (err) {
                container.innerHTML =
                    '<div class="zl-w-empty" style="color:var(--sys-color-error,#d63638);">Error: ' +
                    escHtml(err.message) + '</div>';
            });
    }

    /**
     * Render batch rows into a container element.
     * @param {Array}       batches
     * @param {HTMLElement}  container
     */
    function renderBatchList(batches, container) {
        var html = '';
        for (var i = 0; i < batches.length; i++) {
            var b = batches[i];

            var batchId    = b.id || b.batch_id || '';
            var tag        = b.tag || b.batch_tag || '';
            var assignedTo = b.assigned_label || b.assigned_to || b.salesperson || '';
            // v2.0.0 FIX: API returns total_leads and contacted_count, not lead_count/contacted
            var leadCount  = b.total_leads !== undefined ? b.total_leads : (b.lead_count !== undefined ? b.lead_count : 0);
            var contacted  = b.contacted_count !== undefined ? b.contacted_count : (b.contacted !== undefined ? b.contacted : 0);
            var total      = leadCount;
            var status     = b.status || 'unknown';
            var date       = b.created_at || b.date || '';
            var isTest     = (tag && tag.toLowerCase().indexOf('test') !== -1) ||
                             b.is_test === true || b.is_test === 1 || b.is_test === '1';
            var aiSummary  = b.ai_summary || '';
            var isRunningBatch = (status === 'generating' || status === 'running' || status === 'in_progress');

            html += '<div class="zl-w-batch-row' + (isRunningBatch ? ' is-running' : '') + '" data-batch-id="' + escHtml(batchId) + '">';

            // v2.0.0: Show running hint banner for in-progress batches
            if (isRunningBatch) {
                html += '<div class="zl-batch-running-hint">Generation in progress — tracking automatically</div>';
            }

            /* -- header (accessible accordion control) ------------ */
            var leadsDomId = 'zl-batch-leads-' + escHtml(batchId);
            html += '<div class="zl-w-batch-row-header zl-batch-header" data-batch-id="' + escHtml(batchId) +
                    '" role="button" tabindex="0" aria-expanded="false" aria-controls="' + leadsDomId + '">';

            // left
            html += '<div style="display:flex;align-items:center;gap:8px;flex:1;min-width:0;">';
            html += '<span class="zl-w-batch-toggle zl-expand-arrow" aria-hidden="true">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>' +
                    '</span>';

            if (tag) {
                var tagCls = isTest ? 'zl-w-batch-tag zl-w-badge-test' : 'zl-w-batch-tag';
                html += '<span class="' + tagCls + '">' + escHtml(tag) + '</span>';
            }
            if (assignedTo) {
                html += '<span style="font-size:12px;color:var(--sys-text-sec,#475569);white-space:nowrap;' +
                        'overflow:hidden;text-overflow:ellipsis;">' + escHtml(assignedTo) + '</span>';
            }
            html += '<span class="zl-w-batch-count">' +
                    contacted + '/' + total + ' leads</span>';
            html += '</div>';

            // right
            html += '<div class="zl-w-batch-actions">';
            html += statusBadge(status);
            html += '<span class="zl-w-batch-date">' +
                    escHtml(formatDate(date)) + '</span>';

            if (isTest && hasPerm('can_sync_nutshell')) {
                html += '<button class="zl-w-btn zl-w-btn-outline zl-w-btn-sm zl-btn-send-test" data-batch-id="' + escHtml(batchId) +
                        '" title="Send to Nutshell">Send to CRM</button>';
            }
            html += '<button class="zl-w-btn zl-w-btn-sm zl-btn-delete-batch" data-batch-id="' + escHtml(batchId) +
                    '" style="background:var(--ref-red-600,#DC2626);color:#fff;" title="Delete batch" aria-label="Delete batch">&times;</button>';
            html += '<span class="zl-batch-hint" aria-hidden="true">Tap to view</span>';
            html += '</div>';
            html += '</div>'; // /header

            /* -- expandable leads area ---------------------------- */
            html += '<div class="zl-w-leads-container zl-batch-leads" id="zl-batch-leads-' + escHtml(batchId) +
                    '" style="display:none;"></div>';

            // v2.0.0: AI Summary (collapsed by default)
            if (aiSummary && aiSummary.length > 10) {
                var summaryId = 'zl-ai-summary-' + escHtml(batchId);
                html += '<div style="padding:0 12px 8px;">';
                html += '<button class="zl-ai-toggle" data-target="' + summaryId + '">';
                html += '<span class="zl-ai-toggle-arrow">▸</span> AI Summary</button>';
                html += '<div class="zl-ai-summary" id="' + summaryId + '">' + escHtml(aiSummary) + '</div>';
                html += '</div>';
            }

            html += '</div>'; // /batch-row
        }

        container.innerHTML = html;
        bindBatchEvents(container);
    }

    /**
     * Produce an inline status badge.
     * @param {string} status
     * @returns {string}
     */
    function statusBadge(status) {
        var statusMap = {
            complete:    'zl-w-status-complete',
            completed:   'zl-w-status-complete',
            finalized:   'zl-w-status-complete',
            running:     'zl-w-status-generating',
            in_progress: 'zl-w-status-generating',
            pending:     'zl-w-status-generating',
            failed:      'zl-w-status-failed',
            error:       'zl-w-status-failed',
            test:        'zl-w-badge-test'
        };
        var cls = statusMap[status] || '';
        return '<span class="zl-w-batch-status' + (cls ? ' ' + cls : '') + '">' +
               escHtml(ucfirst(status)) + '</span>';
    }

    /**
     * Attach click handlers to batch headers and action buttons.
     * @param {HTMLElement} container
     */
    function bindBatchEvents(container) {

        /* expand / collapse (click + keyboard, since headers are role=button) */
        var headers = container.querySelectorAll('.zl-batch-header');
        for (var i = 0; i < headers.length; i++) {
            headers[i].addEventListener('click', handleHeaderClick);
            headers[i].addEventListener('keydown', handleHeaderKeydown);
        }

        /* delete */
        var delBtns = container.querySelectorAll('.zl-btn-delete-batch');
        for (var j = 0; j < delBtns.length; j++) {
            delBtns[j].addEventListener('click', handleDeleteClick);
        }

        /* send test to CRM */
        var sendBtns = container.querySelectorAll('.zl-btn-send-test');
        for (var k = 0; k < sendBtns.length; k++) {
            sendBtns[k].addEventListener('click', handleSendTestClick);
        }

        /* v2.0.0: AI summary toggles */
        var aiToggles = container.querySelectorAll('.zl-ai-toggle');
        for (var m = 0; m < aiToggles.length; m++) {
            aiToggles[m].addEventListener('click', function (e) {
                e.stopPropagation();
                var targetId = this.getAttribute('data-target');
                var target = document.getElementById(targetId);
                if (target) {
                    target.classList.toggle('open');
                    this.classList.toggle('open');
                }
            });
        }
    }

    function handleHeaderClick(e) {
        if (e.target.tagName === 'BUTTON' || (e.target.closest && e.target.closest('button'))) { return; }
        var bid = this.getAttribute('data-batch-id');
        toggleBatchLeads(bid, this);
    }

    function handleHeaderKeydown(e) {
        if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
            // Don't hijack activation of a focused inner button.
            if (e.target.tagName === 'BUTTON') { return; }
            e.preventDefault();
            var bid = this.getAttribute('data-batch-id');
            toggleBatchLeads(bid, this);
        }
    }

    function handleDeleteClick(e) {
        e.stopPropagation();
        deleteBatch(this.getAttribute('data-batch-id'));
    }

    function handleSendTestClick(e) {
        e.stopPropagation();
        sendTestToNutshell(this.getAttribute('data-batch-id'), this);
    }

    /**
     * Expand or collapse a batch's lead section.
     * @param {string}      batchId
     * @param {HTMLElement}  headerEl
     */
    function toggleBatchLeads(batchId, headerEl) {
        var div   = $('zl-batch-leads-' + batchId);
        if (!div) { return; }

        var reduce = window.matchMedia &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var isClosed = (div.style.display === 'none' || div.style.display === '');

        if (isClosed) {
            /* ---- OPEN ---- */
            div.style.display = 'block';
            if (headerEl) {
                headerEl.classList.add('is-open');
                headerEl.setAttribute('aria-expanded', 'true');
            }
            if (!div.dataset.loaded) { loadBatchLeads(batchId); }

            if (reduce) { return; } // no animation; content just appears

            // Animate from 0 → measured height, then release to auto so
            // late-loading async content (lead cards) isn't clipped.
            div.classList.add('zl-animating');
            div.style.maxHeight = '0px';
            div.style.opacity = '0';
            // Force reflow so the start state is committed before transition.
            // eslint-disable-next-line no-unused-expressions
            div.offsetHeight;
            requestAnimationFrame(function () {
                div.style.maxHeight = Math.max(div.scrollHeight, 400) + 'px';
                div.style.opacity = '1';
            });
            // After the transition, drop the cap so the panel can grow with
            // async lead cards / expanding details.
            var release = function () {
                div.style.maxHeight = 'none';
                div.classList.remove('zl-animating');
                div.removeEventListener('transitionend', release);
            };
            div.addEventListener('transitionend', release);
            // Safety release in case transitionend doesn't fire.
            setTimeout(release, 400);
        } else {
            /* ---- CLOSE ---- */
            if (headerEl) {
                headerEl.classList.remove('is-open');
                headerEl.setAttribute('aria-expanded', 'false');
            }
            if (reduce) {
                div.style.display = 'none';
                return;
            }
            // Animate current height → 0, then hide.
            div.classList.add('zl-animating');
            div.style.maxHeight = div.scrollHeight + 'px';
            // eslint-disable-next-line no-unused-expressions
            div.offsetHeight;
            requestAnimationFrame(function () {
                div.style.maxHeight = '0px';
                div.style.opacity = '0';
            });
            var done = function () {
                div.style.display = 'none';
                div.style.maxHeight = '';
                div.style.opacity = '';
                div.classList.remove('zl-animating');
                div.removeEventListener('transitionend', done);
            };
            div.addEventListener('transitionend', done);
            setTimeout(done, 400);
        }
    }

    /* ================================================================
     *  BATCH LEADS (expand view)
     * ================================================================ */

    /**
     * Fetch leads for a specific batch and render lead cards.
     * @param {string} batchId
     */
    function loadBatchLeads(batchId) {
        var div = $('zl-batch-leads-' + batchId);
        if (!div) { return; }
        div.innerHTML =
            '<div class="zl-w-loading">Loading leads&hellip;</div>';

        ajaxPost('zl_get_batch_leads', { batch_id: batchId })
            .then(function (resp) {
                if (!resp || !resp.success || !resp.data) {
                    div.innerHTML =
                        '<div class="zl-w-empty">No leads found.</div>';
                    div.dataset.loaded = '1';
                    return;
                }
                // v2.0.0 FIX: API returns { leads: [...], batch: {...} }
                var leads = resp.data.leads || resp.data;
                if (!Array.isArray(leads) || leads.length === 0) {
                    div.innerHTML =
                        '<div class="zl-w-empty">No leads in this batch.</div>';
                    div.dataset.loaded = '1';
                    return;
                }
                renderLeadCards(leads, div, batchId);
                div.dataset.loaded = '1';
            })
            .catch(function (err) {
                div.innerHTML =
                    '<div class="zl-w-empty" style="color:var(--sys-color-error,#d63638);">Error: ' +
                    escHtml(err.message) + '</div>';
            });
    }

    /**
     * Render lead cards.
     * @param {Array}       leads
     * @param {HTMLElement}  container
     * @param {string}      batchId
     */
    function renderLeadCards(leads, container, batchId) {
        var html = '';
        for (var i = 0; i < leads.length; i++) {
            html += buildLeadCard(leads[i], batchId);
        }
        container.innerHTML = html;
        bindLeadEvents(container);
    }

    /* ---- score helpers ------------------------------------------- */

    function scoreLabel(score) {
        var n = parseInt(score, 10) || 0;
        if (n >= 70) { return 'high'; }
        if (n >= 40) { return 'med'; }
        return 'low';
    }

    function scoreColor(label) {
        var l = (label || '').toLowerCase();
        if (l === 'high')                    { return '#00a32a'; }
        if (l === 'med' || l === 'medium')   { return '#dba617'; }
        return '#d63638';
    }

    function contactStatusColor(status) {
        var s = (status || '').toLowerCase();
        if (s === 'contacted') { return { bg: '#00a32a1a', fg: '#00a32a' }; }
        if (s === 'skipped')   { return { bg: '#8888881a', fg: '#888888' }; }
        return { bg: '#72aee61a', fg: '#72aee6' };
    }

    function nutshellStatusColor(status) {
        var s = (status || '').toLowerCase();
        if (s === 'synced' || s === 'created') { return { bg: '#8c5fe61a', fg: '#8c5fe6' }; }
        if (s === 'error'  || s === 'failed')  { return { bg: '#d636381a', fg: '#d63638' }; }
        return { bg: '#8888881a', fg: '#888888' };
    }

    /* ---- card builder -------------------------------------------- */

    /**
     * Build a single lead card.
     * @param {Object} lead
     * @param {string} batchId
     * @returns {string}
     */
    function buildLeadCard(lead, batchId) {
        var leadId          = lead.id || lead.lead_id || '';
        // v2.0.0 FIX: Construct name from first_name + last_name (DB stores them separately)
        var firstName       = lead.first_name || '';
        var lastName        = lead.last_name || '';
        var name            = (firstName + ' ' + lastName).trim();
        if (!name) { name = lead.name || lead.customer_name || 'Customer'; }
        var org             = lead.organization || lead.company || '';
        var score           = lead.score !== undefined ? Math.round(parseFloat(lead.score)) : 0;
        var sLabel          = lead.score_label || scoreLabel(score);
        var contactStatus   = lead.contact_status || 'pending';
        var nsStatus        = lead.nutshell_status || '';
        var nsLeadId        = lead.nutshell_lead_id || '';
        var nsUrl           = lead.nutshell_url || lead.nutshell_link || '';
        var city            = lead.city || '';
        var state           = lead.state || '';
        var location        = city + (city && state ? ', ' : '') + state;
        if (!location) { location = lead.location || lead.city_state || ''; }
        var email           = lead.email || '';
        var phone           = lead.phone || '';
        var purchaseSummary = lead.purchase_summary || '';
        var purchaseHist    = lead.purchase_history || '';
        var spNotes         = lead.salesperson_notes || lead.notes || '';
        var isSkipped       = (contactStatus === 'skipped');
        var isContacted     = (contactStatus === 'contacted');
        var batchOptions    = typeof zlWidgetData !== 'undefined' ? zlWidgetData : {};

        // Parse purchase date from history for email
        var purchaseDate    = '';
        var purchaseItems   = [];
        try {
            var parsed = typeof purchaseHist === 'string' ? JSON.parse(purchaseHist) : purchaseHist;
            if (Array.isArray(parsed)) {
                purchaseItems = parsed;
                for (var pi = 0; pi < parsed.length; pi++) {
                    if (parsed[pi].date && parsed[pi].name && parsed[pi].name !== 'Location' && parsed[pi].name !== 'Tax and Installation is Included') {
                        if (!purchaseDate || parsed[pi].date < purchaseDate) {
                            purchaseDate = parsed[pi].date;
                        }
                    }
                }
            }
        } catch (e) { /* not valid JSON */ }

        var h = '';
        h += '<div class="zl-w-lead-card' + (isSkipped ? ' zl-skipped' : '') + (isContacted ? ' zl-contacted' : '') +
             '" data-lead-id="' + escHtml(leadId) + '" data-batch-id="' + escHtml(batchId) + '">';

        /* ─── HEADER: Score + Name + Location + Status ─────────── */
        h += '<div class="zl-lc-header">';

        // Score badge with meaningful label
        var scoreClass = score >= 70 ? 'high' : (score >= 50 ? 'mid' : 'low');
        var scoreMeaning = score >= 80 ? 'Very likely to re-engage' : (score >= 70 ? 'Strong prospect' : (score >= 50 ? 'Worth a check-in' : 'Lower priority'));
        h += '<div class="zl-lc-score-wrap">';
        h += '<div class="zl-lc-score ' + scoreClass + '">' + score + '</div>';
        h += '<div class="zl-lc-score-label">' + escHtml(scoreMeaning) + '</div>';
        h += '</div>';

        h += '<div class="zl-lc-identity">';
        h += '<div class="zl-lc-name">' + escHtml(name) + '</div>';
        if (location) {
            // Abbreviate state names (CALIFORNIA → CA)
            var displayLoc = location.replace(/,?\s*CALIFORNIA\b/i, ', CA')
                                     .replace(/,?\s*ARIZONA\b/i, ', AZ')
                                     .replace(/,?\s*NEVADA\b/i, ', NV')
                                     .replace(/,?\s*TEXAS\b/i, ', TX')
                                     .replace(/,?\s*OREGON\b/i, ', OR')
                                     .replace(/,?\s*WASHINGTON\b/i, ', WA')
                                     .replace(/,?\s*COLORADO\b/i, ', CO')
                                     .replace(/,?\s*FLORIDA\b/i, ', FL')
                                     .replace(/,?\s*NEW YORK\b/i, ', NY')
                                     .replace(/\s+/g, ' ').trim();
            h += '<div class="zl-lc-location">' + escHtml(displayLoc) + '</div>';
        }
        h += '</div>';

        // Status chip
        var statusLabel = isSkipped ? 'Skipped' : (isContacted ? 'Contacted' : 'Pending');
        var statusClass = isSkipped ? 'skipped' : (isContacted ? 'contacted' : 'pending');
        h += '<div class="zl-lc-status ' + statusClass + '">' + statusLabel + '</div>';
        h += '</div>';

        /* ─── WHY WE'RE CONTACTING ────────────────────────────── */
        if (purchaseSummary) {
            h += '<div class="zl-lc-why">';
            h += '<span class="zl-lc-why-label">Purchased:</span> ' + escHtml(purchaseSummary);
            if (purchaseDate) {
                h += ' <span class="zl-lc-date">(' + escHtml(purchaseDate) + ')</span>';
            }
            h += '</div>';
        }

        /* ─── ACTION ROW: Email / Call / Contacted / Skip ──────── */
        h += '<div class="zl-lc-actions">';

        // Email button — pre-filled mailto with check-in message
        if (email && hasPerm('view_contact_info')) {
            // Subject line comes from the neutral default Voice profile (or the tenant's
            // override via zl_email_voice). {name} is filled with the first name; a variant
            // without {name} is used when the first name is unknown.
            var fn = (firstName || name.split(' ')[0] || '').trim();
            var _wd = (typeof zlWidgetData !== 'undefined') ? zlWidgetData : {};
            var _subjTpl = (_wd.emailVoice && _wd.emailVoice.subjects && _wd.emailVoice.subjects.length)
                ? _wd.emailVoice.subjects
                : (fn ? ['Checking in, {name}', 'A quick hello, {name}', 'Following up, {name}']
                      : ['Just checking in', 'A quick hello', 'Following up']);
            var emailSubject = String(_subjTpl[Math.floor(Math.random() * _subjTpl.length)])
                .replace(/\{name\}/g, fn).replace(/,\s*$/, '').trim();
            // Pass the product filter so the email focuses on what was searched
            var emailOpts = { lastProductFilter: '' };
            var pfInput = document.getElementById('zl-filter-product');
            if (pfInput && pfInput.value) {
                emailOpts.lastProductFilter = pfInput.value;
            }
            var emailBody = buildCheckInEmail(fn, purchaseSummary, purchaseDate, emailOpts);
            // mailto line-break fix (v2.3.1): the body is built with "\n\n" between
            // sentences — the house voice's one-thought-per-line signature. But a
            // mailto: body must use CRLF; many mail clients (notably the iOS/macOS
            // Mail handoff the field reps use) silently DROP a lone %0A, collapsing
            // every paragraph break into one run-on block ("Hi Leyssa,You crossed my
            // mind...a user T.619-..."). Normalize any lone LF to CRLF before
            // encoding so the airy layout survives. encodeURIComponent then yields
            // %0D%0A, which clients honor. (The copy itself is already on-voice; this
            // is purely a transport-encoding fix, not a content change.)
            var emailBodyCRLF = emailBody.replace(/\r\n|\r|\n/g, '\r\n');
            h += '<a href="mailto:' + encodeURIComponent(email) +
                 '?subject=' + encodeURIComponent(emailSubject) +
                 '&body=' + encodeURIComponent(emailBodyCRLF) +
                 '" class="zl-lc-btn zl-lc-email" title="Email ' + escHtml(name) + '">';
            h += '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>';
            h += ' Email</a>';
        }

        // Phone button
        if (phone && hasPerm('view_contact_info')) {
            h += '<a href="tel:' + escHtml(phone.replace(/[^+\d]/g, '')) + '" class="zl-lc-btn zl-lc-phone" title="Call ' + escHtml(name) + '">';
            h += '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
            h += ' Call</a>';
        }

        // Contacted button
        if (hasPerm('can_mark_contacted') && !isContacted) {
            h += '<button class="zl-lc-btn zl-lc-contacted zl-btn-contacted" data-lead-id="' + escHtml(leadId) +
                 '" data-batch-id="' + escHtml(batchId) + '">✓ Contacted</button>';
        }

        // Skip button — one-click, no dialog
        if (hasPerm('can_mark_contacted') && !isSkipped && !isContacted) {
            h += '<button class="zl-lc-btn zl-lc-skip zl-btn-skip-instant" data-lead-id="' + escHtml(leadId) +
                 '" data-batch-id="' + escHtml(batchId) + '">Skip</button>';
        }
        // Undo Skip button (only on skipped cards)
        if (isSkipped) {
            h += '<button class="zl-lc-btn zl-lc-unskip zl-btn-unskip" data-lead-id="' + escHtml(leadId) +
                 '" data-batch-id="' + escHtml(batchId) + '">Undo Skip</button>';
        }

        h += '</div>';

        /* ─── PURCHASE HISTORY (pretty formatted) ──────────────── */
        if (purchaseItems.length > 0) {
            h += '<details class="zl-lc-purchase">';
            h += '<summary>Purchase History (' + purchaseItems.length + ' items)</summary>';
            h += '<div class="zl-lc-purchase-list">';
            for (var p = 0; p < purchaseItems.length; p++) {
                var item = purchaseItems[p];
                var itemName = item.name || '';
                // Skip location lines and tax lines
                if (itemName === 'Location' || itemName === 'Tax and Installation is Included') { continue; }
                var itemDesc = item.description || '';
                var itemQty  = item.qty || 1;
                var itemAmt  = item.amount || 0;
                var itemDate = item.date || '';
                h += '<div class="zl-lc-purchase-item">';
                h += '<div class="zl-lc-pi-name">' + escHtml(itemName);
                if (itemQty > 1) { h += ' <span class="zl-lc-pi-qty">×' + itemQty + '</span>'; }
                h += '</div>';
                if (itemDesc) {
                    h += '<div class="zl-lc-pi-desc">' + escHtml(itemDesc.substring(0, 80)) + (itemDesc.length > 80 ? '…' : '') + '</div>';
                }
                h += '<div class="zl-lc-pi-meta">';
                if (itemDate) { h += '<span>' + escHtml(itemDate) + '</span>'; }
                if (itemAmt > 0) { h += '<span>$' + Number(itemAmt).toLocaleString() + '</span>'; }
                h += '</div>';
                h += '</div>';
            }
            h += '</div></details>';
        }

        /* ─── SALESPERSON NOTES (AI summary) ──────────────────── */
        if (spNotes) {
            h += '<details class="zl-lc-sp-notes">';
            h += '<summary>AI Notes</summary>';
            h += '<div class="zl-lc-sp-body">' + escHtml(spNotes) + '</div>';
            h += '</details>';
        }

        h += '</div>'; // /lead-card
        return h;
    }

    /**
     * Build a pre-filled, human-sounding check-in email body.
     *
     * VOICE BINDING: the copy comes from a NEUTRAL default Voice profile localized by the
     * server (zlWidgetData.emailVoice), not from hardcoded house-voice text. A tenant
     * overrides the openers/offers/subjects via the zl_email_voice PHP filter; the
     * statistically-derived company house voice is private and is NOT shipped here.
     * Tokens: {name} {product} {time} {signName} {phone}. An empty sign-off name or phone
     * line is omitted rather than filled with a placeholder — nothing is invented.
     */
    function buildCheckInEmail(firstName, purchaseSummary, purchaseDate, opts) {
        var name  = firstName || 'there';
        var wd    = (typeof zlWidgetData !== 'undefined') ? zlWidgetData : {};
        var voice = wd.emailVoice || {};

        // Sign-off name + phone come only from the profile (server). No hardcoded fallback —
        // an empty value drops that line from the signature.
        var signName = wd.userEmailName || '';
        var phone    = wd.userPhone || wd.companyPhone || '';

        // Resolve a plain-language product phrase (data-driven; see humanizeProduct).
        var rawProduct = '';
        var productFilter = (opts && opts.lastProductFilter) ? opts.lastProductFilter : '';
        if (productFilter) {
            rawProduct = productFilter;
        } else if (purchaseSummary) {
            var items = purchaseSummary.split(',');
            for (var i = 0; i < items.length; i++) {
                var item = items[i].trim();
                var low  = item.toLowerCase();
                if (item && low !== 'location' && low.indexOf('tax') === -1) {
                    rawProduct = item; break;
                }
            }
        }
        var productPhrase = humanizeProduct(rawProduct);
        var timeRef       = humanizeTimeRef(purchaseDate);

        // Neutral default voice when the tenant supplies none: plain and warm, no trade
        // nouns, no company identity (that lives in the From field).
        var openers = (voice.openers && voice.openers.length) ? voice.openers
            : ['It’s been {time} since we completed your order.'];
        var offers  = (voice.offers && voice.offers.length) ? voice.offers
            : ['If there’s anything else we can help with, I’d be glad to take a look.'];

        var v = Math.floor(Math.random() * Math.max(openers.length, offers.length));
        function fill(s) {
            return String(s)
                .replace(/\{product\}/g, productPhrase)
                .replace(/\{time\}/g, timeRef)
                .replace(/\{name\}/g, name)
                .replace(/\{signName\}/g, signName)
                .replace(/\{phone\}/g, phone);
        }

        // Assemble (blank line between sentences). Sign-off lines appended only when set.
        var body = 'Hi ' + name + ',\n\n';
        body += fill(openers[v % openers.length]) + '\n\n';
        body += fill(offers[v % offers.length]);
        if (signName) { body += '\n\n' + signName; }
        if (phone)    { body += (signName ? '\n' : '\n\n') + phone; }
        return body;
    }

    /**
     * Turn a raw line-item / filter string into plain language for the email.
     *
     * ITEM ENGINE BINDING: the token → friendly-phrase map is DATA-DRIVEN
     * (zlWidgetData.productHumanize: an ordered array of { match, phrase }, most-specific
     * first). Core ships it EMPTY — no product name in code — so by default this returns the
     * neutral fallback ('the items we provided', overridable via the voice profile's
     * fallback_product). A tenant / the shared Item Engine supplies the map via the
     * zl_product_humanize_map PHP filter.
     */
    function humanizeProduct(raw) {
        var wd = (typeof zlWidgetData !== 'undefined') ? zlWidgetData : {};
        var fallback = (wd.emailVoice && wd.emailVoice.fallback_product)
            ? wd.emailVoice.fallback_product : 'the items we provided';
        if (!raw) { return fallback; }
        // Strip qty/sku noise: "(x2)", trailing dates, codes, extra spaces.
        var p = String(raw).toLowerCase()
             .replace(/\(x?\d+\)/g, ' ')
             .replace(/\b\d{4}[\/\-]\d{1,2}([\/\-]\d{1,2})?\b/g, ' ')
             .replace(/[#|]+/g, ' ')
             .replace(/\s{2,}/g, ' ')
             .trim();
        var map = (wd.productHumanize && wd.productHumanize.length) ? wd.productHumanize : [];
        for (var i = 0; i < map.length; i++) {
            var m = (map[i] && map[i].match) ? String(map[i].match).toLowerCase() : '';
            if (m && p.indexOf(m) !== -1) { return map[i].phrase || fallback; }
        }
        // Unknown / no vocabulary configured: stay neutral rather than echo the raw token.
        return fallback;
    }

    /**
     * v2.1.0: Natural human time reference. Never emits "YYYY/MM".
     * Uses season/relative phrasing the way a person recognizes time.
     */
    function humanizeTimeRef(purchaseDate) {
        if (!purchaseDate) { return 'a little while'; }
        var d = new Date(purchaseDate);
        if (isNaN(d.getTime())) { return 'a little while'; }
        var now = new Date();
        var months = Math.round((now - d) / (30 * 24 * 60 * 60 * 1000));

        if (months <= 1)  { return 'about a month'; }
        if (months <= 3)  { return 'a few months'; }
        if (months <= 6)  { return 'several months'; }
        if (months <= 11) { return 'the better part of a year'; }
        if (months <= 14) { return 'about a year'; }
        if (months <= 30) { return 'a couple of years'; }
        if (months <= 48) { return 'a few years'; }
        return 'quite a while';
    }

    /**
     * Build a collapsible (disclosure) section.
     * @param {string} title
     * @param {string} content
     * @param {string} sectionId
     * @returns {string}
     */
    function buildCollapsible(title, content, sectionId, expanded) {
        var isOpen = expanded === true;
        var h = '<div>';
        h += '<div class="zl-w-collapsible-toggle zl-collapsible-toggle" data-target="' + escHtml(sectionId) + '">';
        h += '<span class="zl-w-toggle-arrow zl-collapse-arrow" style="transform:rotate(' + (isOpen ? '90' : '0') + 'deg);">&#9654;</span>';
        h += escHtml(title);
        h += '</div>';
        h += '<div id="' + escHtml(sectionId) +
             '" class="zl-w-collapsible-content" style="display:' + (isOpen ? 'block' : 'none') + ';">' + escHtml(String(content)) + '</div>';
        h += '</div>';
        return h;
    }

    /**
     * Bind lead-level event handlers (Contacted, Skip, collapsible toggles).
     * @param {HTMLElement} container
     */
    function bindLeadEvents(container) {

        /* Contacted */
        var cBtns = container.querySelectorAll('.zl-btn-contacted');
        for (var i = 0; i < cBtns.length; i++) {
            cBtns[i].addEventListener('click', function (e) {
                e.stopPropagation();
                markContacted(
                    this.getAttribute('data-lead-id'),
                    this.getAttribute('data-batch-id')
                );
            });
        }

        /* Skip */
        var sBtns = container.querySelectorAll('.zl-btn-skip');
        for (var j = 0; j < sBtns.length; j++) {
            sBtns[j].addEventListener('click', function (e) {
                e.stopPropagation();
                markSkipped(
                    this.getAttribute('data-lead-id'),
                    this.getAttribute('data-batch-id')
                );
            });
        }

        /* v2.0.0: Instant Skip — gray out card, inject Undo button */
        var sInstBtns = container.querySelectorAll('.zl-btn-skip-instant');
        for (var si = 0; si < sInstBtns.length; si++) {
            sInstBtns[si].addEventListener('click', function (e) {
                e.stopPropagation();
                var btn = this;
                var lid = btn.getAttribute('data-lead-id');
                var bid = btn.getAttribute('data-batch-id');
                var card = btn.closest('.zl-w-lead-card') || document.querySelector('[data-lead-id="' + lid + '"]');
                if (!card) return;

                // 1. Add skipped class (dims the card)
                card.classList.add('zl-skipped');

                // 2. Update status chip
                var chip = card.querySelector('.zl-lc-status');
                if (chip) {
                    chip.className = 'zl-lc-status skipped';
                    chip.textContent = 'SKIPPED';
                }

                // 3. Replace the actions row with just an Undo button
                var actionsRow = card.querySelector('.zl-lc-actions');
                if (actionsRow) {
                    actionsRow.innerHTML =
                        '<button class="zl-lc-btn zl-lc-unskip zl-btn-unskip-live" ' +
                        'data-lead-id="' + lid + '" data-batch-id="' + bid + '">↩ Undo Skip</button>';

                    // Bind the undo button immediately
                    var undoBtn = actionsRow.querySelector('.zl-btn-unskip-live');
                    if (undoBtn) {
                        undoBtn.addEventListener('click', function (ue) {
                            ue.stopPropagation();
                            var uBtn = this;
                            uBtn.disabled = true;
                            uBtn.textContent = 'Restoring...';

                            ajaxPost('zl_update_contact_status', {
                                lead_id: lid,
                                contact_status: 'pending',
                                contact_notes: ''
                            }).then(function () {
                                // Reload the entire batch to get clean card rendering
                                var leadsContainer = document.getElementById('zl-batch-leads-' + bid);
                                if (leadsContainer) {
                                    leadsContainer.dataset.loaded = '';
                                    loadBatchLeads(bid);
                                }
                            }).catch(function () {
                                uBtn.disabled = false;
                                uBtn.textContent = '↩ Undo Skip';
                            });
                        });
                    }
                }

                // 4. Collapse the purchase history and other expandable content
                var details = card.querySelectorAll('details');
                for (var di = 0; di < details.length; di++) {
                    details[di].removeAttribute('open');
                }

                // 5. Fire AJAX to persist the skip
                ajaxPost('zl_update_contact_status', {
                    lead_id: lid,
                    contact_status: 'skipped',
                    contact_notes: ''
                }).catch(function () {
                    // Revert on failure — reload to get clean state
                    var leadsContainer = document.getElementById('zl-batch-leads-' + bid);
                    if (leadsContainer) {
                        leadsContainer.dataset.loaded = '';
                        loadBatchLeads(bid);
                    }
                });
            });
        }

        /* v2.0.0: Undo Skip — for cards that loaded from server as skipped */
        var unskipBtns = container.querySelectorAll('.zl-btn-unskip');
        for (var ui = 0; ui < unskipBtns.length; ui++) {
            unskipBtns[ui].addEventListener('click', function (e) {
                e.stopPropagation();
                var btn = this;
                var lid = btn.getAttribute('data-lead-id');
                var bid = btn.getAttribute('data-batch-id');

                btn.disabled = true;
                btn.textContent = 'Restoring...';

                ajaxPost('zl_update_contact_status', {
                    lead_id: lid,
                    contact_status: 'pending',
                    contact_notes: ''
                }).then(function () {
                    // Reload the batch to get fresh card rendering
                    var leadsContainer = document.getElementById('zl-batch-leads-' + bid);
                    if (leadsContainer) {
                        leadsContainer.dataset.loaded = '';
                        loadBatchLeads(bid);
                    }
                }).catch(function () {
                    btn.disabled = false;
                    btn.textContent = '↩ Undo Skip';
                });
            });
        }

        /* Collapsible toggles */
        var toggles = container.querySelectorAll('.zl-collapsible-toggle');
        for (var k = 0; k < toggles.length; k++) {
            toggles[k].addEventListener('click', function () {
                var tgt   = $(this.getAttribute('data-target'));
                var arrow = this.querySelector('.zl-collapse-arrow');
                if (!tgt) { return; }
                if (tgt.style.display === 'none') {
                    tgt.style.display = 'block';
                    if (arrow) { arrow.style.transform = 'rotate(90deg)'; }
                } else {
                    tgt.style.display = 'none';
                    if (arrow) { arrow.style.transform = 'rotate(0deg)'; }
                }
            });
        }
    }

    /* ================================================================
     *  LEAD ACTIONS
     * ================================================================ */

    /**
     * Mark a lead as "contacted" — prompts for an optional note via modal.
     * @param {string} leadId
     * @param {string} batchId
     */
    function markContacted(leadId, batchId) {
        if (isRunning) { return; }

        showModal({
            title: 'Mark as Contacted',
            message: 'Optionally add a note about this contact:',
            input: true,
            inputPlaceholder: 'Contact note (optional)',
            confirmText: 'Mark Contacted',
            cancelText: 'Cancel',
            onConfirm: function (note) {
                isRunning = true;
                disableActions();

                ajaxPost('zl_update_contact_status', {
                    lead_id: leadId,
                    contact_status: 'contacted',
                    contact_notes: note || '',
                    contact_channel: 'phone'
                })
                .then(function (resp) {
                    isRunning = false;
                    enableActions();
                    if (resp && resp.success) {
                        logMsg('Lead ' + leadId + ' marked as contacted.');
                        // Soft, non-error note about CRM propagation. With
                        // "silent retry later", a queued post is normal, not a
                        // failure — so we reassure rather than alarm.
                        var wb = resp.data && resp.data.writeback;
                        if (wb && wb.queued) {
                            logMsg('Nutshell update will sync shortly (queued).');
                        }
                        reloadBatchLeads(batchId);
                        loadStats();
                    } else {
                        logMsg('Error: ' + extractMsg(resp));
                    }
                })
                .catch(function (err) {
                    isRunning = false;
                    enableActions();
                    logMsg('Error marking contacted: ' + err.message);
                });
            }
        });
    }

    /**
     * Mark a lead as "skipped".
     * @param {string} leadId
     * @param {string} batchId
     */
    function markSkipped(leadId, batchId) {
        if (isRunning) { return; }

        showModal({
            title: 'Skip Lead',
            message: 'Are you sure you want to skip this lead?',
            confirmText: 'Skip',
            cancelText: 'Cancel',
            onConfirm: function () {
                isRunning = true;
                disableActions();

                ajaxPost('zl_update_contact_status', {
                    lead_id: leadId,
                    contact_status: 'skipped',
                    contact_notes: ''
                })
                .then(function (resp) {
                    isRunning = false;
                    enableActions();
                    if (resp && resp.success) {
                        logMsg('Lead ' + leadId + ' marked as skipped.');
                        reloadBatchLeads(batchId);
                        loadStats();
                    } else {
                        logMsg('Error: ' + extractMsg(resp));
                    }
                })
                .catch(function (err) {
                    isRunning = false;
                    enableActions();
                    logMsg('Error marking skipped: ' + err.message);
                });
            }
        });
    }

    /**
     * Force-reload the leads panel for a given batch.
     * @param {string} batchId
     */
    function reloadBatchLeads(batchId) {
        // v2.5.0 — In rep mode there are no batches; an action on a lead should
        // refresh the assigned-leads list and the banner counts instead. This
        // single branch covers every status-update call site (contacted/skip/
        // undo) without each having to know about rep mode.
        if (typeof zlWidgetData !== 'undefined' && zlWidgetData.isRepMode === true) {
            loadMyLeads();
            return;
        }
        var div = $('zl-batch-leads-' + batchId);
        if (div) {
            div.dataset.loaded = '';
            loadBatchLeads(batchId);
        }
    }

    /**
     * Extract an error message from a WP AJAX response.
     * @param {Object} resp
     * @returns {string}
     */
    function extractMsg(resp) {
        if (resp && resp.data && resp.data.message) { return resp.data.message; }
        if (resp && resp.data && typeof resp.data === 'string') { return resp.data; }
        return 'Unknown error';
    }

    /* ================================================================
     *  BATCH ACTIONS
     * ================================================================ */

    /**
     * Delete a batch after custom modal confirmation.
     * @param {string} batchId
     */
    function deleteBatch(batchId) {
        if (isRunning) { return; }

        showModal({
            title: 'Delete Batch',
            message: 'Are you sure you want to permanently delete this batch and all its leads? This action cannot be undone.',
            confirmText: 'Delete',
            cancelText: 'Cancel',
            onConfirm: function () {
                isRunning = true;
                disableActions();
                logMsg('Deleting batch ' + batchId + ' ...');

                ajaxPost('zl_delete_batch', { batch_id: batchId })
                    .then(function (resp) {
                        isRunning = false;
                        enableActions();
                        if (resp && resp.success) {
                            logMsg('Batch ' + batchId + ' deleted.');
                            loadBatches();
                            loadStats();
                        } else {
                            logMsg('Delete error: ' + extractMsg(resp));
                        }
                    })
                    .catch(function (err) {
                        isRunning = false;
                        enableActions();
                        logMsg('Delete error: ' + err.message);
                    });
            }
        });
    }

    /**
     * Send a test batch to Nutshell CRM (chunked).
     * @param {string} batchId
     */
    function sendTestToNutshell(batchId, btnEl) {
        if (isRunning) { return; }

        showModal({
            title: 'Send to Nutshell',
            message: 'This will create CRM leads in Nutshell for all leads in this test batch. Continue?',
            confirmText: 'Send',
            cancelText: 'Cancel',
            onConfirm: function () {
                isRunning      = true;
                currentBatchId = null;           // session lock: nullify first
                currentBatchId = batchId;
                // v2.1.0: busy the button in place + inline status (no reflow).
                if (btnEl) {
                    setBtnBusy(btnEl);
                    showInlineStatus(btnEl, 'Sending to CRM…', '');
                }
                disableActions();
                showProgress('Sending test batch to Nutshell...', 0);
                logMsg('Sending test batch ' + batchId + ' to Nutshell ...');
                sendTestChunk(batchId, 0, btnEl);
            }
        });
    }

    /**
     * Recursive chunk sender for test-to-Nutshell.
     * @param {string} batchId
     * @param {number} offset
     * @param {HTMLElement} [btnEl]  The originating button (for busy state).
     */
    function sendTestChunk(batchId, offset, btnEl) {
        if (currentBatchId !== batchId) {
            logMsg('Session mismatch, aborting send.');
            return;
        }

        ajaxPost('zl_send_test_to_nutshell', { batch_id: batchId, offset: offset })
            .then(function (resp) {
                if (!resp || !resp.success) {
                    logMsg('Send error: ' + extractMsg(resp));
                    isRunning = false;
                    currentBatchId = null;
                    hideProgress();
                    enableActions();
                    if (btnEl) {
                        clearBtnBusy(btnEl);
                        showInlineStatus(btnEl, 'Could not send — try again.', 'err');
                    }
                    return;
                }
                var d         = resp.data || {};
                var processed = d.processed || 0;
                var total     = d.total || 0;
                var done      = d.done || false;
                var pct       = total > 0 ? Math.round((processed / total) * 100) : 0;

                showProgress('Sending to Nutshell: ' + processed + '/' + total, pct);
                logMsg('Sent chunk: ' + processed + '/' + total + ' leads.');
                if (btnEl) {
                    showInlineStatus(btnEl, 'Sending… ' + processed + '/' + total, '');
                }

                if (done) {
                    logMsg('Test batch sent to Nutshell successfully.');
                    isRunning      = false;
                    currentBatchId = null;
                    hideProgress();
                    enableActions();
                    if (btnEl) {
                        clearBtnBusy(btnEl);
                        showInlineStatus(btnEl, 'Sent to CRM ✓', 'ok');
                    }
                    // v2.1.0: refresh stats (cheap) but defer the heavy batch
                    // re-render slightly so the user sees the success state
                    // first — avoids the abrupt full-list reflow on tap.
                    loadStats();
                    setTimeout(function () { loadBatches(); }, 1200);
                } else {
                    sendTestChunk(batchId, processed, btnEl);
                }
            })
            .catch(function (err) {
                logMsg('Send error: ' + err.message);
                isRunning      = false;
                currentBatchId = null;
                hideProgress();
                enableActions();
                if (btnEl) {
                    clearBtnBusy(btnEl);
                    showInlineStatus(btnEl, 'Could not send — try again.', 'err');
                }
            });
    }

    /* ================================================================
     *  SYNC CRM
     * ================================================================ */

    /**
     * Full CRM sync via zl_sync_nutshell.
     */
    function syncCRM() {
        if (isRunning) { return; }
        isRunning = true;
        disableActions();
        showProgress('Syncing CRM data...', 0);
        logMsg('Starting CRM sync ...');

        ajaxPost('zl_sync_nutshell', {})
            .then(function (resp) {
                isRunning = false;
                hideProgress();
                enableActions();
                if (resp && resp.success) {
                    var synced = (resp.data && resp.data.synced) || 0;
                    logMsg('CRM sync complete. ' + synced + ' records synced.');
                    loadStats();
                    loadBatches();
                } else {
                    logMsg('CRM sync error: ' + extractMsg(resp));
                }
            })
            .catch(function (err) {
                isRunning = false;
                hideProgress();
                enableActions();
                logMsg('CRM sync error: ' + err.message);
            });
    }

    /* ================================================================
     *  8-STEP GENERATION PIPELINE
     * ================================================================ */

    /**
     * Entry point for lead generation (test or full).
     *
     * Captures filter state, disables UI, shows progress, then walks
     * through the exact same 8-step pipeline as dashboard.js.
     *
     * @param {boolean} isTest
     */
    function startGeneration(isTest) {
        /* ---- re-entry guard ----------------------------------- */
        if (isRunning) {
            logMsg('Generation already in progress.');
            return;
        }
        isRunning      = true;
        currentBatchId = null;       // session lock: nullify at function top

        disableActions();
        clearLog();
        showProgress(
            isTest ? 'Starting test generation...' : 'Starting full generation...',
            0
        );
        logMsg(isTest ? 'Starting TEST generation ...' : 'Starting FULL generation ...');

        /* ---- capture filter state ----------------------------- */
        var spEl  = $('zl-filter-salesperson');
        var lbEl  = $('zl-filter-lookback');
        var pfEl  = $('zl-filter-product');
        var czEl  = $('zl-filter-city-zip');
        var smEl  = $('zl-filter-spend-min');
        var sxEl  = $('zl-filter-spend-max');
        var dmEl  = $('zl-filter-demographic');

        var filters = {
            // v2.0.0: When salesperson dropdown is absent (sales user), use the
            // auto-resolved code from zlWidgetData.spCode. This prevents the
            // "Please select a salesperson" error that occurred in v1.8.2.
            salesperson:        spEl ? spEl.value : (zlWidgetData.spCode || ''),
            lookback_days:      lbEl ? lbEl.value : '',
            product_filter:     pfEl ? pfEl.value.trim() : '',
            city_zip_filter:    czEl ? czEl.value.trim() : '',
            spend_min:          smEl ? (parseFloat(smEl.value) || 0) : 0,
            spend_max:          sxEl ? (parseFloat(sxEl.value) || 0) : 0,
            demographic_filter: dmEl ? dmEl.value : 'both',
            is_test:            isTest ? 1 : 0
        };

        logMsg('Filters: Salesperson=' + (filters.salesperson || 'All') +
               ', Lookback=' + (filters.lookback_days ? filters.lookback_days + ' days' : 'Default'));
        if (filters.product_filter) {
            logMsg('Product filter: "' + filters.product_filter + '"');
        }
        if (filters.city_zip_filter) {
            logMsg('Location filter: "' + filters.city_zip_filter + '"');
        }
        if (filters.spend_min > 0 || filters.spend_max > 0) {
            logMsg('Spend range: $' + filters.spend_min + ' — ' +
                   (filters.spend_max > 0 ? '$' + filters.spend_max : 'no limit'));
        }

        /* ---- v1.7.0: Use background generation (flush-and-continue) */
        startBackgroundGeneration(filters);
    }

    /**
     * v1.7.0 — Start background generation via zl_start_background.
     * The server creates the batch, flushes the response immediately,
     * then runs the full pipeline server-side. We poll for progress.
     *
     * @param {Object} filters
     */
    function startBackgroundGeneration(filters) {
        showProgress('Starting background generation...', 2);
        logMsg('Sending generation request to server (background mode)...');

        ajaxPost('zl_start_background', filters)
            .then(function (resp) {
                if (!resp || !resp.success) {
                    throw new Error(extractMsg(resp) || 'Failed to start background generation');
                }
                var d = resp.data || {};
                currentBatchId = d.batch_id || null;
                if (!currentBatchId) {
                    throw new Error('No batch_id returned from server');
                }
                logMsg('Batch #' + currentBatchId + ' created. Pipeline running in background.');
                logMsg('You can close this page — generation will continue on the server.');

                // Start polling for progress
                pollBatchProgress(currentBatchId);
            })
            .catch(function (err) { generationError('Background start failed: ' + err.message); });
    }

    /**
     * v1.7.0 — Poll the server for batch generation progress.
     * Polls every 3 seconds until the batch is complete or errors.
     *
     * @param {number} batchId
     */
    function pollBatchProgress(batchId) {
        // Clear any existing poll timer
        if (pollTimerId) {
            clearInterval(pollTimerId);
            pollTimerId = null;
        }

        var lastLoggedMessage = '';
        // v1.8.0: Stall banner state. The backend sends 'stalled: true' in
        // the poll response once >120s have elapsed since the last heartbeat.
        // We surface a banner but keep polling — the watchdog may clear.
        var stallBanner = null;

        function clearStallBanner() {
            if (stallBanner && stallBanner.parentNode) {
                stallBanner.parentNode.removeChild(stallBanner);
            }
            stallBanner = null;
        }
        function showStallBanner(lastHeartbeatS, warnings) {
            if (stallBanner) {
                // Refresh the content only.
                var txt = 'Generation appears stalled (no heartbeat for ' + lastHeartbeatS + 's). Still watching…';
                var msg = stallBanner.querySelector('.zl-stall-msg');
                if (msg) msg.textContent = txt;
                return;
            }
            stallBanner = document.createElement('div');
            stallBanner.className = 'zl-stall-banner';
            var msg = document.createElement('span');
            msg.className = 'zl-stall-msg';
            msg.textContent = 'Generation appears stalled (no heartbeat for ' + lastHeartbeatS + 's). Still watching…';
            stallBanner.appendChild(msg);
            if (warnings && warnings.length) {
                var detail = document.createElement('div');
                detail.className = 'zl-stall-warnings';
                detail.textContent = 'Recent warnings: ' + warnings.slice(-3).map(function (w) {
                    return w.msg || w;
                }).join(' · ');
                stallBanner.appendChild(detail);
            }
            var host = document.querySelector('.zl-widget-progress')
                || document.getElementById('zl-widget-status')
                || document.body;
            host.appendChild(stallBanner);
        }

        function doPoll() {
            ajaxPost('zl_poll_batch_progress', { batch_id: batchId })
                .then(function (resp) {
                    if (!resp || !resp.success) {
                        logMsg('Poll error: ' + extractMsg(resp));
                        return;
                    }
                    var d = resp.data || {};
                    var pct     = d.pct || 0;
                    var message = d.message || '';
                    var status  = d.status || 'unknown';
                    var step    = d.step || '';

                    // Always update the progress bar (for percentage changes)
                    showProgress(message, pct);

                    // Only log when the message actually changes (avoid spam)
                    if (message && message !== lastLoggedMessage) {
                        logMsg('[' + step + ' ' + pct + '%] ' + message);
                        lastLoggedMessage = message;
                    }

                    // v1.8.0 stall handling — surface banner, but keep polling.
                    if (d.stalled && status !== 'complete' && status !== 'error') {
                        showStallBanner(d.last_heartbeat_s || 0, d.warnings || []);
                    } else {
                        clearStallBanner();
                    }

                    if (status === 'complete') {
                        clearInterval(pollTimerId);
                        pollTimerId = null;
                        clearStallBanner();
                        generationComplete(batchId);
                    } else if (status === 'error') {
                        clearInterval(pollTimerId);
                        pollTimerId = null;
                        clearStallBanner();
                        generationError('Server pipeline error: ' + message);
                    }
                    // status === 'running' → keep polling
                })
                .catch(function (err) {
                    logMsg('Poll network error: ' + err.message + ' (will retry)');
                });
        }

        // Immediate first poll, then every 3 seconds
        doPoll();
        pollTimerId = setInterval(doPoll, 3000);
    }

    /* ---- Legacy Step 1: zl_start_batch (kept for fallback) --- */

    function step1_createBatch(filters) {
        showProgress('Step 1/8: Creating batch...', 5);
        logMsg('Step 1: Creating batch ...');

        ajaxPost('zl_start_batch', filters)
            .then(function (resp) {
                if (!resp || !resp.success) {
                    throw new Error(extractMsg(resp) || 'Failed to create batch');
                }
                var d = resp.data || {};
                currentBatchId = d.batch_id || null;
                if (!currentBatchId) {
                    throw new Error('No batch_id returned from server');
                }
                logMsg('Batch created: ' + currentBatchId);
                step2_fetchInvoices(currentBatchId, 0, filters);
            })
            .catch(function (err) { generationError('Step 1 failed: ' + err.message); });
    }

    /* ---- Step 2: zl_fetch_invoices (paginated) --------------- */

    function step2_fetchInvoices(batchId, page, filters) {
        if (currentBatchId !== batchId) {
            logMsg('Session mismatch — aborting invoice fetch.');
            return;
        }

        showProgress('Step 2/8: Fetching invoices (page ' + (page + 1) + ')...', 10);
        logMsg('Step 2: Fetching invoices, page ' + (page + 1) + ' ...');

        ajaxPost('zl_fetch_invoices', {
            batch_id:           batchId,
            page:               page,
            salesperson:        filters.salesperson || '',
            lookback_days:      filters.lookback_days || '',
            product_filter:     filters.product_filter || '',
            city_zip_filter:    filters.city_zip_filter || '',
            spend_min:          filters.spend_min || 0,
            spend_max:          filters.spend_max || 0,
            demographic_filter: filters.demographic_filter || 'both'
        })
        .then(function (resp) {
            if (!resp || !resp.success) {
                throw new Error(extractMsg(resp) || 'Invoice fetch failed');
            }
            // v1.6.0 — Fixed: field names now match the PHP response exactly.
            // Previously used d.fetched / d.total_fetched / d.has_more / d.next_page
            // which don't exist in the PHP response, causing the widget to always
            // log "Fetched 0 invoices" and never paginate past page 1.
            var d = resp.data || {};

            // Per-page progress logging (mirrors dashboard.js)
            if (d.total_pages > 1) {
                logMsg('  Page ' + d.page + '/' + d.total_pages +
                       ' — ' + d.invoice_count + ' invoices (' +
                       (d.customer_count || 0) + ' customers)');
                var pagePct = 10 + Math.round((d.page / d.total_pages) * 10);
                showProgress('Step 2/8: Fetching invoices (page ' + d.page + '/' + d.total_pages + ')...', pagePct);
            } else {
                logMsg('Fetched ' + (d.page_invoice_count || 0) + ' invoices (' +
                       (d.customer_count || 0) + ' customers)');
            }

            if (!d.done) {
                // More pages to fetch — continue with next page
                step2_fetchInvoices(batchId, d.page + 1, filters);
            } else {
                logMsg('Invoice fetch complete. Total: ' + d.invoice_count +
                       ' invoices across ' + (d.customer_count || 0) + ' customers');
                if (d.total_pages > 1) {
                    logMsg('  (Fetched across ' + d.total_pages + ' pages)');
                }
                step2b_expandFilter(batchId);
            }
        })
        .catch(function (err) { generationError('Step 2 failed: ' + err.message); });
    }

    /* ---- Step 2b: zl_expand_filter (AI) ---------------------- */

    function step2b_expandFilter(batchId) {
        if (currentBatchId !== batchId) {
            logMsg('Session mismatch — aborting filter expansion.');
            return;
        }

        showProgress('Step 2b/8: AI filter expansion...', 20);
        logMsg('Step 2b: AI filter expansion ...');

        ajaxPost('zl_expand_filter', { batch_id: batchId })
            .then(function (resp) {
                if (!resp || !resp.success) {
                    logMsg('Warning: ' + (extractMsg(resp) || 'Filter expansion issue') +
                           ' (continuing)');
                } else {
                    var d = resp.data || {};
                    if (d.expanded) {
                        logMsg('Filter expanded: ' + (d.expanded_count || 0) + ' additional terms.');
                    } else {
                        logMsg('No filter expansion applied.');
                    }
                }
                step3_enrich(batchId, 0, 0);
            })
            .catch(function (err) {
                logMsg('Warning: filter expansion error (' + err.message + '), continuing ...');
                step3_enrich(batchId, 0, 0);
            });
    }

    /* ---- Step 3: zl_enrich_chunk (chunked) ------------------- */

    // v1.6.0 — Cumulative skip counters across chunks (mirrors dashboard.js).
    var _enrichSkips = { product: 0, territory: 0, cooldown: 0, enrich: 0, cityzip: 0, spend: 0, errors: 0 };

    function step3_enrich(batchId, offset, enrichedSoFar) {
        if (currentBatchId !== batchId) {
            logMsg('Session mismatch — aborting enrichment.');
            return;
        }

        showProgress('Step 3/8: Enriching data (chunk ' + (offset + 1) + ')...', 30);
        logMsg('Step 3: Enrichment chunk, offset=' + offset + ' ...');

        ajaxPost('zl_enrich_chunk', { batch_id: batchId, offset: offset })
            .then(function (resp) {
                if (!resp || !resp.success) {
                    throw new Error(extractMsg(resp) || 'Enrichment failed');
                }
                // v1.6.0 — Fixed: field names now match the PHP response exactly.
                var d             = resp.data || {};
                var chunkDone     = d.enriched || 0;
                var totalEnriched = enrichedSoFar + chunkDone;
                var done          = d.done || false;
                var nextOffset    = d.next_offset !== undefined ? d.next_offset : offset + 1;
                var total         = d.total || 0;
                var processed     = d.processed || 0;
                var candidates    = d.candidate_count || 0;
                var errors        = d.errors || 0;

                // Accumulate per-filter skip counters across chunks
                _enrichSkips.product   += d.skip_product   || 0;
                _enrichSkips.territory += d.skip_territory  || 0;
                _enrichSkips.cooldown  += d.skip_cooldown   || 0;
                _enrichSkips.enrich    += d.skip_enrich     || 0;
                _enrichSkips.cityzip   += d.skip_cityzip    || 0;
                _enrichSkips.spend     += d.skip_spend      || 0;
                _enrichSkips.errors    += errors;

                // Use processed/total for progress (not enriched/total)
                var pct = total > 0 ? Math.round(30 + (processed / total) * 15) : 35;
                showProgress('Step 3/8: Enriching (' + processed + '/' + total + ', ' + candidates + ' candidates)...', pct);
                logMsg('Processed: ' + processed + '/' + total +
                       ' | Candidates: ' + candidates +
                       ' | Chunk enriched: ' + chunkDone +
                       (errors > 0 ? ' | Errors: ' + errors : ''));

                if (done) {
                    // Log final summary with per-filter breakdown
                    var skipParts = [];
                    if (_enrichSkips.product   > 0) skipParts.push('product filter: '   + _enrichSkips.product);
                    if (_enrichSkips.territory > 0) skipParts.push('territory: '         + _enrichSkips.territory);
                    if (_enrichSkips.cooldown  > 0) skipParts.push('cooldown: '          + _enrichSkips.cooldown);
                    if (_enrichSkips.enrich    > 0) skipParts.push('excluded/commercial: ' + _enrichSkips.enrich);
                    if (_enrichSkips.cityzip   > 0) skipParts.push('city/zip: '          + _enrichSkips.cityzip);
                    if (_enrichSkips.spend     > 0) skipParts.push('spend: '             + _enrichSkips.spend);
                    logMsg('Enrichment complete. ' + candidates + ' candidates from ' + total + ' customers.');
                    if (skipParts.length > 0) {
                        logMsg('  Skipped — ' + skipParts.join(', '));
                    }
                    if (d.early_stopped) {
                        logMsg('  (Early-stopped: enough candidates found)');
                    }
                    if (_enrichSkips.errors > 0) {
                        logMsg('  Errors: ' + _enrichSkips.errors);
                    }
                    // Reset for next batch
                    _enrichSkips = { product: 0, territory: 0, cooldown: 0, enrich: 0, cityzip: 0, spend: 0, errors: 0 };
                    step4_selectLeads(batchId);
                } else {
                    step3_enrich(batchId, nextOffset, totalEnriched);
                }
            })
            .catch(function (err) { generationError('Step 3 failed: ' + err.message); });
    }

    /* ---- Step 4: zl_select_leads ----------------------------- */

    function step4_selectLeads(batchId) {
        if (currentBatchId !== batchId) {
            logMsg('Session mismatch — aborting selection.');
            return;
        }

        showProgress('Step 4/8: Scoring and selecting leads...', 50);
        logMsg('Step 4: Scoring and selecting leads ...');

        ajaxPost('zl_select_leads', { batch_id: batchId })
            .then(function (resp) {
                if (!resp || !resp.success) {
                    throw new Error(extractMsg(resp) || 'Lead selection failed');
                }
                // v1.6.0 — Fixed: field names now match the PHP response exactly.
                var d          = resp.data || {};
                var leadCount  = d.lead_count || 0;
                var candidates = d.total_candidates || 0;
                logMsg('Selected ' + leadCount + ' leads from ' + candidates + ' candidates.');
                step4_5_aiValidate(batchId);
            })
            .catch(function (err) { generationError('Step 4 failed: ' + err.message); });
    }

    /* ---- Step 4.5: zl_ai_validate (AI) ----------------------- */

    function step4_5_aiValidate(batchId) {
        if (currentBatchId !== batchId) {
            logMsg('Session mismatch — aborting validation.');
            return;
        }

        showProgress('Step 4.5/8: AI validation...', 60);
        logMsg('Step 4.5: AI strict validation ...');

        ajaxPost('zl_ai_validate', { batch_id: batchId })
            .then(function (resp) {
                if (!resp || !resp.success) {
                    logMsg('Warning: ' + (extractMsg(resp) || 'AI validation issue') +
                           ' (continuing)');
                } else {
                    // v1.6.0 — Fixed: field names now match the PHP response exactly.
                    var d        = resp.data || {};
                    if (d.skipped) {
                        logMsg('AI validation skipped: ' + (d.reason || 'N/A'));
                    } else {
                        var passed   = d.passed || 0;
                        var rejected = d.rejected || 0;
                        var trimmed  = d.trimmed || 0;
                        var final_ct = d.final_count || 0;
                        logMsg('AI validation: ' + passed + ' passed, ' + rejected + ' rejected.' +
                               (trimmed > 0 ? ' Trimmed ' + trimmed + ' excess.' : '') +
                               ' Final: ' + final_ct + ' leads.');
                    }
                }
                step5_aiRefine(batchId, 0);
            })
            .catch(function (err) {
                logMsg('Warning: AI validation error (' + err.message + '), continuing ...');
                step5_aiRefine(batchId, 0);
            });
    }

    /* ---- Step 5: zl_ai_refine (AI, chunked) ------------------ */

    function step5_aiRefine(batchId, offset) {
        if (currentBatchId !== batchId) {
            logMsg('Session mismatch — aborting refinement.');
            return;
        }

        showProgress('Step 5/8: AI refinement...', 70);
        if (offset === 0) {
            logMsg('Step 5: AI description refinement ...');
        }

        // v1.6.0 — Fixed: send offset and handle chunking like dashboard.js.
        // PHP processes 10 leads per chunk and returns done + next_offset.
        ajaxPost('zl_ai_refine', { batch_id: batchId, offset: offset })
            .then(function (resp) {
                if (!resp || !resp.success) {
                    logMsg('Warning: ' + (extractMsg(resp) || 'AI refinement issue') +
                           ' (continuing)');
                    step6_createNutshell(batchId, 0);
                } else {
                    var d      = resp.data || {};
                    var count  = d.refined || 0;
                    var done   = d.done !== undefined ? d.done : true;
                    var next   = d.next_offset || 0;
                    if (count > 0) {
                        logMsg('Refined ' + count + ' lead descriptions (offset ' + offset + ').');
                    }
                    if (!done && next > offset) {
                        step5_aiRefine(batchId, next);
                    } else {
                        logMsg('AI refinement complete.');
                        step6_createNutshell(batchId, 0);
                    }
                }
            })
            .catch(function (err) {
                logMsg('Warning: AI refinement error (' + err.message + '), continuing ...');
                step6_createNutshell(batchId, 0);
            });
    }

    /* ---- Step 6: zl_create_nutshell (chunked) ---------------- */

    var _crmCreatedTotal = 0; // Cumulative created count across chunks

    function step6_createNutshell(batchId, offset) {
        if (currentBatchId !== batchId) {
            logMsg('Session mismatch — aborting CRM creation.');
            return;
        }

        if (offset === 0) {
            _crmCreatedTotal = 0; // Reset on first call
        }

        showProgress('Step 6/8: Creating CRM leads...', 80);
        logMsg('Step 6: Creating CRM leads, chunk ' + (Math.floor(offset / 5) + 1) + ' ...');

        ajaxPost('zl_create_nutshell', { batch_id: batchId, offset: offset })
            .then(function (resp) {
                if (!resp || !resp.success) {
                    throw new Error(extractMsg(resp) || 'CRM creation failed');
                }
                // v1.6.0 — Fixed: field names now match the PHP response exactly.
                // PHP returns: created, done, next_offset (always 0 since it queries uncreated leads).
                var d          = resp.data || {};
                var created    = d.created || 0;
                var done       = d.done || false;
                var nextOffset = d.next_offset !== undefined ? d.next_offset : 0;

                _crmCreatedTotal += created;

                showProgress('Step 6/8: CRM leads (' + _crmCreatedTotal + ' created)...', 85);
                logMsg('CRM leads created this chunk: ' + created + ' (total: ' + _crmCreatedTotal + ')');

                if (done) {
                    logMsg('CRM lead creation complete. Total created: ' + _crmCreatedTotal);
                    step7_finalize(batchId);
                } else {
                    step6_createNutshell(batchId, nextOffset);
                }
            })
            .catch(function (err) { generationError('Step 6 failed: ' + err.message); });
    }

    /* ---- Step 7: zl_finalize (AI summary) -------------------- */

    function step7_finalize(batchId) {
        if (currentBatchId !== batchId) {
            logMsg('Session mismatch — aborting finalization.');
            return;
        }

        showProgress('Step 7/8: Finalizing...', 95);
        logMsg('Step 7: Finalizing batch with AI summary ...');

        ajaxPost('zl_finalize', { batch_id: batchId })
            .then(function (resp) {
                if (!resp || !resp.success) {
                    logMsg('Warning: ' + (extractMsg(resp) || 'Finalization issue'));
                } else {
                    var d = resp.data || {};
                    if (d.lead_count) { logMsg('Final lead count: ' + d.lead_count); }
                    if (d.summary) { logMsg('AI Summary: ' + d.summary); }
                }
                generationComplete(batchId);
            })
            .catch(function (err) {
                logMsg('Warning: finalization error (' + err.message + ')');
                generationComplete(batchId);
            });
    }

    /* ---- completion / error ----------------------------------- */

    function generationComplete(batchId) {
        // v1.7.0 — Clear poll timer if active
        if (pollTimerId) {
            clearInterval(pollTimerId);
            pollTimerId = null;
        }
        showProgress('Generation complete!', 100);
        logMsg('Generation complete for batch ' + batchId + '.');
        isRunning      = false;
        currentBatchId = null;
        enableActions();
        loadStats();
        loadBatches();
    }

    function generationError(message) {
        // v1.7.0 — Clear poll timer if active
        if (pollTimerId) {
            clearInterval(pollTimerId);
            pollTimerId = null;
        }
        logMsg('ERROR: ' + message);
        showProgress('Error — see log', 0);
        isRunning      = false;
        currentBatchId = null;
        enableActions();
    }

    /* ================================================================
     *  PERMISSION GATING
     * ================================================================ */

    /**
     * Show or hide UI elements based on the user's feature array.
     */
    function applyPermissions() {
        // v2.0.0: Filter panel visibility is now controlled by PHP (admin-only fields
        // excluded from DOM for sales users). JS only hides generation buttons when
        // the user lacks the specific permission.
        toggleEl('zl-btn-test',      hasPerm('can_generate_test'));
        toggleEl('zl-btn-full',      hasPerm('can_generate_full'));
        toggleEl('zl-btn-sync',      hasPerm('can_sync_nutshell'));
        toggleEl('zl-batch-section', hasPerm('view_batch_history'));

        // v2.0.0: Only hide the generation panel body if user has NO generation perms at all
        if (!hasPerm('can_generate_test') && !hasPerm('can_generate_full')) {
            toggleEl('zl-panel-generate', false);
        }

        // v2.0.0 — Apply sales view styling adjustments
        if (isSalesView()) {
            applySalesView();
        }
    }

    /**
     * v2.0.0 — Sales view adjustments.
     *
     * Sales users now SEE the generation panel (collapsed) and CAN generate
     * leads within their territory. Only the section title changes.
     */
    function applySalesView() {
        // v2.5.0 — In rep mode the list is the flat assigned-leads view (no batch
        // accordions), and applyRepMode() owns the title + loading. Skip the
        // legacy batch auto-expand so the two paths don't fight.
        if (typeof zlWidgetData !== 'undefined' && zlWidgetData.isRepMode === true) {
            return;
        }

        // Rename section title
        var sectionTitle = $('zl-batch-section-title');
        if (sectionTitle) {
            sectionTitle.textContent = 'My Leads';
        }

        // Auto-expand the first (most recent) batch after a short delay
        setTimeout(function () {
            var firstHeader = document.querySelector('.zl-batch-header');
            if (firstHeader) {
                var bid = firstHeader.getAttribute('data-batch-id');
                if (bid) {
                    toggleBatchLeads(bid, firstHeader);
                }
            }
        }, 1500);
    }

    /**
     * Toggle an element's display based on a boolean.
     * @param {string}  id
     * @param {boolean} visible
     */
    function toggleEl(id, visible) {
        var el = $(id);
        if (!el) { return; }
        el.style.display = visible ? '' : 'none';
    }

    /* ================================================================
     *  DROPDOWN POPULATION
     * ================================================================ */

    /**
     * Populate the salesperson select from zlWidgetData.salespeople.
     */
    function populateSalespersonDropdown() {
        var sel = $('zl-filter-salesperson');
        if (!sel) { return; }

        // v2.0.0: Admin sees "All Salespeople" (value='all'), individual salespeople.
        // Non-admin users don't see this dropdown (excluded from DOM by PHP).
        sel.innerHTML = '<option value="all">All Salespeople</option>';

        if (typeof zlWidgetData === 'undefined' || !zlWidgetData.salespeople) { return; }
        var list = zlWidgetData.salespeople;
        for (var i = 0; i < list.length; i++) {
            var opt   = document.createElement('option');
            opt.value = list[i].code || '';
            var label = list[i].name || list[i].code || '';
            if (list[i].territories) { label += ' (' + list[i].territories + ')'; }
            opt.textContent = label;
            sel.appendChild(opt);
        }

        // v2.0.0: If user has a resolved salesperson code, pre-select it.
        // v2.6.0: ONLY when that code actually exists in the roster —
        // assigning a value with no matching <option> left the select BLANK
        // (selectedIndex -1), which is how the dashboard shipped an empty
        // Salesperson box. Unlinked reps now fall back to "All Salespeople"
        // and see an explanatory hint instead.
        var spCode  = zlWidgetData.spCode || '';
        var hasCode = false;
        if (spCode) {
            for (var oi = 0; oi < sel.options.length; oi++) {
                if (sel.options[oi].value === spCode) { hasCode = true; break; }
            }
        }
        sel.value = hasCode ? spCode : 'all';
        var unlinkedHint = document.getElementById('zl-sp-unlinked-hint');
        if (unlinkedHint) unlinkedHint.hidden = !(spCode && !hasCode);
    }

    /**
     * Populate the lookback select from zlWidgetData.lookback.
     */
    function populateLookbackDropdown() {
        var sel = $('zl-filter-lookback');
        if (!sel) { return; }

        sel.innerHTML = '<option value="">Default</option>';

        if (typeof zlWidgetData === 'undefined' || !zlWidgetData.lookback) { return; }
        var list = zlWidgetData.lookback;
        for (var i = 0; i < list.length; i++) {
            var opt   = document.createElement('option');
            opt.value = list[i].value !== undefined ? list[i].value : '';
            opt.textContent = list[i].label || String(list[i].value) || '';
            sel.appendChild(opt);
        }
    }

    /* ================================================================
     *  EVENT BINDING
     * ================================================================ */

    /**
     * Attach all top-level widget event listeners.
     */
    function bindWidgetEvents() {

        /* Test generation */
        var testBtn = $('zl-btn-test');
        if (testBtn) {
            testBtn.addEventListener('click', function () {
                if (isRunning) { return; }
                startGeneration(true);
            });
        }

        /* Full generation (confirm first) */
        var fullBtn = $('zl-btn-full');
        if (fullBtn) {
            fullBtn.addEventListener('click', function () {
                if (isRunning) { return; }
                showModal({
                    title: 'Full Generation',
                    message: 'This will run a full lead generation batch. ' +
                             'This may take several minutes. Continue?',
                    confirmText: 'Generate',
                    cancelText: 'Cancel',
                    onConfirm: function () { startGeneration(false); }
                });
            });
        }

        /* Sync CRM */
        var syncBtn = $('zl-btn-sync');
        if (syncBtn) {
            syncBtn.addEventListener('click', function () {
                if (isRunning) { return; }
                syncCRM();
            });
        }

        /* Refresh */
        var refreshBtn = $('zl-btn-refresh');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () {
                loadStats();
                if (hasPerm('view_batch_history')) { loadBatches(); }
            });
        }

        /* Clear log */
        var clearBtn = $('zl-btn-clear-log');
        if (clearBtn) {
            clearBtn.addEventListener('click', clearLog);
        }

        /* Admin link */
        var adminLink = $('zl-admin-link');
        if (adminLink && typeof zlWidgetData !== 'undefined' && zlWidgetData.adminUrl) {
            adminLink.href = zlWidgetData.adminUrl;
        }

        /* v2.1.0: "Ready to Email" tile → jump to the leads list and open
           the most recent batch (where actionable leads live). Works for
           both click and keyboard (Enter/Space) since it's role="button". */
        var readyTile = $('zl-stat-tile-ready');
        if (readyTile) {
            var activateReady = function () {
                var section = $('zl-batch-section-title') || $('zl-batch-list');
                // Expand the newest batch if it's collapsed.
                var firstHeader = document.querySelector('.zl-batch-header');
                if (firstHeader && !firstHeader.classList.contains('is-open')) {
                    var bid = firstHeader.getAttribute('data-batch-id');
                    if (bid) { toggleBatchLeads(bid, firstHeader); }
                }
                // Scroll the leads area into view (honors scroll-margin-top).
                var scrollTarget = firstHeader ||
                    document.querySelector('.zl-w-panel') || section;
                if (scrollTarget && scrollTarget.scrollIntoView) {
                    try {
                        scrollTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    } catch (e) { scrollTarget.scrollIntoView(true); }
                }
            };
            readyTile.addEventListener('click', activateReady);
            readyTile.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                    e.preventDefault();
                    activateReady();
                }
            });
        }
    }

    /* ================================================================
     *  INIT
     * ================================================================ */

    /**
     * Main initialization routine.
     *
     * Guarded by the initDone boolean so it only ever runs once,
     * regardless of how many triggers fire.
     */
    function init() {
        if (initDone) { return; }

        /* prerequisite: localized data must exist */
        if (typeof zlWidgetData === 'undefined') { return; }

        /* prerequisite: widget container must be in the DOM */
        if (!$('zl-widget-wrap')) { return; }

        initDone = true;

        /* populate dropdowns */
        populateSalespersonDropdown();
        populateLookbackDropdown();

        /* permission gating */
        applyPermissions();

        /* bind events */
        bindWidgetEvents();

        /* v2.5.0 — Rep mode (Phase 2): a salesperson sees ONLY their assigned
           leads and NOT the generate/batch machinery. Determined server-side
           (zlWidgetData.isRepMode is authoritative). We hide the generate +
           batch sections entirely and load the assigned-leads list instead. */
        var repMode = (typeof zlWidgetData !== 'undefined' && zlWidgetData.isRepMode === true);
        if (repMode) {
            applyRepMode();
        }

        /* initial data loads */
        loadStats();
        if (repMode) {
            loadMyLeads();                 // assigned-leads list (rep mode)
        } else if (hasPerm('view_batch_history')) {
            loadBatches();                 // batch history (admin/operator)
        }

        /* version badge */
        var verEl = $('zl-version');
        if (verEl && zlWidgetData.version) {
            verEl.textContent = 'v' + zlWidgetData.version;
        }

        logMsg('Widget initialized (v' + (zlWidgetData.version || '?') + ')');
    }

    /* ================================================================
     *  THREE-TIER INIT PATTERN
     *
     *  1. Immediate DOM check  — widget may already be rendered
     *  2. zdz_widgets_rendered  — Zorderz SPA custom event
     *  3. DOMContentLoaded     — standard page-load fallback
     * ================================================================ */

    // Tier 1: immediate
    init();

    // Tier 2: SPA event
    document.addEventListener('zdz_widgets_rendered', function () {
        init();
    });

    // Tier 3: DOMContentLoaded (only if document is still loading)
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init();
        });
    } else {
        // DOM already ready — attempt again to cover edge-case race
        init();
    }

    /* ================================================================
     *  v2.1.0: OPEN-TO-TOP ON DOCK LAUNCH
     *
     *  When the Leads dock icon is tapped, the theme's bridge.js
     *  dispatches a cancelable `zdz_app_launch` event (detail.appId)
     *  and then — for inline_widget apps that don't claim it — runs
     *  `setTimeout(150)` and scrolls `.dash-widget-container[data-app-id]`
     *  into view with `block:'center'`. Because this widget is tall,
     *  centering drops the user into the MIDDLE of it (the reported
     *  "boots up midway through" bug).
     *
     *  We listen for `zdz_app_launch`, and when WE are the target we
     *  re-scroll the SAME container to its TOP, honoring the sticky
     *  app-bar offset via the `scroll-margin-top` already defined in
     *  CSS. We do NOT call preventDefault() — the theme's view switch
     *  (switchView('sv-dash')) and its highlight box-shadow must still
     *  run; we only override the final scroll POSITION.
     *
     *  Timing: the theme scrolls inside setTimeout(150). We wait a bit
     *  longer (220ms) so our 'start' scroll lands AFTER the theme's
     *  'center' scroll and therefore wins. The app id is 'leads'
     *  (see ZL_App::get_config). Matcher stays defensive across
     *  possible detail key names / future slug changes.
     * ================================================================ */
    var ZL_APP_ID = 'leads';

    function tslIsLaunchTarget(detail) {
        if (!detail) { return false; }
        var slug = detail.appId || detail.app || detail.id || detail.slug || '';
        slug = String(slug).toLowerCase();
        return slug === ZL_APP_ID || slug === 'zorderz' ||
               slug === 'sales-leads' || slug === 'leads' || slug === 'tsl';
    }

    function tslScrollToTop() {
        // Match the exact element the theme scrolls + highlights so the
        // scroll target and the green highlight ring agree.
        var target =
            document.querySelector('.dash-widget-container[data-app-id="' + ZL_APP_ID + '"]') ||
            document.querySelector('.dash-widget-container .zl-w') ||
            document.getElementById('zl-widget-wrap');
        if (!target) { return; }
        // Climb to the widget container if we matched the inner wrap.
        if (target.id === 'zl-widget-wrap') {
            target = target.closest('.dash-widget-container') || target;
        }
        // Fire AFTER the theme's setTimeout(150) center-scroll so ours wins.
        setTimeout(function () {
            try {
                target.scrollIntoView({ behavior: 'smooth', block: 'start', inline: 'nearest' });
            } catch (e) {
                target.scrollIntoView(true); // older engines
            }
        }, 220);
    }

    document.addEventListener('zdz_app_launch', function (ev) {
        if (tslIsLaunchTarget(ev && ev.detail)) {
            tslScrollToTop();
            // v2.5.0 — the dashboard "N new leads" tile launches us with
            // { view: 'my-leads' }. When asked, make sure rep mode is applied
            // and the assigned-leads list is loaded/focused so the rep lands
            // directly on their actionable leads (not a stale/empty view).
            var detail = (ev && ev.detail) || {};
            var view   = detail.view || (detail.options && detail.options.view) || '';
            if (String(view) === 'my-leads' &&
                typeof zlWidgetData !== 'undefined' && zlWidgetData.isRepMode === true) {
                // init() may not have run yet on a cold SPA open; ensure it has.
                init();
                applyRepMode();
                loadMyLeads();
                tslFocusLeadList();
            }
        }
    });

    /* ================================================================
     *  v2.0.0: ACCORDION PANEL TOGGLES
     * ================================================================ */

    function initPanelToggles() {
        var panels = document.querySelectorAll('.zl-w-panel-header');
        for (var i = 0; i < panels.length; i++) {
            panels[i].addEventListener('click', function () {
                var panel = this.closest('.zl-w-panel');
                if (!panel) return;
                panel.classList.toggle('collapsed');
                panel.classList.toggle('expanded');
            });
        }
    }

    /* ================================================================
     *  v2.0.0: NUTSHELL NOTES FETCHING
     * ================================================================ */

    function loadLeadNotes(leadId, container) {
        container.innerHTML = '<div class="zl-w-loading" style="font-size:12px;">Loading notes…</div>';
        ajaxPost('zl_get_lead_notes', { lead_id: leadId })
            .then(function (resp) {
                if (!resp || !resp.success) {
                    container.innerHTML = '<em style="font-size:12px;color:#6b7280;">Could not load notes.</em>';
                    return;
                }
                var notes = resp.data.notes || [];
                var localNotes = resp.data.local_notes || [];
                var html = '';
                // Detail meta line
                var nsLead = resp.data.ns_lead_id || '';
                if (nsLead) {
                    html += '<div class="zl-notes-meta"><strong>NS Lead:</strong> #' + escHtml(nsLead) + '</div>';
                }
                // Nutshell timeline notes
                if (notes.length > 0) {
                    for (var i = 0; i < notes.length; i++) {
                        var n = notes[i];
                        var dateStr = n.date ? escHtml(n.date.substring(0, 10)) : '';
                        var author = n.author ? escHtml(n.author) : '';
                        html += '<div style="padding:4px 0;border-bottom:1px solid var(--ref-gray-100,#f3f4f6);font-size:12px;">';
                        if (dateStr) html += '<strong>' + dateStr + '</strong> ';
                        if (author) html += '<span style="color:#6b7280;">' + author + '</span> ';
                        html += escHtml(n.text || '');
                        html += '</div>';
                    }
                } else {
                    html += '<em style="font-size:12px;color:#6b7280;">No Nutshell notes yet.</em>';
                }
                // Local notes
                if (localNotes.length > 0) {
                    for (var j = 0; j < localNotes.length; j++) {
                        var ln = localNotes[j];
                        html += '<div style="padding:4px 0;font-size:12px;color:#6b7280;">';
                        html += '<strong>' + escHtml(ln.author || 'Note') + ':</strong> ' + escHtml(ln.text || '');
                        html += '</div>';
                    }
                }
                container.innerHTML = html;
            })
            .catch(function () {
                container.innerHTML = '<em style="font-size:12px;color:#ef4444;">Error loading notes.</em>';
            });
    }

    /* ================================================================
     *  v2.0.0: FORWARD-TO-TEAM
     * ================================================================ */

    var _teamMembersCache = null;

    function loadTeamMembers(selectEl) {
        if (_teamMembersCache) {
            populateTeamSelect(selectEl, _teamMembersCache);
            return;
        }
        selectEl.innerHTML = '<option value="">Loading team…</option>';
        ajaxPost('zl_get_team_members', {})
            .then(function (resp) {
                if (resp && resp.success && Array.isArray(resp.data)) {
                    _teamMembersCache = resp.data;
                    populateTeamSelect(selectEl, resp.data);
                } else {
                    /* v2.6.0: a non-success response used to leave the select
                     * stuck on "Loading team…" forever. */
                    teamLoadFailed(selectEl);
                }
            })
            .catch(function () { teamLoadFailed(selectEl); });
    }

    /* v2.6.0: visible failure + one-tap retry for the team loader */
    function teamLoadFailed(selectEl) {
        selectEl.innerHTML = '<option value="">Couldn\u2019t load team — tap to retry</option>';
        var retry = function () {
            selectEl.removeEventListener('mousedown', retry);
            selectEl.removeEventListener('focus', retry);
            _teamMembersCache = null;
            loadTeamMembers(selectEl);
        };
        selectEl.addEventListener('mousedown', retry);
        selectEl.addEventListener('focus', retry);
    }

    function populateTeamSelect(selectEl, members) {
        var html = '<option value="">Select team member…</option>';
        for (var i = 0; i < members.length; i++) {
            var m = members[i];
            html += '<option value="' + m.id + '">' + escHtml(m.name) + '</option>';
        }
        selectEl.innerHTML = html;
    }

    function submitForward(leadId, recipientId, noteText, isTask, successCb) {
        ajaxPost('zl_forward_note', {
            lead_id: leadId,
            recipient_id: recipientId,
            note_text: noteText,
            is_task: isTask ? 1 : 0
        }).then(function (resp) {
            if (resp && resp.success) {
                if (successCb) successCb(resp.data);
            } else {
                alert('Forward failed: ' + (resp && resp.data ? resp.data : 'Unknown error'));
            }
        }).catch(function (err) {
            alert('Forward failed: ' + err.message);
        });
    }

    function loadForwardHistory(leadId, container) {
        ajaxPost('zl_get_forwards', { lead_id: leadId })
            .then(function (resp) {
                if (!resp || !resp.success || !resp.data || resp.data.length === 0) {
                    container.innerHTML = '';
                    return;
                }
                var html = '<div class="zl-fwd-history-title">📨 Forward History (' + resp.data.length + ')</div>';
                for (var i = 0; i < resp.data.length; i++) {
                    var f = resp.data[i];
                    html += '<div class="zl-fwd-history-item">';
                    html += '→ ' + escHtml(f.recipient_name || 'Unknown') + ' on ' + escHtml((f.created_at || '').substring(0, 10));
                    html += ' <span style="color:' + (f.status === 'completed' ? '#10B981' : '#f59e0b') + ';">[' + escHtml(f.status) + ']</span>';
                    if (f.status === 'pending' && f.is_task) {
                        html += ' <button class="zl-fwd-complete-btn" data-forward-id="' + f.id + '" style="font-size:11px;padding:2px 6px;cursor:pointer;">Mark Complete</button>';
                    }
                    html += '</div>';
                }
                container.innerHTML = html;

                // Bind complete buttons
                var completeBtns = container.querySelectorAll('.zl-fwd-complete-btn');
                for (var j = 0; j < completeBtns.length; j++) {
                    completeBtns[j].addEventListener('click', function () {
                        var fwdId = this.getAttribute('data-forward-id');
                        ajaxPost('zl_mark_forward_complete', { forward_id: fwdId })
                            .then(function () { loadForwardHistory(leadId, container); });
                    });
                }
            });
    }

    /* ================================================================
     *  v2.0.0: ENHANCED LEAD CARD EVENTS (notes, forward, pipeline)
     * ================================================================ */

    // Override bindLeadEvents to add notes toggle and forward triggers
    var _origBindLeadEvents = typeof bindLeadEvents === 'function' ? bindLeadEvents : null;

    function bindLeadEventsV2(container) {
        // Call original if it exists
        if (_origBindLeadEvents) {
            _origBindLeadEvents(container);
        }

        // Notes toggle (tap "📋 Tap for details & notes")
        var noteHints = container.querySelectorAll('.zl-notes-hint');
        for (var i = 0; i < noteHints.length; i++) {
            noteHints[i].addEventListener('click', function (e) {
                e.stopPropagation();
                var leadId = this.getAttribute('data-lead-id');
                var panel = container.querySelector('.zl-notes-panel[data-lead-id="' + leadId + '"]');
                if (!panel) return;
                if (panel.classList.contains('open')) {
                    panel.classList.remove('open');
                } else {
                    panel.classList.add('open');
                    if (!panel.dataset.loaded) {
                        var notesBody = panel.querySelector('.zl-notes-body');
                        if (notesBody) {
                            loadLeadNotes(leadId, notesBody);
                            panel.dataset.loaded = '1';
                        }
                    }
                }
            });
        }

        // Forward Note triggers
        var fwdTriggers = container.querySelectorAll('.zl-fwd-trigger');
        for (var j = 0; j < fwdTriggers.length; j++) {
            fwdTriggers[j].addEventListener('click', function (e) {
                e.stopPropagation();
                var leadId = this.getAttribute('data-lead-id');
                var form = container.querySelector('.zl-fwd-form[data-lead-id="' + leadId + '"]');
                if (!form) return;
                form.style.display = form.style.display === 'none' ? 'block' : 'none';
                if (form.style.display === 'block') {
                    var sel = form.querySelector('.zl-fwd-recipient');
                    if (sel && sel.options.length <= 1) {
                        loadTeamMembers(sel);
                    }
                    // Load forward history
                    var historyEl = container.querySelector('.zl-fwd-history[data-lead-id="' + leadId + '"]');
                    if (historyEl) {
                        loadForwardHistory(leadId, historyEl);
                    }
                }
            });
        }

        // Forward Send buttons
        var fwdSends = container.querySelectorAll('.zl-fwd-send');
        for (var k = 0; k < fwdSends.length; k++) {
            fwdSends[k].addEventListener('click', function (e) {
                e.stopPropagation();
                var leadId = this.getAttribute('data-lead-id');
                var form = this.closest('.zl-fwd-form');
                if (!form) return;
                var sel = form.querySelector('.zl-fwd-recipient');
                var taskChk = form.querySelector('.zl-fwd-task-check');
                var previewEl = form.querySelector('.zl-fwd-preview');
                var recipientId = sel ? sel.value : '';
                var noteText = previewEl ? previewEl.textContent : '';
                var isTask = taskChk ? taskChk.checked : false;
                if (!recipientId) return;
                submitForward(leadId, recipientId, noteText, isTask, function (data) {
                    var bar = form.previousElementSibling; // .zl-fwd-bar
                    if (bar) {
                        bar.innerHTML = '<span style="color:#10B981;font-size:12px;">✅ Forwarded to ' + escHtml(data.recipient_name || 'team member') + '</span>';
                    }
                    form.style.display = 'none';
                });
            });
        }

        // Forward Cancel buttons
        var fwdCancels = container.querySelectorAll('.zl-fwd-cancel');
        for (var m = 0; m < fwdCancels.length; m++) {
            fwdCancels[m].addEventListener('click', function (e) {
                e.stopPropagation();
                var form = this.closest('.zl-fwd-form');
                if (form) form.style.display = 'none';
            });
        }

        // Forward recipient change → enable send
        var fwdRecipients = container.querySelectorAll('.zl-fwd-recipient');
        for (var n = 0; n < fwdRecipients.length; n++) {
            fwdRecipients[n].addEventListener('change', function () {
                var form = this.closest('.zl-fwd-form');
                if (!form) return;
                var sendBtn = form.querySelector('.zl-fwd-send');
                if (sendBtn) sendBtn.disabled = !this.value;
            });
        }

        // AI Summary toggles
        var aiToggles = container.querySelectorAll('.zl-ai-toggle');
        for (var p = 0; p < aiToggles.length; p++) {
            aiToggles[p].addEventListener('click', function (e) {
                e.stopPropagation();
                var targetId = this.getAttribute('data-target');
                var target = document.getElementById(targetId);
                if (target) {
                    target.classList.toggle('open');
                    this.classList.toggle('open');
                }
            });
        }
    }

    // Monkey-patch bindLeadEvents to use v2.0.0 version
    bindLeadEvents = function (container) {
        bindLeadEventsV2(container);
    };

    /* ================================================================
     *  v2.0.0: ENHANCED BUILD LEAD CARD (add pipeline + notes + forward)
     * ================================================================ */

    // Store reference to original buildLeadCard
    var _origBuildLeadCard = typeof buildLeadCard === 'function' ? buildLeadCard : null;

    // Override to add pipeline strip, notes panel, and forward form AFTER the existing card content
    var _origBuildLeadCardFn = buildLeadCard;
    buildLeadCard = function (lead, batchId) {
        // Get base card from original function
        var base = _origBuildLeadCardFn(lead, batchId);
        var leadId = lead.id || lead.lead_id || '';

        // Inject pipeline strip before the closing </div>
        var hasCRM = lead.nutshell_lead_id && lead.nutshell_lead_id !== '';
        var isContacted = lead.contact_status === 'contacted';
        var isSkipped = lead.contact_status === 'skipped';
        var isClosed = lead.contact_status === 'closed';

        var pipHtml = '<div class="zl-pip-steps">';
        pipHtml += '<span class="zl-pip-step done"><span class="zl-pip-step-dot"></span>Generated</span>';
        pipHtml += '<span class="zl-pip-connector ' + (hasCRM ? 'done' : '') + '"></span>';
        pipHtml += '<span class="zl-pip-step ' + (hasCRM ? 'done' : '') + '"><span class="zl-pip-step-dot"></span>In CRM</span>';
        pipHtml += '<span class="zl-pip-connector ' + (isContacted ? 'done' : isSkipped ? 'warn' : '') + '"></span>';
        pipHtml += '<span class="zl-pip-step ' + (isContacted ? 'done' : isSkipped ? 'skipped' : '') + '"><span class="zl-pip-step-dot"></span>' + (isSkipped ? 'Skipped' : 'Contacted') + '</span>';
        pipHtml += '<span class="zl-pip-connector ' + (isClosed ? 'done' : '') + '"></span>';
        pipHtml += '<span class="zl-pip-step ' + (isClosed ? 'done' : '') + '"><span class="zl-pip-step-dot"></span>Closed</span>';
        pipHtml += '</div>';

        // Notes hint + expandable panel
        var notesHtml = '<div class="zl-notes-hint" data-lead-id="' + escHtml(leadId) + '">📋 Tap for details &amp; Nutshell notes</div>';
        notesHtml += '<div class="zl-notes-panel" data-lead-id="' + escHtml(leadId) + '">';
        notesHtml += '<div class="zl-notes-body">Loading…</div>';

        // Forward bar + form (if user has permission)
        if (hasPerm('can_forward_note')) {
            notesHtml += '<div class="zl-fwd-bar">';
            notesHtml += '<button class="zl-fwd-btn zl-fwd-trigger" data-lead-id="' + escHtml(leadId) + '" type="button">➡️ Forward Note</button>';
            notesHtml += '</div>';
            notesHtml += '<div class="zl-fwd-form" data-lead-id="' + escHtml(leadId) + '" style="display:none;">';
            notesHtml += '<div class="zl-fwd-form-header">Forward to Team Member</div>';
            notesHtml += '<select class="zl-fwd-recipient" data-lead-id="' + escHtml(leadId) + '"><option value="">Loading team…</option></select>';
            notesHtml += '<label class="zl-fwd-task-label"><input type="checkbox" class="zl-fwd-task-check" /> Mark as task (requires completion)</label>';
            notesHtml += '<div class="zl-fwd-preview">' + escHtml((lead.name || 'Lead') + ' — ' + (lead.city || '') + ' — ' + (lead.purchase_summary || '')) + '</div>';
            notesHtml += '<div class="zl-fwd-actions">';
            notesHtml += '<button class="zl-fwd-send" data-lead-id="' + escHtml(leadId) + '" type="button" disabled>Send</button>';
            notesHtml += '<button class="zl-fwd-cancel" type="button">Cancel</button>';
            notesHtml += '</div></div>';
            notesHtml += '<div class="zl-fwd-history" data-lead-id="' + escHtml(leadId) + '"></div>';
        }

        notesHtml += '</div>';

        // Inject before the last closing </div> of the card
        var lastClose = base.lastIndexOf('</div>');
        if (lastClose !== -1) {
            return base.substring(0, lastClose) + pipHtml + notesHtml + '</div>';
        }
        return base + pipHtml + notesHtml;
    };

    /* ================================================================
     *  v2.5.0: REP MODE (Phase 2 — per-user assigned leads)
     *
     *  A salesperson sees ONLY their assigned leads and NOT the generate
     *  or batch-history machinery. Everything here is additive: admins and
     *  operators never enter rep mode, so their batch flow is untouched.
     *  The server is authoritative (zlWidgetData.isRepMode); this only
     *  adapts the UI for usability.
     * ================================================================ */

    /**
     * Reshape the widget for a salesperson: hide generate + batch sections,
     * relabel the list as "My Leads", and wire the "new leads today" banner.
     * Idempotent — safe to call more than once (cold SPA open can re-fire).
     */
    function applyRepMode() {
        // Hide the generate panel entirely (not just collapse it) — reps don't
        // generate. Belt-and-suspenders with PHP (which already omits admin-only
        // fields and, for zdz_sales, ships without generation perms).
        toggleEl('zl-panel-generate', false);
        toggleEl('zl-btn-test',  false);
        toggleEl('zl-btn-full',  false);

        // The batch-history accordion is admin/operator-facing; reps get the
        // flat assigned-leads list instead. Hide the batch list container's
        // chrome but REUSE the same list element to render cards into.
        var sectionTitle = $('zl-batch-section-title');
        if (sectionTitle) { sectionTitle.textContent = 'My Leads'; }

        // Mark the wrap so CSS can make rep-specific tweaks if desired.
        var wrap = $('zl-widget-wrap');
        if (wrap) { wrap.setAttribute('data-rep-mode', '1'); }

        // Wire the server-rendered "new leads today" banner (if present) to
        // focus + (re)load the assigned-leads list.
        var banner = $('zl-w-leadbanner');
        if (banner && !banner.dataset.bound) {
            banner.dataset.bound = '1';
            banner.addEventListener('click', function () {
                loadMyLeads();
                tslFocusLeadList();
            });
        }
    }

    /**
     * Fetch the current rep's ASSIGNED leads (server-scoped) and render them
     * with the existing lead-card renderer. Never throws into the page.
     */
    function loadMyLeads() {
        var container = $('zl-batch-list');
        if (!container) { return; }
        container.innerHTML = '<div class="zl-w-loading" style="padding:16px;opacity:.7;">Loading your leads…</div>';

        ajaxPost('zl_my_leads', { only: 'all' })
            .then(function (resp) {
                if (!resp || !resp.success || !resp.data) {
                    container.innerHTML = '<div class="zl-w-empty" style="padding:16px;opacity:.7;">Could not load your leads. Pull to refresh.</div>';
                    return;
                }
                var leads = resp.data.leads || [];
                if (!leads.length) {
                    container.innerHTML =
                        '<div class="zl-w-empty" style="padding:20px;text-align:center;opacity:.75;">' +
                        '<div style="font-size:15px;font-weight:600;margin-bottom:4px;">No leads assigned to you yet</div>' +
                        '<div style="font-size:13px;">When an admin assigns you leads, they’ll show up here.</div>' +
                        '</div>';
                } else {
                    // batchId empty — these are assigned leads spanning batches;
                    // each card still carries its own lead id for actions.
                    renderLeadCards(leads, container, '');
                }
                // Refresh the banner/stat counts from the authoritative payload.
                if (resp.data.counts) {
                    updateMyLeadCounts(resp.data.counts);
                }
            })
            .catch(function () {
                container.innerHTML = '<div class="zl-w-empty" style="padding:16px;opacity:.7;">Could not load your leads.</div>';
            });
    }

    /**
     * Update the rep banner count + the localized cache after the rep acts on a
     * lead (so the dashboard tile and in-widget banner stay truthful without a
     * full reload). Called from loadMyLeads and after a contact/skip action.
     * @param {Object} counts { new_today, open_pending, total }
     */
    function updateMyLeadCounts(counts) {
        if (!counts) { return; }
        if (typeof zlWidgetData !== 'undefined') {
            zlWidgetData.myLeadCounts = counts;
        }
        var countEl = $('zl-w-leadbanner-count');
        if (countEl && typeof counts.open_pending !== 'undefined') {
            countEl.textContent = counts.open_pending;
        }
        // When the backlog hits zero, soften the banner to the "caught up" state.
        var banner = $('zl-w-leadbanner');
        if (banner && typeof counts.open_pending !== 'undefined') {
            if (parseInt(counts.open_pending, 10) === 0) {
                banner.classList.remove('zl-w-leadbanner--active');
                banner.classList.add('zl-w-leadbanner--clear');
            }
        }
    }

    /** Scroll the assigned-leads list into view (used by the banner + launch). */
    function tslFocusLeadList() {
        var el = $('zl-batch-section-title') || $('zl-batch-list');
        if (!el) { return; }
        setTimeout(function () {
            try { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            catch (e) { el.scrollIntoView(true); }
        }, 80);
    }

    /* ================================================================
     *  v2.0.0: PATCH INIT TO ADD PANEL TOGGLES
     * ================================================================ */

    // Hook into the init flow — the existing init() already ran,
    // so we need to attach panel toggles after the DOM is ready.
    function initV2() {
        initPanelToggles();
    }

    // Run immediately if DOM is ready, else wait
    if ($('zl-widget-wrap')) {
        initV2();
    } else {
        document.addEventListener('zdz_widgets_rendered', initV2);
    }

})();
