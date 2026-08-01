'use strict';

/**
 * Zorderz Bug Reporter v2.0
 *
 * User-facing bug/feedback reporting with:
 * - Smart auto-captured context (current view, app, browser, console errors)
 * - Minimal-click flow: tap icon → type → submit (2 interactions)
 * - Category quick-select (Bug / Suggestion / Other)
 * - Integrated with existing showToast() and Haptics
 * - Console error buffering for debug payloads
 *
 * @package Zorderz
 * @since   2.7.0
 */
const BugReporter = {

  /* ─── State ─── */
  errors: [],
  isOpen: false,
  isSubmitting: false,

  /* ─── Init ─── */
  init() {
    // 1. Intercept console.error for debug payloads.
    const originalError = console.error;
    console.error = (...args) => {
      this.errors.push({
        msg: args.map(a => {
          try { return typeof a === 'object' ? JSON.stringify(a) : String(a); }
          catch (e) { return String(a); }
        }).join(' '),
        time: new Date().toISOString()
      });
      if (this.errors.length > 20) this.errors.shift();
      originalError.apply(console, args);
    };

    // 2. Intercept unhandled errors.
    window.addEventListener('error', (e) => {
      this.errors.push({
        msg: `${e.message} (${e.filename}:${e.lineno})`,
        time: new Date().toISOString()
      });
      if (this.errors.length > 20) this.errors.shift();
    });

    // 3. Bind UI controls.
    this._bindTrigger();
    this._bindModal();
  },

  /* ─── Trigger Button ─── */
  _bindTrigger() {
    const trigger = document.getElementById('bug-report-trigger');
    if (trigger) {
      trigger.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        this.open();
      });
    }
  },

  /* ─── Modal Controls ─── */
  _bindModal() {
    const overlay  = document.getElementById('zdz-bug-overlay');
    const closeBtn = document.getElementById('zdz-bug-close');
    const submitBtn = document.getElementById('zdz-bug-submit');
    const cancelBtn = document.getElementById('zdz-bug-cancel');
    const catBtns  = document.querySelectorAll('.zdz-bug-cat-btn');

    // Close on overlay click.
    if (overlay) {
      overlay.addEventListener('click', (e) => {
        if (e.target === overlay) this.close();
      });
    }

    // Close / Cancel.
    if (closeBtn) closeBtn.addEventListener('click', () => this.close());
    if (cancelBtn) cancelBtn.addEventListener('click', () => this.close());

    // Submit.
    if (submitBtn) submitBtn.addEventListener('click', () => this.submit());

    // Category toggle.
    catBtns.forEach(btn => {
      btn.addEventListener('click', () => {
        catBtns.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        if (typeof Haptics !== 'undefined') Haptics.tap();
      });
    });

    // Keyboard: Escape to close, Ctrl/Cmd+Enter to submit.
    document.addEventListener('keydown', (e) => {
      if (!this.isOpen) return;
      if (e.key === 'Escape') {
        e.preventDefault();
        this.close();
      }
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        this.submit();
      }
    });
  },

  /* ─── Smart Context Capture ─── */
  _getContext() {
    let currentApp = 'None';
    let currentView = 'Dashboard';

    // Detect active app via Bridge.
    if (window.Bridge && Bridge.currentApp) {
      const appConfig = (window.zdzData?.apps || []).find(a => a.id === Bridge.currentApp);
      currentApp = appConfig ? appConfig.nm : Bridge.currentApp;
      currentView = 'App: ' + currentApp;
    } else if (typeof state !== 'undefined' && state.currentView) {
      const viewMap = {
        'sv-dash': 'Dashboard',
        'sv-settings': 'Settings',
        'sv-chat': 'Chat'
      };
      currentView = viewMap[state.currentView] || state.currentView;
    }

    return {
      currentApp,
      currentView,
      url: window.location.href,
      userAgent: navigator.userAgent,
      platform: navigator.platform || 'unknown',
      viewport: `${window.innerWidth}x${window.innerHeight}`,
      pixelRatio: window.devicePixelRatio || 1,
      theme: typeof state !== 'undefined' ? state.theme : 'unknown',
      role: window.zdzData?.userRole || 'unknown',
      userId: window.zdzData?.userId,
      userName: window.zdzData?.userName,
      version: window.zdzData?.themeVersion || 'unknown',
      online: navigator.onLine,
      timestamp: new Date().toISOString(),
      recentApps: typeof state !== 'undefined' ? (state.recentApps || []).slice(0, 5) : [],
      recentErrors: this.errors.slice(-5)
    };
  },

  /* ─── Open Modal ─── */
  open() {
    const overlay      = document.getElementById('zdz-bug-overlay');
    const textarea     = document.getElementById('zdz-bug-description');
    const contextBadge = document.getElementById('zdz-bug-context');
    const contextRow   = document.getElementById('zdz-bug-context-row');

    if (!overlay) return;

    // Capture context before showing.
    const ctx = this._getContext();

    // Show context badge.
    if (contextBadge && contextRow) {
      contextBadge.textContent = ctx.currentView;
      contextRow.style.display = '';
    }

    // Reset form.
    if (textarea) {
      textarea.value = '';
      textarea.placeholder = ctx.currentApp !== 'None'
        ? `What went wrong in ${ctx.currentApp}?`
        : 'Describe what happened and what you expected...';
      textarea.classList.remove('zdz-bug-shake');
    }

    // Reset category to "Bug".
    document.querySelectorAll('.zdz-bug-cat-btn').forEach(b => b.classList.remove('active'));
    const defaultCat = document.querySelector('.zdz-bug-cat-btn[data-cat="bug"]');
    if (defaultCat) defaultCat.classList.add('active');

    // Reset submit button.
    this._resetSubmitBtn();

    // Show overlay.
    overlay.classList.add('show');
    overlay.setAttribute('aria-hidden', 'false');
    this.isOpen = true;

    // Focus textarea after transition.
    setTimeout(() => {
      textarea?.focus();
    }, 200);

    if (typeof refreshIcons === 'function') refreshIcons();
    if (typeof Haptics !== 'undefined') Haptics.tap();
  },

  /* ─── Close Modal ─── */
  close() {
    const overlay = document.getElementById('zdz-bug-overlay');
    if (overlay) {
      overlay.classList.remove('show');
      overlay.setAttribute('aria-hidden', 'true');
    }
    this.isOpen = false;
  },

  /* ─── Submit Report ─── */
  async submit() {
    if (this.isSubmitting) return;

    const textarea   = document.getElementById('zdz-bug-description');
    const description = (textarea?.value || '').trim();

    // Validate: description is required.
    if (!description) {
      textarea?.focus();
      if (typeof Haptics !== 'undefined') Haptics.error();
      textarea?.classList.add('zdz-bug-shake');
      setTimeout(() => textarea?.classList.remove('zdz-bug-shake'), 600);
      return;
    }

    const submitBtn = document.getElementById('zdz-bug-submit');
    const activeCat = document.querySelector('.zdz-bug-cat-btn.active');
    const category  = activeCat?.dataset.cat || 'bug';

    this.isSubmitting = true;

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<span class="zdz-bug-spinner"></span> Sending\u2026';
    }

    const context = this._getContext();

    try {
      const res = await fetch(`${window.zdzData.apiUrl}report-bug`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': window.zdzData.nonce
        },
        body: JSON.stringify({
          description,
          category,
          debug_data: context
        })
      });

      const data = await res.json();

      if (data.success || data.post_id) {
        this.close();
        if (typeof showToast === 'function') showToast('Report submitted \u2714 Thank you!');
        if (typeof Haptics !== 'undefined') Haptics.success();
      } else {
        throw new Error(data.message || 'Submission failed');
      }
    } catch (err) {
      console.error('[BugReporter] Submit error:', err);
      if (typeof showToast === 'function') showToast('Failed to send report. Please try again.');
      if (typeof Haptics !== 'undefined') Haptics.error();
      this._resetSubmitBtn();
    } finally {
      this.isSubmitting = false;
    }
  },

  /* ─── Helpers ─── */
  _resetSubmitBtn() {
    const submitBtn = document.getElementById('zdz-bug-submit');
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<i data-lucide="send" style="width:16px;height:16px"></i> Submit';
      if (typeof refreshIcons === 'function') refreshIcons();
    }
  }
};

/* ─── Bootstrap ─── */
document.addEventListener('DOMContentLoaded', () => {
  BugReporter.init();
});

window.BugReporter = BugReporter;
