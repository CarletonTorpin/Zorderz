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
 * build-patched-8: zdz_app_launch detail enriched to the documented
 *       contract — fires for every inline_widget app uniformly (no app-id
 *       allow-list, so Prep is covered), { cancelable:true,
 *       bubbles:true }, detail = { appId, app, container, selector, options }.
 *       Purely additive; honors preventDefault; legacy scroll-into-view
 *       unchanged. (Camera/Sketch behavior preserved.)
 *
 * the focus view. An inline widget opens as its own page: its card is fixed
 *       over the screen where it stands (focusWidget / _unfocus; nothing is moved), with Back and Pin to Home in
 *       its header, `zdz_app_focus` / `zdz_app_blur`, and [data-zdz-fill]. Contract: FOCUS-VIEW-CONTRACT-v1.md. Off for anyone who turns it off
 *       in Settings (zdzData.focusView === false), and then everything above behaves as before.
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
      // the event fires for EVERY inline_widget app
      // uniformly (keyed only on bridge_type — no app-id allow-list), so
      // Prep receives it identically to Camera/Sketch. The detail
      // payload carries the registered app record (`app`) and a resolved
      // `container` (plus a `selector` fallback) so a plugin listener can find
      // its widget without re-deriving it. Adding these keys is purely additive
      // — existing listeners that only read detail.appId are unaffected.
      var launchContainer = document.querySelector('.dash-widget-container[data-app-id="' + appId + '"]');
      // a parked card is made visible (still far above the page) for the launch, so a plugin that opens
      // its own overlay from its card (Camera, Dot Plot) or focuses a field in its handler sees a live card.
      var wasParked = !!(launchContainer && launchContainer.classList.contains('zdz-parked'));
      // A card left live by an earlier cancelled launch is parked fully again now that something else opens
      // (else its in-page controls would stay reachable to Tab and VoiceOver for good).
      document.querySelectorAll('#dash-widget-zone > .dash-widget-container[data-zdz-live]').forEach(function (c) {
        if (c === launchContainer) return;
        delete c.dataset.zdzLive;
        if (typeof window.zdzParkWidget === 'function') window.zdzParkWidget(c);
      });
      if (wasParked) launchContainer.classList.add('zdz-parked-live');
      var viewBefore = document.querySelector('.sub-view.active');
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
      // While the launch is dispatched, a handler that jumps to its own control must not launch again
      // (zdzScrollToControl → loadApp → launch → ... would run out of stack).
      var prevLaunching = Bridge._launching; // a launch inside a launch: put the outer one back
      Bridge._launching = appId;
      try { document.dispatchEvent(launchEvt); } finally { Bridge._launching = prevLaunching; }
      if (launchEvt.defaultPrevented) {
        // A handler that took the person to another view (the chat app keeps its own) leaves no page open
        // behind it.
        if (this._focused && document.querySelector('.sub-view.active') !== viewBefore) this.closeApp();
        // The plugin opened its own surface from its card: the card stays live (visible, tappable, still off the
        // page) from now on, so that surface can be seen. zdzParkWidget keeps it that way.
        if (launchContainer) { launchContainer.dataset.zdzLive = '1'; launchContainer.removeAttribute('aria-hidden'); launchContainer.inert = false; }
        return;
      }
      if (wasParked && launchContainer && !launchContainer.dataset.zdzLive) launchContainer.classList.remove('zdz-parked-live');

      // the app opens as its own page. The card the plugin
      // already rendered on the dashboard becomes that page where it stands (fixed over the screen; not moved,
      // not re-rendered), so its scripts, frames and state carry on, and Back makes it a card again. A widget
      // that lives anywhere other than the dashboard's widget zone (the Analytics app keeps its own in the chat view)
      // keeps the old behavior below.
      if (this.focusOn() && this.focusWidget(appId, app)) return;

      // an app page that is open closes first, or the dashboard would scroll behind it (the Analytics app keeps
      // its widget in the chat view, so opening it from inside another app came here).
      if (this.currentApp) this.closeApp();

      // Legacy default: switch to the dashboard and scroll the widget in.
      if (typeof switchView === 'function') switchView('sv-dash');

      // BLACK-SCREEN-UNTIL-SCROLL FIX.
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
      // The target is rect-based (offsetParent-independent — the
      // offsetTop math was wrong once widget containers became positioned).
      var ZDZ_launchToken = (Bridge._launchToken = (Bridge._launchToken || 0) + 1);
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
          // Launch clip: computeTarget now
          // re-reads the chrome height on EVERY call and RESERVES the sticky
          // app bar's height even while it is still hidden. The bar is
          // position:fixed + translated/opacity-0 (NOT display:none), so it has
          // a real, measurable height right now; it reveals a frame or two after
          // we land (once the dock scrolls off) and — before this fix — then
          // overlapped and clipped the widget's first control. Reserving
          // its measured height up front lands the widget already clear of where
          // the bar will appear, and because the reserve equals the bar's
          // eventual height the target does not change across the reveal (no
          // re-scroll, no oscillation near the reveal threshold).
          var stickyEl = document.getElementById('dash-sticky');
          var computeTarget = function () {
            var rootCS = getComputedStyle(document.documentElement);
            var topH   = parseFloat(rootCS.getPropertyValue('--dash-top-h')) || 0;
            var stickyVar = parseFloat(rootCS.getPropertyValue('--dash-sticky-h')) || 0;
            var stickyMeasured = stickyEl ? Math.round(stickyEl.getBoundingClientRect().height) : 0;
            var chrome = topH + Math.max(stickyVar, stickyMeasured);
            var wRect = widget.getBoundingClientRect();
            var sRect = scroller.getBoundingClientRect();
            // Viewport-relative delta + current scroll = absolute scrollTop that
            // puts the widget top at the scroller top; subtract chrome + 8px gap.
            return Math.max(0, scroller.scrollTop + (wRect.top - sRect.top) - chrome - 8);
          };
          // INSTANT landing (no 'smooth' — that's what got dropped before).
          scroller.scrollTop = computeTarget();
          // re-anchor on the tapped widget now (app.js initDashScrollAnchor),
          // so any widget above it that grows from here on is absorbed before paint.
          if (typeof window.zdzDashAnchorRepick === 'function') window.zdzDashAnchorRepick();
          // Re-verify over the next few frames in case layout shifted (fonts,
          // images, the fade completing) and nudge again if we drifted.
          var verify = function (n) {
            var t = computeTarget();
            if (Math.abs(scroller.scrollTop - t) > 4) scroller.scrollTop = t;
            if (n > 0) requestAnimationFrame(function () { verify(n - 1); });
          };
          requestAnimationFrame(function () { verify(3); });

          // - PIN THE TARGET AGAINST LATE ASYNC GROWTH (iPad CLS fix).
          // iPad Safari has NO CSS scroll-anchoring, so a heavier widget ABOVE
          // the target (e.g. Commission's live-FreshBooks card) that finishes
          // loading a few hundred ms after we land GROWS and shoves the target
          // down out of view -- a different widget then appears to "slide over"
          // the one you tapped. The 3-frame verify above ends long before that
          // async content arrives. Keep the target pinned while the page height
          // is still changing (stop 500ms after it settles; 4s hard cap), and
          // CANCEL the instant the user scrolls/taps so we never fight a real
          // scroll. A newer tap invalidates this pin via the launch token.
          var ZDZ_pinDone = false;
          var ZDZ_pinDeadline = Date.now() + 4000;
          var ZDZ_lastH = -1, ZDZ_stableSince = 0;
          var ZDZ_userTook = function () { ZDZ_pinDone = true; };
          var ZDZ_pinEvents = ['wheel', 'touchstart', 'pointerdown', 'keydown'];
          ZDZ_pinEvents.forEach(function (ev) {
            (ev === 'keydown' ? document : scroller).addEventListener(ev, ZDZ_userTook, { passive: true });
          });
          var ZDZ_pinCleanup = function () {
            ZDZ_pinEvents.forEach(function (ev) {
              (ev === 'keydown' ? document : scroller).removeEventListener(ev, ZDZ_userTook);
            });
          };
          var ZDZ_pin = function () {
            if (ZDZ_pinDone || ZDZ_launchToken !== Bridge._launchToken
                || Date.now() > ZDZ_pinDeadline || !widget.isConnected) {
              ZDZ_pinCleanup(); return;
            }
            var h = scroller.scrollHeight;
            if (h !== ZDZ_lastH) { ZDZ_lastH = h; ZDZ_stableSince = Date.now(); }
            var tgt = computeTarget();
            if (Math.abs(scroller.scrollTop - tgt) > 4) { scroller.scrollTop = tgt; }
            if (ZDZ_stableSince && (Date.now() - ZDZ_stableSince) > 500) { ZDZ_pinCleanup(); return; }
            setTimeout(ZDZ_pin, 100);
          };
          setTimeout(ZDZ_pin, 100);
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
    const viewport = document.getElementById('app-viewport');
    const iconEl   = document.getElementById('app-header-icon');
    const titleEl  = document.getElementById('app-header-title');
    const body     = document.getElementById('app-body');

    if (!viewport || !body) return;

    // a widget in focus goes home first, so this page never takes it with it.
    const wasOpen = !!this.currentApp;
    this._unfocus();
    this.currentApp = app;

    // Set compact header
    if (iconEl) {
      iconEl.style.background = app.cc || '#333';
      iconEl.style.color = app.ink || '#fff';   // the group's ink (dark on light plates)
      iconEl.innerHTML = `<i data-lucide="${app.icon || 'box'}" style="width:16px;height:16px"></i>`;
    }
    if (titleEl) titleEl.textContent = app.nm;

    // tag the viewport + body with the active app id so the theme can
    // apply per-app CSS in the full-screen surface (e.g. let Knowledge Base use
    // the full horizontal width; guarantee Commission-calculator text contrast).
    // Cleared again in close(). Purely additive — no behavior depends on it.
    viewport.setAttribute('data-app-id', appId);
    body.setAttribute('data-app-id', appId);

    // Loading state
    body.className = 'app-body';
    body.innerHTML = '<div class="app-loading"><div class="app-loading-spinner"></div></div>';

    // Push a history entry so the phone back button closes the app. Going from one open app straight to
    // another REPLACES that entry, so one Back always reaches home (an extra entry was a dead Back on home).
    this._history({ zdzApp: appId }, wasOpen);

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

  /**
   * Close the open app. fromPop: the browser already went back (app.js's popstate handler passes true). Any other
   * caller (a plugin's 'close' message, a fallback below) closes the page AND spends the entry it pushed, so no
   * dead Back is left behind on home.
   */
  closeApp(fromPop) {
    if (!this.currentApp) return;

    const viewport = document.getElementById('app-viewport');
    const body     = document.getElementById('app-body');

    if (this._focused) {
      this._unfocus();
    } else {
      document.body.classList.remove('app-active');
      if (viewport) {
        viewport.classList.remove('active');
        viewport.setAttribute('aria-hidden', 'true');
        viewport.removeAttribute('data-app-id'); // clear per-app theming tag
      }
      // Clean up DOM after close animation finishes, only if nothing opened since — an app opened within
      // these 380 ms used to be wiped by this timer.
      setTimeout(() => {
        if (body && !(Bridge.currentApp && !Bridge._focused)) {
          body.innerHTML = '';
          body.className = 'app-body';
        }
      }, 380);
    }
    if (typeof Haptics !== 'undefined') Haptics.tap();
    this.currentApp = null;
    if (fromPop !== true) this._popEntry();
  },

  /** Spend the history entry an open page pushed, without that pop closing anything else (counted, as closeDoc). */
  _popEntry() {
    try {
      if (history.state && history.state.zdzApp) {
        this._skipPops = (this._skipPops || 0) + 1;
        history.back();
      }
    } catch (e) { this._skipPops = Math.max(0, (this._skipPops || 1) - 1); }
  },

  /** A full-screen (iframe / ajax_html) page goes away without touching history: another page takes its place. */
  _closeViewport() {
    const viewport = document.getElementById('app-viewport');
    const body = document.getElementById('app-body');
    document.body.classList.remove('app-active');
    if (viewport) { viewport.classList.remove('active'); viewport.setAttribute('aria-hidden', 'true'); viewport.removeAttribute('data-app-id'); }
    if (body) { body.innerHTML = ''; body.className = 'app-body'; }
  },

  /* ---------------------------------------------------------------------------------------------------------
   * THE FOCUS VIEW.
   *
   * Less scrolling, more like pages: tapping an app opens it as its own page with a Back arrow; home is the ask bar, the launcher and Today. FOCUS-VIEW-CONTRACT-v1.md has the plugin contract.
   *
   * The widget's card BECOMES the page where it stands: it is not moved. Moving a node reloads every iframe it
   * holds (an open email, a messenger frame), resets inner scroll positions and drops what was typed in a frame
   *, so the card stays in the dashboard's widget zone and, while open, is fixed over the whole
   * screen (.zdz-focused). Its own header becomes the page header: the theme adds a Back arrow and Pin to Home to
   * it; "Ask chat about this" and anything else a plugin put there stay. Its body is the one scroll.
   *   - document events `zdz_app_focus` / `zdz_app_blur` ({appId, container, host}) and one window `resize`;
   *   - an element marked [data-zdz-fill] is given the height left on screen (Sketch's canvas);
   *   - inside the page, --dash-top-h / --dash-sticky-h are 0px and --zdz-focus-h is the page's height.
   * ------------------------------------------------------------------------------------------------------- */
  _focused: null,

  /**
   * On unless the person turned it off in Settings. wp_localize_script() hands booleans to the page as strings
   * ("1" for on, "" for off), so "", "0", 0 and false all mean off (a test of `=== false` alone would miss them).
   */
  focusOn() {
    if (typeof zdzData === 'undefined' || !zdzData || !('focusView' in zdzData)) return true;
    const v = zdzData.focusView;
    return !(v === false || v === 0 || v === '' || v === '0' || v === 'false');
  },

  /** One history entry per open page: replace it when one page follows another, else push. */
  _history(stateObj, replace) {
    try {
      if (replace && history.state && history.state.zdzApp) { history.replaceState(stateObj, ''); }
      else { history.pushState(stateObj, ''); }
    } catch (e) { /* history is a convenience; the page still opens */ }
  },

  /** The card for an app in the dashboard's widget zone, or null. */
  _card(appId) {
    const zone = document.getElementById('dash-widget-zone');
    return zone ? zone.querySelector(':scope > .dash-widget-container[data-app-id="' + String(appId).replace(/["\\]/g, '') + '"]') : null;
  },

  /**
   * Open an inline widget as its own page. Returns false (and changes nothing) when the widget is not in the
   * dashboard's widget zone, so the caller falls back to the scroll.
   */
  focusWidget(appId, app) {
    const card = this._card(appId);
    if (!card) return false;
    const body = card.querySelector(':scope > .dash-widget-body') || card.lastElementChild;
    if (!body) return false;
    if (this._focused && this._focused.container === card) return true; // already open: nothing to do

    const wasOpen = !!this.currentApp;
    const opener = document.activeElement;
    this._unfocus();
    if (wasOpen) this._closeViewport();
    // The card lives in the dashboard view: from Settings or the chat view, that view comes forward first, or the
    // page would open hidden with the bottom bar gone.
    const dash = document.getElementById('sv-dash');
    if (dash && !dash.classList.contains('active') && typeof switchView === 'function') switchView('sv-dash');
    const homeScroll = dash ? dash.scrollTop : 0;

    this._pageBar(card, appId);
    delete card.dataset.zdzLive;
    card.classList.remove('zdz-parked', 'zdz-parked-live');
    card.removeAttribute('aria-hidden');
    card.classList.add('zdz-focused');
    this._focused = { appId: appId, container: card, body: body, opener: opener, homeScroll: homeScroll, inert: [] };
    this._modal(true);
    this._holdScroll(this._focused);
    if (typeof ResizeObserver !== 'undefined') {
      // The Ask panel opening above the body, or anything else that changes the body's size, re-measures it.
      this._ro = this._ro || new ResizeObserver(() => { if (Bridge._focused) Bridge._measure(); });
      this._ro.observe(body);
    }
    this.currentApp = app;
    this._history({ zdzApp: appId, zdzFocus: 1 }, wasOpen);
    document.body.classList.add('zdz-focus-open');
    body.scrollTop = 0;
    if (typeof refreshIcons === 'function') refreshIcons();
    this._measure();
    // Keyboard and screen-reader focus goes into the page (unless a launch handler already put it inside).
    if (!card.contains(document.activeElement)) {
      const back = card.querySelector('.dw-page-back');
      if (back) { try { back.focus({ preventScroll: true }); } catch (e) { /* old WebView */ } }
    }
    this._announce('zdz_app_focus', appId, card, body);
    return true;
  },

  /** Back arrow and Pin to Home in the card's own header (made once per card; shown only while it is a page). */
  _pageBar(card, appId) {
    const header = card.querySelector(':scope > .dash-widget-header');
    if (!header) return;
    if (!header.querySelector('.dw-page-back')) {
      const back = document.createElement('button');
      back.type = 'button';
      back.className = 'dw-page-back';
      back.setAttribute('aria-label', 'Back');
      back.innerHTML = '<i data-lucide="arrow-left" style="width:22px;height:22px"></i>';
      back.addEventListener('click', () => {
        if (!Bridge._focused || Bridge._focused.container !== card) return;
        if (history.state && history.state.zdzApp) history.back(); else Bridge.closeApp(true);
      });
      header.insertBefore(back, header.firstChild);
    }
    let pin = header.querySelector('.dw-pin');
    if (!pin) {
      pin = document.createElement('button');
      pin.type = 'button';
      pin.className = 'dw-pin';
      pin.setAttribute('aria-label', 'Pin to Home');
      pin.addEventListener('click', () => Bridge.togglePin(card.getAttribute('data-app-id')));
      header.appendChild(pin);
    }
    this._drawPin(pin, appId);
  },

  _drawPin(pin, appId) {
    const on = this.isPinned(appId);
    pin.setAttribute('aria-pressed', on ? 'true' : 'false');
    pin.title = on ? 'On Home. Tap to take it off Home.' : 'Keep this app on Home';
    pin.innerHTML = '<i data-lucide="' + (on ? 'pin-off' : 'pin') + '" style="width:18px;height:18px"></i>' +
      '<span class="dw-pin-label" aria-hidden="true">' + (on ? 'On Home' : 'Pin') + '</span>';
    if (typeof refreshIcons === 'function') refreshIcons();
  },

  /**
   * A page's body is its one scroll, and nothing an app writes on the card can take that away (an app that sets
   * the card's overflow inline could otherwise leave its page unable to scroll).
   * Every widget starts while it is parked on home, and some set the card's or its body's overflow inline with
   * !important there (Commission's Sales ledger un-clips them so its column header can pin on the dashboard). An
   * inline !important beats this theme's own !important, so the page opened with overflow:visible and could not
   * scroll. While the page is open, those inline overflow values are put aside (and any the app writes later), and
   * when it closes they go back exactly as the app left them, so the dashboard card behaves as before.
   */
  _SCROLL_PROPS: ['overflow', 'overflow-x', 'overflow-y'],
  _holdScroll(f) {
    if (!f) return;
    const props = this._SCROLL_PROPS;
    f.heldStyle = new Map();
    const els = [f.container, f.body];
    const take = (el) => {
      let saved = f.heldStyle.get(el);
      props.forEach((p) => {
        const v = el.style.getPropertyValue(p);
        if (!v) return;
        if (!saved) { saved = {}; f.heldStyle.set(el, saved); }
        saved[p] = [v, el.style.getPropertyPriority(p)];
        el.style.removeProperty(p);
      });
    };
    els.forEach(take);
    if (typeof MutationObserver !== 'undefined') {
      f.styleWatch = new MutationObserver(() => { if (this._focused === f) els.forEach(take); });
      els.forEach((el) => f.styleWatch.observe(el, { attributes: true, attributeFilter: ['style'] }));
    }
  },
  _releaseScroll(f) {
    if (!f || !f.heldStyle) return;
    if (f.styleWatch) { f.styleWatch.disconnect(); f.styleWatch = null; }
    f.heldStyle.forEach((saved, el) => {
      Object.keys(saved).forEach((p) => {
        if (el.style.getPropertyValue(p)) return; // the app wrote a newer value after the page closed: keep it
        el.style.setProperty(p, saved[p][0], saved[p][1]);
      });
    });
    f.heldStyle = null;
  },

  /** The open card goes back to being a card. Safe to call when nothing is open. */
  _unfocus() {
    const f = this._focused;
    if (!f) return;
    this._focused = null;
    if (this._ro) { try { this._ro.unobserve(f.body); } catch (e) { /* gone */ } }
    this._modal(false, f);
    this._releaseScroll(f);
    this._clearFill(f.container);
    f.container.classList.remove('zdz-focused');
    f.container.style.removeProperty('--zdz-focus-h');
    if (typeof window.zdzParkWidget === 'function') window.zdzParkWidget(f.container);
    document.body.classList.remove('zdz-focus-open');
    // Home's scroll goes back where it was: a pinned card leaving the page shortened home while it was open, and
    // the browser clamped its position.
    const dash = document.getElementById('sv-dash');
    if (dash && f.homeScroll) { dash.scrollTop = f.homeScroll; requestAnimationFrame(() => { if (!Bridge._focused) dash.scrollTop = f.homeScroll; }); }
    // Focus goes back to what opened it (the launcher tile), when that is still on screen.
    if (f.opener && f.opener.isConnected && f.opener !== document.body && f.opener.getClientRects().length) {
      try { f.opener.focus({ preventScroll: true }); } catch (e) { /* old WebView */ }
    }
    this._announce('zdz_app_blur', f.appId, f.container, f.body);
  },

  /**
   * While a page is open, everything behind it is inert: Tab and VoiceOver stay in the page.
   * Only what this call made inert is released again.
   */
  _modal(on, f) {
    f = f || this._focused;
    if (!f) return;
    if (on) {
      const card = f.container;
      const skip = (el) => el === card || el.contains(card) || el.dataset.zdzLive === '1';
      const list = [];
      const add = (el) => { if (el && !el.inert && !skip(el)) { el.inert = true; list.push(el); } };
      const main = document.getElementById('sv-dash');
      if (main) Array.prototype.forEach.call(main.children, add);
      const zone = document.getElementById('dash-widget-zone');
      if (zone) Array.prototype.forEach.call(zone.children, add);
      document.querySelectorAll('.bnav').forEach(add);
      // The bug reporter's overlay sits on <body> and is only hidden when closed; its controls would take Tab.
      const bug = document.getElementById('zdz-bug-overlay');
      if (bug && bug.getAttribute('aria-hidden') === 'true') add(bug);
      f.inert = list;
    } else {
      (f.inert || []).forEach(el => { el.inert = false; });
      f.inert = [];
    }
  },

  /** Events for plugins, then one resize so anything that measured itself measures again. */
  _announce(name, appId, container, host) {
    let ev;
    const detail = { appId: appId, container: container, host: host };
    try { ev = new CustomEvent(name, { detail: detail, bubbles: true }); }
    catch (e) { ev = document.createEvent('CustomEvent'); ev.initCustomEvent(name, true, false, detail); }
    document.dispatchEvent(ev);
    requestAnimationFrame(() => {
      this._synthResize = true;
      try { window.dispatchEvent(new Event('resize')); } catch (e) { /* very old WebView */ }
      this._synthResize = false;
    });
  },

  /** The page's height as --zdz-focus-h, and [data-zdz-fill] elements sized to the space left on screen. */
  _measure() {
    const f = this._focused;
    if (!f) return;
    const body = f.body;
    // On the card, so both the card's own rules (Game's budget) and everything in the body can read it.
    f.container.style.setProperty('--zdz-focus-h', body.clientHeight + 'px');
    const fills = Array.prototype.filter.call(f.container.querySelectorAll('[data-zdz-fill]'), el => el.getClientRects().length > 0);
    if (!fills.length) return;
    fills.forEach(el => {
      if (el.__zdzFillOrig === undefined) el.__zdzFillOrig = el.style.height || '';
      el.style.height = '';
    });
    // What the content needs, measured with each fill element at its own height and the body at its content's
    // height (offsetHeight, so a transition's scale is not counted). Both sides include the body's padding,
    // which holds the bottom safe area, so the fill never runs under the home indicator.
    // the content's height is read from the body's children (their offsets, so a transition's scale is not
    // counted), plus the body's padding. (Letting the body grow to its content and reading its height comes back
    // capped at the screen, which would leave a few pixels of scroll.)
    const cs = getComputedStyle(body);
    let top = Infinity, bottom = -Infinity;
    Array.prototype.forEach.call(body.children, k => {
      const kcs = getComputedStyle(k);
      if (kcs.position === 'absolute' || kcs.position === 'fixed' || kcs.display === 'none') return;
      top = Math.min(top, k.offsetTop - (parseFloat(kcs.marginTop) || 0));
      bottom = Math.max(bottom, k.offsetTop + k.offsetHeight + (parseFloat(kcs.marginBottom) || 0));
    });
    const content = (bottom > top) ? (bottom - top) : 0;
    const natural = content + (parseFloat(cs.paddingTop) || 0) + (parseFloat(cs.paddingBottom) || 0);
    const sizes = fills.map(el => el.offsetHeight);
    const free = body.clientHeight - natural;   // what the screen has beyond the content
    fills.forEach((el, i) => {
      const min = parseInt(el.getAttribute('data-zdz-fill'), 10) || 200;
      // One fill element takes all of it; several share it evenly.
      el.style.height = Math.max(min, Math.floor(sizes[i] + free / fills.length)) + 'px';
    });
  },

  _clearFill(container) {
    container.querySelectorAll('[data-zdz-fill]').forEach(el => {
      if (el.__zdzFillOrig !== undefined) { el.style.height = el.__zdzFillOrig; delete el.__zdzFillOrig; }
    });
  },

  /* ---- Pin to Home: a widget someone wants on home stays there (zdzData.homePins, saved per person). ---- */
  isPinned(appId) {
    const pins = (typeof zdzData !== 'undefined' && Array.isArray(zdzData.homePins)) ? zdzData.homePins : [];
    return pins.indexOf(appId) !== -1;
  },

  togglePin(appId) {
    if (typeof zdzData === 'undefined') return;
    const pins = Array.isArray(zdzData.homePins) ? zdzData.homePins.slice() : [];
    const i = pins.indexOf(appId);
    if (i === -1) pins.push(appId); else pins.splice(i, 1);
    zdzData.homePins = pins;
    const card = this._card(appId);
    const pin = card && card.querySelector('.dw-pin');
    if (pin) this._drawPin(pin, appId);
    if (typeof window.zdzTodayRefresh === 'function') window.zdzTodayRefresh();
    if (typeof showToast === 'function') showToast(i === -1 ? 'Pinned to Home' : 'Taken off Home');
    // Saves go one after another, so a quick on-off-on cannot land out of order.
    const list = pins.slice();
    this._pinSave = (this._pinSave || Promise.resolve()).then(() => fetch(zdzData.apiUrl + 'user-prefs', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': zdzData.nonce },
      body: JSON.stringify({ home_pins: list }),
      credentials: 'same-origin',
      keepalive: true
    }).then(r => { if (!r.ok) throw new Error('save'); })).catch(() => {
      if (typeof showToast === 'function') showToast('That pin did not save. It holds until you reload.');
    });
  },

  /**
   * open a SAME-SITE document page in a layer ABOVE
   * whatever is on screen (the dashboard or an open app), with its own Back.
   *
   * Why a layer, not the app viewport: the viewport replaces the open app (Back would land on the dashboard, not
   * the list you came from), and opening the page in the browser would, on an iPhone Home Screen app, open Safari,
   * where the app's sign-in does not exist. A same-site page loaded in a frame here runs
   * with the app's own session, so a Vault document simply opens.
   *
   * Back: the layer pushes one history entry; the phone's back gesture, the
   * header arrow and Escape all close the layer only (app.js's popstate handler
   * asks Bridge.docOpen first, so the app underneath stays open).
   *
   * A Knowledge Vault document goes through the vault's own link helper when the knowledge app is here
   * (window.ZKVVaultLinks.open(href, title)): it names the document's page and adds its Download button, then
   * comes back here with opts.fromVault. A page on another site opens in a new window (no opener): the layer only
   * ever frames this site.
   *
   * @param {string} url    The document's URL (http or https; this site's in the layer, another site's in a new window).
   * @param {string} [title] Header title until the page's own title loads.
   * @param {Object} [opts] { download: same-site URL for a Download button, fromVault: true from the vault helper }
   * @return {boolean} true when the document opened (in the layer or a new window), false when the URL was refused.
   */
  docOpen: false,

  openDoc(url, title, opts) {
    opts = opts || {};
    let u;
    try { u = new URL(String(url || ''), window.location.href); } catch (e) { return false; }
    if (u.protocol !== 'https:' && u.protocol !== 'http:') return false;
    if (!opts.fromVault && !opts.download && window.ZKVVaultLinks && typeof window.ZKVVaultLinks.open === 'function') {
      try { if (window.ZKVVaultLinks.open(u.href, title || '')) return true; } catch (e) {}
    }
    if (u.origin !== window.location.origin) {
      const w = window.open(u.href, '_blank', 'noopener');
      if (w) { try { w.opener = null; } catch (e) {} }
      return true;
    }

    let layer = document.getElementById('zdz-doc-layer');
    if (!layer) {
      layer = document.createElement('div');
      layer.id = 'zdz-doc-layer';
      layer.className = 'app-viewport zdz-doc-layer';
      layer.setAttribute('role', 'dialog');
      layer.setAttribute('aria-modal', 'true');
      layer.setAttribute('aria-hidden', 'true');
      layer.innerHTML =
        '<header class="app-header">' +
          '<button type="button" class="app-back zdz-doc-back" aria-label="Back">' +
            '<i data-lucide="arrow-left" style="width:22px;height:22px"></i></button>' +
          '<div class="app-header-info"><span class="app-header-title zdz-doc-title"></span></div>' +
          '<div class="app-header-actions"><a class="zdz-doc-download" hidden download>Download</a></div>' +
        '</header>' +
        '<div class="app-body has-iframe zdz-doc-body"></div>';
      document.body.appendChild(layer);
      layer.querySelector('.zdz-doc-back').addEventListener('click', function () { Bridge.closeDoc(false); });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && Bridge.docOpen) Bridge.closeDoc(false);
      });
    }

    const titleEl = layer.querySelector('.zdz-doc-title');
    const dl      = layer.querySelector('.zdz-doc-download');
    const body    = layer.querySelector('.zdz-doc-body');
    titleEl.textContent = title || 'Document';

    dl.hidden = true;
    dl.removeAttribute('href');
    if (opts.download) {
      try {
        const d = new URL(String(opts.download), window.location.href);
        if (d.origin === window.location.origin) { dl.href = d.href; dl.hidden = false; }
      } catch (e) {}
    }

    body.innerHTML = '';
    const frame = document.createElement('iframe');
    frame.src = u.href;
    frame.title = title || 'Document';
    frame.style.cssText = 'width:100%;height:100%;border:none;display:block';
    frame.addEventListener('load', function () {
      // Same-site page: take its real title (e.g. the Vault document's name).
      try {
        const t = frame.contentDocument && frame.contentDocument.title;
        if (t) titleEl.textContent = t;
      } catch (e) {}
    });
    body.appendChild(frame);

    if (!this.docOpen) {
      try { history.pushState({ zdzDoc: 1 }, ''); this._docPushed = true; } catch (e) { this._docPushed = false; }
    }
    this.docOpen = true;
    layer.classList.add('active');
    layer.setAttribute('aria-hidden', 'false');
    document.body.classList.add('zdz-doc-active');
    if (typeof refreshIcons === 'function') refreshIcons();
    if (typeof Haptics !== 'undefined') Haptics.tap();
    return true;
  },

  /**
   * Close the document layer. fromPop=true when the browser already went back
   * (popstate); otherwise the entry openDoc() pushed is popped here, and the
   * popstate that follows finds docOpen already false.
   */
  closeDoc(fromPop) {
    if (!this.docOpen) return;
    this.docOpen = false;
    const layer = document.getElementById('zdz-doc-layer');
    if (layer) {
      layer.classList.remove('active');
      layer.setAttribute('aria-hidden', 'true');
      const body = layer.querySelector('.zdz-doc-body');
      setTimeout(function () { if (body && !Bridge.docOpen) body.innerHTML = ''; }, 380);
    }
    document.body.classList.remove('zdz-doc-active');
    const pushed = this._docPushed;
    this._docPushed = false;
    if (!fromPop && pushed) {
      this._skipPops = (this._skipPops || 0) + 1; // counted (see app.js popstate)
      try { history.back(); } catch (e) { this._skipPops--; }
    }
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
          // the next page takes this one's place (and its history entry) directly; closing first and
          // opening 400 ms later left a dead Back on home.
          const navOpts = event.data.prompt ? { prompt: event.data.prompt } : undefined;
          Bridge.loadApp(event.data.appId, navOpts);
        }
        break;
    }
  }
};

// Listen for postMessage from iframe apps
window.addEventListener('message', Bridge.handleMessage);

// a turned phone or a resized window re-measures the page in focus. The theme's scripts load before the
// plugins', so this runs before a plugin's own resize handler reads the new size. The resize the Bridge itself
// sends after a move is skipped here (it has just measured).
window.addEventListener('resize', function () {
  if (!Bridge._synthResize && Bridge._focused) Bridge._measure();
});

window.Bridge = Bridge;
