'use strict';

/**
 * Bridge v3.2 — Full-Screen App Viewport
 *
 * Replaces the old bottom-sheet pattern with an immersive full-screen
 * page transition. When a user opens an app, the viewport slides up
 * to cover the entire screen, the bottom nav hides, and a compact
 * app header provides a back button.
 *
 * v3.1: Added `options.prompt` support — KPI cards can deep-link into
 *       apps (e.g. Sales Analytics) with a pre-filled prompt.
 *       The prompt is passed to iframe apps via a `zdz_prompt` URL param
 *       and stored in sessionStorage as a fallback for apps that read it.
 *
 * v3.2: Inline-widget launch intent. Before the legacy scroll-into-view,
 *       the Bridge dispatches a cancelable `zdz_app_launch` event. Tool-style
 *       widgets (Camera, Sketch) listen for it and open their own focused
 *       surface immediately (calling preventDefault()), so the icon tap goes
 *       straight to capture/creation instead of scrolling the dashboard.
 *       Apps that don't handle it keep the original scroll behavior.
 *       v3.2 ALSO revives inline <script> tags inside #app-body for
 *       `ajax_html` apps (innerHTML drops scripts), mirroring the
 *       .dash-widget-body revival in app.js — this is what lets a
 *       self-bootstrapping ajax_html app (the Media library) ship inline JS.
 *
 * build v2.20.3-patched-8: zdz_app_launch detail enriched to the documented
 *       contract — fires for every inline_widget app uniformly (no app-id
 *       allow-list, so Prep is covered), { cancelable:true,
 *       bubbles:true }, detail = { appId, app, container, selector, options }.
 *       Purely additive; honors preventDefault; legacy scroll-into-view
 *       unchanged. (Camera/Sketch behavior preserved.)
 */
