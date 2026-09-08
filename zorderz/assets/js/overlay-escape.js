/**
 * assets/js/overlay-escape.js — the shell's overlay-escape contract.
 * ===========================================================================
 * WHY THIS FILE EXISTS
 *
 * Below 1200px the shell gives every widget card its own stacking context, so a
 * widget's sticky header (position:sticky) cannot paint over the widget below:
 *
 *     @media (max-width: 1199.98px) {
 *       .dash-widget-container { position:relative; isolation:isolate; … }
 *     }
 *
 * `isolation:isolate` is what seals the card. But a plugin that renders a
 * full-screen overlay INSIDE its widget markup — `position:fixed; inset:0;
 * z-index:100000` — is then not competing with the app shell at all: its z-index
 * resolves INSIDE the card's context, so the whole card paints below the bottom
 * nav (`.bnav`), which hit-tests in front of the bottom strip of the viewport —
 * exactly where a modal's action row sits once the card hits its height cap.
 * Raising the overlay's z-index cannot help: a value inside an isolated card
 * still loses to the nav outside it.
 *
 * WHAT THIS DOES — AND WHAT IT DELIBERATELY DOES NOT
 *
 * While one of a card's overlays is on screen, the shell drops that ONE card's
 * `isolation` by adding `.zdz-overlay-host` (see app.css). The card is
 * `position:relative` with `z-index:auto`, so without `isolation:isolate` it no
 * longer forms a stacking context: the fixed overlay's z-index goes straight to
 * the root, above the shell chrome. The class comes off the moment the overlay
 * hides, so the seal is back for normal scrolling. Nothing else is touched.
 *
 * It does NOT re-parent plugin markup. A plugin may scope its rules or its custom
 * properties to an ancestor, and the shell cannot tell which do — an overlay
 * moved out of its subtree would lose every var() it references. So an overlay
 * stays exactly where its author put it; un-isolating the card gets the same
 * stacking result without disturbing the cascade. There is no nav-retract layer
 * either: un-isolating already puts an open overlay above the nav where it
 * belongs, so nothing has to move.
 *
 * Above 1200px this is inert — that is where the isolation rule stops applying.
 *
 * PUBLIC API (window.ZDZOverlay)
 *   .scan()            find + register overlays now (idempotent, no DOM moves)
 *   .register(el)      hand the shell an overlay it could not have found
 *   .sync()            re-evaluate which cards are hosting a visible overlay
 *   .list()            the current registry, for debugging
 * ===========================================================================
 */
window.ZDZOverlay = (function () {
  'use strict';

  var CARD       = '.dash-widget-container';
  var DECLARED   = '[data-zdz-overlay]';
  var STAMP      = 'data-zdz-overlay-seen';
  var HOST_CLS   = 'zdz-overlay-host';
  var Z_FLOOR    = 1000;      // below this, a fixed element is not an app overlay
  var MAX_NODES  = 4000;      // sanity cap on one scan pass
  var BREAKPOINT = 1199.98;

  var registry = [];          // { el, card, mo }
  var syncQueued = false;

  function inScope() { return window.innerWidth <= BREAKPOINT; }

  /* getComputedStyle still reports `position` and `z-index` for an element
     whose display is none — which matters, because these overlays are hidden
     at render time and that is when we want to find them. */
  function isOverlay(el) {
    if (el.hasAttribute('data-zdz-overlay')) return true;
    var cs;
    try { cs = window.getComputedStyle(el); } catch (e) { return false; }
    if (!cs || cs.position !== 'fixed') return false;
    var z = parseInt(cs.zIndex, 10);
    return !isNaN(z) && z >= Z_FLOOR;
  }

  function isVisible(el) {
    if (!el.isConnected) return false;
    var cs;
    try { cs = window.getComputedStyle(el); } catch (e) { return false; }
    if (cs.display === 'none' || cs.visibility === 'hidden') return false;
    if (parseFloat(cs.opacity) === 0) return false;
    return el.getClientRects().length > 0;
  }

  /* ---------------------------------------------------------------------
   * Un-seal only the cards that are currently showing an overlay.
   * ------------------------------------------------------------------- */
  function sync() {
    var hosts = [];
    var i;
    for (i = 0; i < registry.length; i++) {
      var entry = registry[i];
      if (!entry.card || !entry.card.isConnected) continue;
      if (isVisible(entry.el) && hosts.indexOf(entry.card) === -1) hosts.push(entry.card);
    }
    // Clear cards that are no longer hosting anything visible.
    var current = document.querySelectorAll(CARD + '.' + HOST_CLS);
    for (i = 0; i < current.length; i++) {
      if (hosts.indexOf(current[i]) === -1) current[i].classList.remove(HOST_CLS);
    }
    for (i = 0; i < hosts.length; i++) hosts[i].classList.add(HOST_CLS);
  }

  function queueSync() {
    if (syncQueued) return;
    syncQueued = true;
    var run = function () { syncQueued = false; sync(); };
    if (window.requestAnimationFrame) window.requestAnimationFrame(run);
    else window.setTimeout(run, 16);
  }

  function watch(entry) {
    if (entry.mo || typeof MutationObserver === 'undefined') return;
    entry.mo = new MutationObserver(queueSync);
    entry.mo.observe(entry.el, {
      attributes: true,
      attributeFilter: ['style', 'class', 'hidden', 'aria-hidden']
    });
  }

  function purge() {
    registry = registry.filter(function (entry) {
      if (entry.el.isConnected && entry.card && entry.card.isConnected) return true;
      if (entry.mo) { entry.mo.disconnect(); entry.mo = null; }
      return false;
    });
  }

  function known(el) {
    for (var i = 0; i < registry.length; i++) if (registry[i].el === el) return true;
    return false;
  }

  function register(el, card) {
    if (!el || known(el)) return false;
    var entry = { el: el, card: card || (el.closest && el.closest(CARD)) || null, mo: null };
    if (!entry.card) return false;
    registry.push(entry);
    watch(entry);
    el.setAttribute(STAMP, '1');
    return true;
  }

  function scan() {
    if (!document.body) return 0;
    purge();
    if (!inScope()) { sync(); return 0; }

    var cards = document.querySelectorAll(CARD);
    var found = 0;

    for (var c = 0; c < cards.length; c++) {
      var card = cards[c];

      var declared = card.querySelectorAll(DECLARED);
      for (var d = 0; d < declared.length; d++) {
        if (register(declared[d], card)) found++;
      }

      var all = card.querySelectorAll('*');
      if (all.length > MAX_NODES) continue;
      for (var i = 0; i < all.length; i++) {
        var el = all[i];
        if (el.hasAttribute(STAMP) || el.hasAttribute('data-zdz-overlay')) continue;
        if (isOverlay(el) && register(el, card)) found++;
      }
    }

    sync();
    return found;
  }

  /* Overlays a plugin builds on demand, after render. Cheap: only reacts to
     nodes added inside a widget card, and coalesces into one scan per frame. */
  function observeLate() {
    if (typeof MutationObserver === 'undefined') return;
    var pending = false;
    new MutationObserver(function (records) {
      if (pending) return;
      for (var i = 0; i < records.length; i++) {
        var t = records[i].target;
        if (records[i].addedNodes.length && t.closest && t.closest(CARD)) {
          pending = true;
          var run = function () { pending = false; scan(); };
          if (window.requestAnimationFrame) window.requestAnimationFrame(run);
          else window.setTimeout(run, 16);
          return;
        }
      }
    }).observe(document.body, { childList: true, subtree: true });
  }

  window.addEventListener('resize', queueSync);
  window.addEventListener('orientationchange', queueSync);
  // The dashboard re-renders its widget cards on this event (app.js); re-scan so a
  // freshly-mounted card's overlays are registered without waiting on the observer.
  document.addEventListener('zdz_widgets_rendered', function () { scan(); });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', observeLate, { once: true });
  } else {
    observeLate();
  }

  return {
    scan: scan,
    sync: sync,
    register: function (el) { var r = register(el); sync(); return r; },
    list: function () { return registry.slice(); }
  };
})();