const Bridge = {
  currentApp: null,

  /**
   * @param {string} appId   — Registered app identifier.
   * @param {Object} [options]
   * @param {string} [options.prompt] — Pre-filled prompt for the target app.
   */
  loadApp(appId, options) {
    const opts = options || {};
    const app = zdzData.apps.find(a => a.id === appId);
    if (!app) return;

    if (typeof addRecent === 'function') addRecent(appId);
    if (typeof Haptics !== 'undefined') Haptics.tap();

    // ---- Redirect apps open in a new tab ----
    if (app.bridge_type === 'redirect') {
      window.open(app.admin_url, '_blank');
      return;
    }

    // ---- Inline widgets: let the app claim the launch, else scroll ----
    if (app.bridge_type === 'inline_widget') {
      // v3.2: Give the plugin a chance to open its own focused surface
      // (e.g. Camera opens the native camera, Sketch opens its fullscreen
      // canvas) so the icon tap goes straight to the tool — one tap to
      // create, no dashboard scroll. If a plugin handles it and calls
      // preventDefault(), we stop here. Otherwise we fall back to the legacy
      // scroll-into-view, so genuine dashboard widgets behave exactly as before.
      //
      // v2.20.3-patched-8: the event fires for EVERY inline_widget app
      // uniformly (keyed only on bridge_type — no app-id allow-list), so
      // Prep receives it identically to Camera/Sketch. The detail
      // payload carries the registered app record (`app`) and a resolved
      // `container` (plus a `selector` fallback) so a plugin listener can find
      // its widget without re-deriving it. Adding these keys is purely additive
      // — existing listeners that only read detail.appId are unaffected.
      var launchContainer = document.querySelector('.dash-widget-container[data-app-id="' + appId + '"]');
      var launchDetail = {
        appId: appId,
        app: app,
        container: launchContainer,
        selector: '.dash-widget-container[data-app-id="' + appId + '"]',
        options: opts
      };
      var launchEvt;
      try {
        launchEvt = new CustomEvent('zdz_app_launch', {
          detail: launchDetail,
          cancelable: true,
          bubbles: true
        });
      } catch (e) {
        // Legacy CustomEvent construction fallback (canBubble, cancelable, detail).
        launchEvt = document.createEvent('CustomEvent');
        launchEvt.initCustomEvent('zdz_app_launch', true, true, launchDetail);
      }
      document.dispatchEvent(launchEvt);
      if (launchEvt.defaultPrevented) return; // plugin opened its own surface

      // Legacy default: switch to the dashboard and scroll the widget in.
      if (typeof switchView === 'function') switchView('sv-dash');

      // v2.25.2 — BLACK-SCREEN-UNTIL-SCROLL FIX.
      //
      // Symptom: tapping an app icon showed a totally black screen until the
      // user manually scrolled, then the widget appeared. TWO compounding
      // causes, both fixed:
      //
      //  (1) The view fade. `.sub-view.active{animation:viewFadeIn}` plays an
      //      opacity 0→1 fade every time `.active` is added. Re-entering the
      //      dashboard restarted that fade from opacity:0 → the whole view went
      //      black for a frame. switchView() now skips the class churn on a
      //      same-view re-entry, and we additionally do an INSTANT (not smooth)
      //      scroll so we never park mid-fade on an unpainted region.
      //
      //  (2) Smooth scroll getting dropped. A programmatic behavior:'smooth'
      //      scroll on #sv-dash is unreliable (overscroll-behavior + sticky
      //      chrome re-measure), so the landing was missed and the user sat in
      //      empty space (which paints as the near-black page bg in dark mode)
      //      until a real scroll forced a repaint. We now scroll INSTANTLY and
      //      VERIFY arrival across a few frames, so the widget is on screen and
      //      painted before control returns.
      //
      // The target is rect-based (offsetParent-independent — the v2.24.6
      // offsetTop math was wrong once widget containers became positioned).
      var ZDZ_landAttempts = 0;
      var ZDZ_doLaunchScroll = function () {
        var widget = document.querySelector('.dash-widget-container[data-app-id="' + appId + '"]');
        if (!widget) return;
        var scroller = document.getElementById('sv-dash')
          || widget.closest('.sub-view')
          || document.querySelector('.sub-view.active');

        // If the widget hasn't laid out yet (height ~0) or the scroller isn't
        // measurable, wait a frame and retry (up to ~10 frames). This is what
        // makes the landing reliable on a fresh render instead of measuring a
        // collapsed element and computing target≈0.
        var notReady = !scroller
          || scroller.clientHeight < 2
          || widget.getBoundingClientRect().height < 2;
        if (notReady && ZDZ_landAttempts < 10) {
          ZDZ_landAttempts++;
          requestAnimationFrame(ZDZ_doLaunchScroll);
          return;
        }

        if (scroller && scroller.scrollHeight > scroller.clientHeight + 4) {
          var rootCS = getComputedStyle(document.documentElement);
          var chrome = (parseFloat(rootCS.getPropertyValue('--dash-top-h')) || 0)
                     + (parseFloat(rootCS.getPropertyValue('--dash-sticky-h')) || 0);
          var computeTarget = function () {
            var wRect = widget.getBoundingClientRect();
            var sRect = scroller.getBoundingClientRect();
            // Viewport-relative delta + current scroll = absolute scrollTop that
            // puts the widget top at the scroller top; subtract chrome + 8px gap.
            return Math.max(0, scroller.scrollTop + (wRect.top - sRect.top) - chrome - 8);
          };
          // INSTANT landing (no 'smooth' — that's what got dropped before).
          scroller.scrollTop = computeTarget();
          // Re-verify over the next few frames in case layout shifted (fonts,
          // images, the fade completing) and nudge again if we drifted.
          var verify = function (n) {
            var t = computeTarget();
            if (Math.abs(scroller.scrollTop - t) > 4) scroller.scrollTop = t;
            if (n > 0) requestAnimationFrame(function () { verify(n - 1); });
          };
          requestAnimationFrame(function () { verify(3); });
        } else {
          widget.scrollIntoView({ block: 'start' });
        }
        // Highlight pulse (unchanged).
        var hc = app.cc || '#3B82F6';
        widget.style.boxShadow = 'inset 0 0 0 2px ' + hc + ', 0 0 12px ' + hc + '33';
        widget.style.borderRadius = '12px';
        setTimeout(function () { widget.style.boxShadow = ''; }, 1500);
      };
      // Two rAFs lets the view switch + first widget layout settle before we
      // measure; the in-function readiness poll covers any slower renders.
      requestAnimationFrame(function () { requestAnimationFrame(ZDZ_doLaunchScroll); });
      return;
    }

    // ---- Full-screen viewport for iframe & ajax_html apps ----
    this.currentApp = app;

    const viewport = document.getElementById('app-viewport');
    const iconEl   = document.getElementById('app-header-icon');
    const titleEl  = document.getElementById('app-header-title');
    const body     = document.getElementById('app-body');

    if (!viewport || !body) return;

    // Set compact header
    if (iconEl) {
      iconEl.style.background = app.cc || '#333';
      iconEl.innerHTML = `<i data-lucide="${app.icon || 'box'}" style="width:16px;height:16px"></i>`;
    }
    if (titleEl) titleEl.textContent = app.nm;

    // v2.24.0: tag the viewport + body with the active app id so the theme can
    // apply per-app CSS in the full-screen surface (e.g. let Knowledge Base use
    // the full horizontal width; guarantee Commission-calculator text contrast).
    // Cleared again in close(). Purely additive — no behavior depends on it.
    viewport.setAttribute('data-app-id', appId);
    body.setAttribute('data-app-id', appId);

    // Loading state
    body.className = 'app-body';
    body.innerHTML = '<div class="app-loading"><div class="app-loading-spinner"></div></div>';

    // Push a history entry so the phone back button closes the app
    history.pushState({ zdzApp: appId }, '');

    // Activate viewport
    document.body.classList.add('app-active');
    viewport.classList.add('active');
    viewport.setAttribute('aria-hidden', 'false');
    if (typeof refreshIcons === 'function') refreshIcons();

    // ---- Store prompt for cross-frame access (v3.1) ----
    if (opts.prompt) {
      try { sessionStorage.setItem('zdz_kpi_prompt', opts.prompt); } catch(e) {}
    } else {
      try { sessionStorage.removeItem('zdz_kpi_prompt'); } catch(e) {}
    }

    // ---- Load app content ----
    if (app.bridge_type === 'iframe') {
      body.classList.add('has-iframe');
      const iframe = document.createElement('iframe');
      const sep = app.admin_url.includes('?') ? '&' : '?';
      let iframeSrc = `${app.admin_url}${sep}zdz_mobile=1`;
      // Append prompt as URL param so the iframe app can read it on load
      if (opts.prompt) {
        iframeSrc += '&zdz_prompt=' + encodeURIComponent(opts.prompt);
      }
      iframe.src = iframeSrc;
      iframe.style.cssText = 'width:100%;height:100%;border:none;display:block';
      iframe.setAttribute('allow', 'clipboard-write');
      body.innerHTML = '';
      body.appendChild(iframe);
    } else if (app.bridge_type === 'ajax_html') {
      fetch(`${zdzData.apiUrl}load-app`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': zdzData.nonce },
        body: JSON.stringify({ app_id: appId })
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          body.innerHTML = data.data.html;
          // v3.2: Revive inline <script> tags in #app-body for ajax_html apps.
          // innerHTML injection silently discards <script> elements (browser
          // security), so an ajax_html app that self-bootstraps its CSS/JS
          // inline (e.g. the Media library) would never run. Re-create each
          // dead script as a live element. Mirrors the .dash-widget-body
          // revival in app.js, including copying ALL attributes (type, async,
          // defer, crossorigin, nonce, data-*) so module/deferred scripts work.
          // No XSS vector — the HTML is server-rendered under WordPress auth,
          // the same trust boundary as the inline-widget path.
          try {
            body.querySelectorAll('script').forEach(dead => {
              const live = document.createElement('script');
              Array.from(dead.attributes).forEach(a => live.setAttribute(a.name, a.value));
              if (!dead.src) { live.textContent = dead.textContent; }
              dead.parentNode.replaceChild(live, dead);
            });
          } catch (e) { /* never let script revival break the app load */ }
          if (typeof refreshIcons === 'function') refreshIcons();
        } else {
          body.innerHTML = `<div class="app-error">Failed to load: ${data.data?.message || 'Unknown error'}</div>`;
        }
      })
      .catch(() => {
        body.innerHTML = '<div class="app-error">Connection error — please try again.</div>';
      });
    }
  },

  closeApp() {
    if (!this.currentApp) return;

    const viewport = document.getElementById('app-viewport');
    const body     = document.getElementById('app-body');

    document.body.classList.remove('app-active');
    if (viewport) {
      viewport.classList.remove('active');
      viewport.setAttribute('aria-hidden', 'true');
      viewport.removeAttribute('data-app-id'); // v2.24.0: clear per-app theming tag
    }
    if (typeof Haptics !== 'undefined') Haptics.tap();

    this.currentApp = null;

    // Clean up DOM after close animation finishes
    setTimeout(() => {
      if (body) {
        body.innerHTML = '';
        body.className = 'app-body';
      }
    }, 380);
  },

  /**
   * postMessage handler — allows iframe apps to communicate with the shell.
   * Supported actions:
   *   { type: 'zdz-bridge', action: 'close' }
   *   { type: 'zdz-bridge', action: 'toast', message: '...' }
   *   { type: 'zdz-bridge', action: 'navigate', appId: '...', prompt: '...' }
   */
  handleMessage(event) {
    if (!event.data || event.data.type !== 'zdz-bridge') return;

    switch (event.data.action) {
      case 'close':
        Bridge.closeApp();
        break;
      case 'toast':
        if (typeof showToast === 'function' && event.data.message) {
          showToast(event.data.message);
        }
        break;
      case 'navigate':
        if (event.data.appId) {
          Bridge.closeApp();
          const navOpts = event.data.prompt ? { prompt: event.data.prompt } : undefined;
          setTimeout(() => Bridge.loadApp(event.data.appId, navOpts), 400);
        }
        break;
    }
  }
};

// Listen for postMessage from iframe apps
window.addEventListener('message', Bridge.handleMessage);

window.Bridge = Bridge;
