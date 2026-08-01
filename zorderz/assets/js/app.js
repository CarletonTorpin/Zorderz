'use strict';

// ---- DATA ----
const CATEGORIES = [
  { id: 'Sales', color: '#7C3AED' },
  { id: 'Finance', color: '#059669' },
  { id: 'Service', color: '#2563EB' },
  { id: 'Field', color: '#EA580C' },
  { id: 'Admin', color: '#DC2626' },
  { id: 'Ops', color: '#0891B2' },
  { id: 'Team', color: '#DB2777' }
];

const PINNED = {
  administrator: ['sales-analytics','estimate-creator','lead-generator','satisfaction-surveys'],
  zdz_owner: ['estimate-creator','sales-analytics'],
  zdz_admin: ['sales-analytics','estimate-creator','lead-generator','satisfaction-surveys'],
  zdz_sales: ['estimate-creator','sales-analytics','lead-generator','game','internal-messaging'],
  zdz_operator: ['estimate-creator','satisfaction-surveys'],
  zdz_mfg: ['estimate-creator'],
  zdz_tech: ['estimate-creator','sales-analytics','satisfaction-surveys','lead-generator','knowledge-vault','stock-checker','game','internal-messaging']
};

// ---- STATE ----
const state = {
  role: zdzData.userRole,
  isAdmin: !!zdzData.isAdmin,
  user: { name: zdzData.userName, ini: zdzData.userInitial, label: zdzData.userRoleLabel || zdzData.userRole, firstName: zdzData.userFirstName || zdzData.userName.split(' ')[0] },
  currentView: 'sv-dash',
  recentApps: [],
  theme: 'system'
};

try {
  const recent = localStorage.getItem('zdz_recent');
  if (recent) state.recentApps = JSON.parse(recent);
  const theme = localStorage.getItem('zdz_theme');
  if (theme) state.theme = theme;
} catch(e) {}

// ---- HAPTICS ----
const Haptics = {
  tap() { try { navigator.vibrate?.(10) } catch(e){} },
  success() { try { navigator.vibrate?.([15,40,15]) } catch(e){} },
  error() { try { navigator.vibrate?.([40,80,40,80,40]) } catch(e){} }
};

// ---- v2.17.1: ACTIVITY TRACKING ----
// Fire-and-forget audit log entries via REST. Covers app opens,
// view switches, dashboard loads, and session heartbeat so the
// audit log shows continuous user activity — not just login/logout.
var _tsTrackQueue = [];
var _tsTrackTimer = null;
function zdzTrack(actionType, detail, appId) {
  _tsTrackQueue.push({
    action_type: actionType || 'page_view',
    detail: detail || '',
    app_id: appId || ''
  });
  // Debounce: flush after 2s of quiet, or immediately if queue is large
  clearTimeout(_tsTrackTimer);
  if (_tsTrackQueue.length >= 5) {
    _tsFlushTrack();
  } else {
    _tsTrackTimer = setTimeout(_tsFlushTrack, 2000);
  }
}
function _tsFlushTrack() {
  if (!_tsTrackQueue.length) return;
  var batch = _tsTrackQueue.splice(0, 20);
  try {
    // Use sendBeacon for reliability (survives page close), fall back to fetch
    var payload = JSON.stringify({ events: batch });
    var url = zdzData.apiUrl + 'track';
    if (navigator.sendBeacon) {
      var blob = new Blob([payload], { type: 'application/json' });
      if (!navigator.sendBeacon(url, blob)) {
        // sendBeacon failed (quota), try fetch
        fetch(url, {
          method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': zdzData.nonce },
          body: payload, keepalive: true
        }).catch(function(){});
      }
    } else {
      fetch(url, {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': zdzData.nonce },
        body: payload, keepalive: true
      }).catch(function(){});
    }
  } catch(e) {}
}
// Flush on page hide (tab close, navigate away)
document.addEventListener('visibilitychange', function() {
  if (document.visibilityState === 'hidden') _tsFlushTrack();
});
window.zdzTrack = zdzTrack;

// ---- TOAST ----
function showToast(msg, duration = 2500) {
  const c = document.getElementById('toast-container');
  if (!c) return;
  const t = document.createElement('div');
  t.className = 'toast';
  t.textContent = msg;
  c.appendChild(t);
  setTimeout(() => { t.classList.add('out'); setTimeout(() => t.remove(), 300); }, duration);
}

function refreshIcons() {
  try { if (window.lucide) lucide.createIcons(); } catch(e) {}
}

// ---- TEXT SCALE (v2.31.0 accessibility) ----
function applyTextScale(on) {
  if (on) { document.documentElement.setAttribute('data-zdz-textscale', 'lg'); }
  else { document.documentElement.removeAttribute('data-zdz-textscale'); }
  try { localStorage.setItem('zdz_textscale', on ? 'lg' : 'off'); } catch(e) {}
}
function toggleTextScale() {
  applyTextScale(document.documentElement.getAttribute('data-zdz-textscale') !== 'lg');
}

// ---- THEME ----
function applyTheme(t) {
  state.theme = t;
  document.documentElement.setAttribute('data-theme', t);
  try { localStorage.setItem('zdz_theme', t); } catch(e) {}
  const meta = document.querySelector('meta[name="theme-color"]');
  if (t === 'dark' || (t === 'system' && window.matchMedia('(prefers-color-scheme:dark)').matches)) {
    meta?.setAttribute('content', '#020617');
  } else if (t === 'sunlight') {
    meta?.setAttribute('content', '#000000');
  } else {
    meta?.setAttribute('content', '#1E3A5F');
  }
  // Update the nav bar logo for the current theme
  updateNavLogo();
}

// ---- NAV LOGO (theme-aware + v2.14.4 A5 vertical sidebar variant) ----
// v2.16.0 T12: PHP-side fallback now ensures both logoDark and logoLight
// are always populated (if either is set). JS cascade is kept as a safety net.
function updateNavLogo() {
  const img = document.getElementById('bnav-logo-img');
  if (!img) return;
  const dark     = img.dataset.logoDark;
  const light    = img.dataset.logoLight;
  const vertical = img.dataset.logoVertical;
  const t = state.theme;

  const isDark = (t === 'dark') ||
    (t === 'system' && window.matchMedia('(prefers-color-scheme:dark)').matches);

  // v2.14.4 A5: On sidebar (≥820px), prefer the vertical logo if available
  const isSidebar = window.matchMedia('(min-width: 820px)').matches;
  if (isSidebar && vertical) {
    img.src = vertical;
    img.classList.add('logo-vertical');
    return;
  }
  img.classList.remove('logo-vertical');

  if (isDark) {
    img.src = dark || light || img.src;
  } else {
    img.src = light || dark || img.src;
  }
}

// ---- NAVIGATION ----
function switchView(viewId) {
  // v2.25.2: BLACK-FLASH FIX. `.sub-view.active{animation:viewFadeIn}` plays an
  // opacity 0→1 fade whenever `.active` is (re)added. Tapping an app icon while
  // already on the dashboard called switchView('sv-dash') again, which removed
  // then re-added `.active` on #sv-dash — RESTARTING the fade from opacity:0, so
  // the whole dashboard flashed to black and only repainted after the launch
  // scroll / a manual scroll. If the requested view is already active, do
  // nothing structural (no class churn, no animation restart, no scroll reset) —
  // the caller's own scroll (e.g. bridge.js launch) then runs on a stable,
  // fully-painted view. Only a genuine view CHANGE toggles `.active` (and thus
  // plays the fade) and resets scroll to top.
  var target = document.getElementById(viewId);
  var isSameView = target && target.classList.contains('active');
  if (!isSameView) {
    document.querySelectorAll('.sub-view').forEach(v => v.classList.remove('active'));
    target?.classList.add('active');
  }

  // Highlight the correct nav item
  document.querySelectorAll('.ni').forEach(n => {
    n.classList.toggle('active', n.dataset.view === viewId);
  });

  // Settings is toggled via the logo button, not a .ni item
  const logoBtn = document.getElementById('bnav-logo');
  if (logoBtn) {
    logoBtn.classList.toggle('settings-active', viewId === 'sv-settings');
  }

  // When settings is active, deactivate all .ni items
  if (viewId === 'sv-settings') {
    document.querySelectorAll('.ni').forEach(n => n.classList.remove('active'));
  }

  state.currentView = viewId;
  // v2.25.2: only reset scroll to top on a genuine view CHANGE. On a same-view
  // re-entry (e.g. tapping a dock icon while already on the dashboard) leave the
  // scroll alone so the caller's launch scroll (bridge.js) isn't fought by a
  // top-reset, and there's no jump-to-top → jump-to-widget flicker.
  if (!isSameView) document.getElementById(viewId)?.scrollTo(0, 0);
  Haptics.tap();
  refreshIcons();
  // v2.17.1: Track view navigation for audit log
  zdzTrack('view_switch', viewId);
}

// ---- GREETING (v2.16.0 T1: 3-tier greeting) ----
function renderGreeting() {
  const el = document.getElementById('greeting-row');
  if (!el) return;
  const hour = new Date().getHours();
  let greeting;
  if      (hour >= 5  && hour < 12) greeting = 'Good morning';
  else if (hour >= 12 && hour < 17) greeting = 'Good afternoon';
  else                               greeting = 'Good evening';
  // v2.16.0 T2: Inner wrapper for greeting + refresh button
  el.innerHTML = `<div class="greeting-row-inner">
    <h2>${greeting}, ${state.user.firstName}</h2>
    <button class="zdz-refresh-btn" id="zdz-refresh-btn" aria-label="Refresh" title="Refresh">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
    </button>
  </div>`;
  // Wire up refresh
  const btn = document.getElementById('zdz-refresh-btn');
  if (btn) {
    btn.addEventListener('click', function () {
      Haptics.tap();
      showToast('Refreshing…');
      setTimeout(function () { window.location.reload(); }, 150);
    });
  }
}

// v2.24.2: tiny, subtle build stamp at the bottom of the dashboard so the
// CURRENTLY-LOADED theme build is verifiable at a glance (the stale-PWA-shell
// problem: data can be live while the cached shell/CSS is old). Reads the
// version localized into zdzData by functions.php. Fails silently.
function renderBuildStamp() {
  try {
    var el = document.getElementById('zdz-build-stamp');
    if (!el) return;
    var v = (typeof zdzData !== 'undefined' && zdzData.themeVersion) ? zdzData.themeVersion : '';
    el.textContent = v ? ('Zorderz ' + v) : '';
  } catch (e) {}
}

// ---- v2.21.3: LEADS ACTION TILE ----
// Surfaces the signed-in salesperson's "you have N leads to contact" at the top
// of the dashboard, sourced from the platform's unified action-items feed
// (GET /zorderz/v1/dashboard-items, which plugins populate via zdz_dashboard_action_items).
// Clicking deep-links into the Leads app in rep mode ({ view: 'my-leads' }).
//
// Reliability notes (matches the robust-systems approach used across the app):
//   • Never breaks the dashboard: any fetch/shape error just hides the tile.
//   • Kiosk (zdz_general) never shows it (most-restrictive-wins; the server also
//     refuses to register a per-user item for kiosk).
//   • Cheap + cached server-side (the producer caches the count in a transient),
//     so calling this on each dashboard render is fine.
//   • Generic over plugins: renders whichever items the feed returns whose
//     app_id is the Leads app; degrades silently if none.
function renderLeadsTile() {
  const el = document.getElementById('leads-tile');
  if (!el) return;

  // Kiosk never sees a personal leads tile.
  if (state.role === 'zdz_general') { el.style.display = 'none'; return; }

  // Default hidden until we know there's something to show (no layout flash).
  el.style.display = 'none';
  el.innerHTML = '';

  if (!zdzData || !zdzData.apiUrl) return;

  fetch(zdzData.apiUrl + 'dashboard-items', {
    headers: { 'X-WP-Nonce': zdzData.nonce },
    credentials: 'same-origin'
  })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (data) {
      if (!data || !data.success || !Array.isArray(data.items)) return;

      // Find the Leads item(s). The producer uses app_id 'lead-generator'.
      const leadItems = data.items.filter(function (it) {
        return it && it.app_id === 'lead-generator' && (it.count | 0) > 0;
      });
      if (!leadItems.length) return; // nothing pending → keep it clean

      const item = leadItems[0];
      const count = item.count | 0;
      const label = String(item.label || (count + ' leads to contact'));
      const urgency = item.urgency || 'low';
      const color = /^#[0-9a-f]{3,8}$/i.test(item.color || '') ? item.color : '#22C55E';

      // Build the tile. Whole tile is a button → opens Leads in rep mode.
      el.style.display = '';
      el.innerHTML =
        '<button type="button" class="leads-tile-btn leads-tile--' + escapeHtml(urgency) + '" ' +
          'style="--leads-tile-accent:' + escapeHtml(color) + '" ' +
          'aria-label="' + escapeHtml(label) + '. Open your leads.">' +
          '<span class="leads-tile-icon" aria-hidden="true">' +
            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>' +
          '</span>' +
          '<span class="leads-tile-body">' +
            '<span class="leads-tile-count">' + count + '</span>' +
            '<span class="leads-tile-label">' + escapeHtml(label) + '</span>' +
          '</span>' +
          '<span class="leads-tile-cta" aria-hidden="true">View →</span>' +
        '</button>';

      const btn = el.querySelector('.leads-tile-btn');
      if (btn) {
        btn.addEventListener('click', function () {
          if (typeof Haptics !== 'undefined' && Haptics.tap) { Haptics.tap(); }
          openApp('lead-generator', { view: 'my-leads', source: 'dashboard-tile' });
        });
      }
    })
    .catch(function () { /* tile is optional — never break the dashboard */ });
}

// ---- QUICK STATS / KPI METRICS (role-based v2.8.0) ----
function renderQuickStats() {
  const el = document.getElementById('quick-stats');
  if (!el) return;

  // ── GENERAL (shared kiosk): No KPI strip at all ──
  // The shared workshop iPad must not show revenue-style tiles
  // (Open Estimates / Paid Jobs MTD / Pipeline). Hide the whole strip
  // exactly the way the operator dashboard does. Defence in depth: the
  // KPI REST endpoint also refuses revenue for this role server-side
  // (class-zdz-kpi-metrics.php) via the all-deny view_company_revenue
  // permission, and fetchKPIMetrics() already skips roles outside its
  // kpiRoles allow-list — so zdz_general never even requests the numbers.
  if (state.role === 'zdz_general') {
    el.style.display = 'none';
    return;
  }

  // ── OPERATOR: No stats — dashboard is widget-focused ──
  if (state.role === 'zdz_operator') {
    el.style.display = 'none';
    return;
  }
  el.style.display = '';

  // ── OWNER / ADMIN: Large KPI metric boxes (v2.10.1) ──
  // data-zdz-kpi        — populated by fetchKPIMetrics() from REST API.
  // data-zdz-kpi-action — click behaviour:
  //   "analytics:<prompt>" → Opens Analytics with the given prompt.
  //   "app:<appId>"        → Scrolls to / opens the named app.
  if (state.role === 'zdz_owner' || state.role === 'administrator' || state.role === 'zdz_admin') {
    el.className = 'kpi-grid';
    el.setAttribute('aria-label', 'Key performance metrics');
    el.innerHTML = `
      <div class="kpi-card kpi-primary kpi-action"
           data-zdz-kpi-action="analytics:What is my year-to-date revenue? Give me the current YTD figure and how it compares to the same period last year.">
        <div class="kpi-label">YTD Revenue</div>
        <div class="kpi-value" data-zdz-kpi="ytd-revenue">—</div>
        <div class="kpi-sub">FreshBooks · tap for details</div>
      </div>
      <div class="kpi-card kpi-primary kpi-action"
           data-zdz-kpi-action="analytics:What is my month-to-date revenue? Give me the current MTD figure and compare it to last month.">
        <div class="kpi-label">MTD Revenue</div>
        <div class="kpi-value" data-zdz-kpi="mtd-revenue">—</div>
        <div class="kpi-sub">FreshBooks · tap for details</div>
      </div>
      <div class="kpi-card kpi-action"
           data-zdz-kpi-action="analytics:How many estimates have we created this month? Show me the MTD count and how it compares to last month.">
        <div class="kpi-icon" style="color:#3B82F6"><i data-lucide="file-text"></i></div>
        <div class="kpi-value" data-zdz-kpi="estimates-mtd">—</div>
        <div class="kpi-label">Estimates MTD</div>
      </div>
      <div class="kpi-card kpi-action"
           data-zdz-kpi-action="app:satisfaction-surveys">
        <div class="kpi-icon" style="color:#8B5CF6"><i data-lucide="mail-check"></i></div>
        <div class="kpi-value" data-zdz-kpi="surveys-month">—</div>
        <div class="kpi-label">Surveys This Month</div>
      </div>
      <div class="kpi-card kpi-action"
           data-zdz-kpi-action="analytics:How many Google reviews do we currently have? What is our recent review trend?">
        <div class="kpi-icon" style="color:#F59E0B"><i data-lucide="star"></i></div>
        <div class="kpi-value" data-zdz-kpi="google-reviews">—</div>
        <div class="kpi-label">Google Reviews</div>
      </div>
      <div class="kpi-card kpi-action"
           data-zdz-kpi-action="analytics:How many website reviews do we have on our Ovation reviews page?">
        <div class="kpi-icon" style="color:#A855F7"><i data-lucide="message-square-heart"></i></div>
        <div class="kpi-value" data-zdz-kpi="website-reviews">—</div>
        <div class="kpi-label">Website Reviews</div>
      </div>
      <div class="kpi-card kpi-action"
           data-zdz-kpi-action="analytics:Show me our current new leads. How many open leads do we have and what is the trend?">
        <div class="kpi-icon" style="color:#10B981"><i data-lucide="user-plus"></i></div>
        <div class="kpi-value" data-zdz-kpi="new-leads">—</div>
        <div class="kpi-label">New Leads (MTD)</div>
      </div>
      <div class="kpi-card kpi-action"
           data-zdz-kpi-action="analytics:How many leads have been contacted? Give me the current contacted count and conversion rate.">
        <div class="kpi-icon" style="color:#06B6D4"><i data-lucide="phone-call"></i></div>
        <div class="kpi-value" data-zdz-kpi="leads-contacted">—</div>
        <div class="kpi-label">Contacted (MTD)</div>
      </div>
      <div class="kpi-card kpi-action"
           data-zdz-kpi-action="analytics:How many leads have converted to jobs? Show me the leads-to-jobs conversion rate.">
        <div class="kpi-icon" style="color:#EC4899"><i data-lucide="briefcase"></i></div>
        <div class="kpi-value" data-zdz-kpi="leads-to-jobs">—</div>
        <div class="kpi-label">Leads → Jobs (MTD)</div>
      </div>
    `;
    refreshIcons();
    initKPIClicks();
    return;
  }

  // ── MANUFACTURING / SHOP FOREMAN: Job queue focus ──
  if (state.role === 'zdz_mfg') {
    el.className = 'kpi-grid kpi-grid-compact';
    el.setAttribute('aria-label', 'Shop floor metrics');
    el.innerHTML = `
      <div class="kpi-card">
        <div class="kpi-icon" style="color:#EF4444"><i data-lucide="clipboard-list"></i></div>
        <div class="kpi-value" data-zdz-kpi="jobs-today">—</div>
        <div class="kpi-label">Jobs Today</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-icon" style="color:#3B82F6"><i data-lucide="calendar"></i></div>
        <div class="kpi-value" data-zdz-kpi="jobs-week">—</div>
        <div class="kpi-label">This Week</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-icon" style="color:#F59E0B"><i data-lucide="package"></i></div>
        <div class="kpi-value" data-zdz-kpi="supply-status">—</div>
        <div class="kpi-label">Supply Status</div>
      </div>
      <div class="kpi-card kpi-action" id="kpi-supply-request">
        <div class="kpi-icon" style="color:#10B981"><i data-lucide="shopping-cart"></i></div>
        <div class="kpi-value" style="font-size:var(--ref-font-sm)">Request</div>
        <div class="kpi-label">Supply Order</div>
      </div>
    `;
    refreshIcons();
    return;
  }

  // ── SALES / TECH / DEFAULT: KPI stat cards with analytics tap (v2.14.4.3) ──
  el.className = 'quick-stats';
  el.setAttribute('aria-label', 'Quick statistics');

  if (state.role === 'zdz_sales') {
    el.innerHTML = `
      <div class="stat-pill kpi-action"
           data-zdz-kpi-action="analytics:How many open estimates do I have right now? Show me the current count and any recent trend.">
        <div class="stat-icon" style="background:#F59E0B15;color:#F59E0B">
          <i data-lucide="file-text"></i>
        </div>
        <div>
          <div class="stat-val" data-zdz-kpi="estimates-mtd">—</div>
          <div class="stat-label">Open Estimates</div>
        </div>
      </div>
      <div class="stat-pill kpi-action"
           data-zdz-kpi-action="analytics:How many paid jobs do we have this month? Give me the MTD count and revenue.">
        <div class="stat-icon" style="background:#3B82F615;color:#3B82F6">
          <i data-lucide="briefcase"></i>
        </div>
        <div>
          <div class="stat-val" data-zdz-kpi="mtd-revenue">—</div>
          <div class="stat-label">Paid Jobs MTD</div>
        </div>
      </div>
      <div class="stat-pill kpi-action"
           data-zdz-kpi-action="analytics:What does our current sales pipeline look like? Show me open leads and estimated value.">
        <div class="stat-icon" style="background:#10B98115;color:#10B981">
          <i data-lucide="trending-up"></i>
        </div>
        <div>
          <div class="stat-val" data-zdz-kpi="new-leads">—</div>
          <div class="stat-label">Pipeline</div>
        </div>
      </div>
    `;
  } else if (state.role === 'zdz_tech') {
    el.innerHTML = `
      <div class="stat-pill kpi-action"
           data-zdz-kpi-action="analytics:How many jobs are scheduled for today?">
        <div class="stat-icon" style="background:#EF444415;color:#EF4444">
          <i data-lucide="map-pin"></i>
        </div>
        <div>
          <div class="stat-val" data-zdz-kpi="jobs-today">—</div>
          <div class="stat-label">Jobs Today</div>
        </div>
      </div>
      <div class="stat-pill kpi-action"
           data-zdz-kpi-action="analytics:How many hours have been logged today across the team?">
        <div class="stat-icon" style="background:#3B82F615;color:#3B82F6">
          <i data-lucide="clock"></i>
        </div>
        <div>
          <div class="stat-val">—</div>
          <div class="stat-label">Hours Today</div>
        </div>
      </div>
      <div class="stat-pill kpi-action"
           data-zdz-kpi-action="analytics:Are any supply items running low? Show me the current stock status.">
        <div class="stat-icon" style="background:#F59E0B15;color:#F59E0B">
          <i data-lucide="boxes"></i>
        </div>
        <div>
          <div class="stat-val">—</div>
          <div class="stat-label">Low Stock</div>
        </div>
      </div>
    `;
  } else {
    // Default — same as sales
    el.innerHTML = `
      <div class="stat-pill kpi-action"
           data-zdz-kpi-action="analytics:What is our month-to-date revenue?">
        <div class="stat-icon" style="background:#F59E0B15;color:#F59E0B">
          <i data-lucide="file-text"></i>
        </div>
        <div>
          <div class="stat-val" data-zdz-kpi="estimates-mtd">—</div>
          <div class="stat-label">Estimates MTD</div>
        </div>
      </div>
      <div class="stat-pill kpi-action"
           data-zdz-kpi-action="analytics:How are our paid jobs trending this month?">
        <div class="stat-icon" style="background:#3B82F615;color:#3B82F6">
          <i data-lucide="briefcase"></i>
        </div>
        <div>
          <div class="stat-val" data-zdz-kpi="mtd-revenue">—</div>
          <div class="stat-label">Paid Jobs</div>
        </div>
      </div>
    `;
  }
  refreshIcons();
  initKPIClicks(); // Wire tap-to-analytics for stat pills too
}

// ---- KPI DATA FETCH (v2.9.0 — Live dashboard data) ----
function fetchKPIMetrics() {
  // v2.14.4.3: All roles with KPI/stat cards now fetch live data
  const kpiRoles = ['zdz_owner', 'administrator', 'zdz_admin', 'zdz_mfg', 'zdz_sales', 'zdz_tech'];
  if (!kpiRoles.includes(state.role)) return;

  // v2.17.1: Restore cached KPI values instantly to avoid shimmer/flicker.
  // Only show shimmer on first-ever load (no cache).
  var cached = null;
  try { cached = JSON.parse(sessionStorage.getItem('zdz_kpi_cache')); } catch(e) {}
  if (cached && typeof cached === 'object') {
    Object.keys(cached).forEach(function(key) {
      var domKey = key.replace(/_/g, '-');
      var el = document.querySelector('[data-zdz-kpi="' + domKey + '"]');
      if (el && cached[key] && cached[key].value && cached[key].value !== '—') {
        el.textContent = cached[key].value;
        el.classList.add('kpi-loaded');
      }
    });
  } else {
    // No cache — show shimmer loading animation
    document.querySelectorAll('[data-zdz-kpi]').forEach(el => {
      el.classList.add('kpi-loading');
    });
  }

  fetch(zdzData.apiUrl + 'kpi-metrics', {
    method: 'GET',
    headers: { 'X-WP-Nonce': zdzData.nonce },
    credentials: 'same-origin'
  })
  .then(r => {
    if (!r.ok) throw new Error('KPI fetch failed: ' + r.status);
    return r.json();
  })
  .then(json => {
    if (!json.success || !json.data) return;
    const data = json.data;

    // v2.17.1: Cache the response for instant render on next load
    // v2.17.2: Merge with existing cache so partial responses don't erase old data
    var existingCache = null;
    try { existingCache = JSON.parse(sessionStorage.getItem('zdz_kpi_cache')); } catch(e) {}
    var merged = Object.assign({}, existingCache || {}, data);
    try { sessionStorage.setItem('zdz_kpi_cache', JSON.stringify(merged)); } catch(e) {}

    // Map API keys (underscores) to DOM data attributes (hyphens)
    // v2.17.2: Use merged cache so missing keys keep their previous values
    // v2.20.0: Handle error states — show warning icon instead of misleading $0
    Object.keys(merged).forEach(key => {
      if (key.charAt(0) === '_') return; // skip meta keys like _ts, _fb_status
      const domKey = key.replace(/_/g, '-');
      const el = document.querySelector(`[data-zdz-kpi="${domKey}"]`);
      if (!el) return;

      const metric = merged[key];
      if (metric && metric.error) {
        // v2.20.0: API error — show warning instead of misleading data
        el.textContent = '⚠';
        el.classList.remove('kpi-loading');
        el.classList.add('kpi-loaded', 'kpi-error');
        // Add error hint below the card
        const card = el.closest('.kpi-card, .stat-pill');
        if (card) {
          card.classList.add('kpi-error-card');
          // Add hint if not already present
          if (!card.querySelector('.kpi-error-hint')) {
            var hint = document.createElement('div');
            hint.className = 'kpi-error-hint';
            hint.textContent = metric.error === 'not_configured' ? 'Not connected' : 'Tap to retry';
            card.appendChild(hint);
          }
        }
      } else if (metric && metric.value && metric.value !== '—') {
        el.textContent = metric.value;
        el.classList.remove('kpi-loading', 'kpi-error');
        el.classList.add('kpi-loaded');
        // Remove any error hints
        const card = el.closest('.kpi-card, .stat-pill');
        if (card) {
          card.classList.remove('kpi-error-card');
          var oldHint = card.querySelector('.kpi-error-hint');
          if (oldHint) oldHint.remove();
        }
      } else if (el) {
        el.classList.remove('kpi-loading');
      }
    });

    // v2.16.0 T16: Update KPI freshness timestamp in sub-line text
    const ts = json.data._ts || (Date.now() / 1000);
    document.querySelectorAll('.kpi-sub').forEach(sub => {
      const ago = formatTimeAgo(ts);
      if (ago) sub.textContent = sub.textContent.replace(/·.*/, '· updated ' + ago);
    });
  })
  .catch(err => {
    console.warn('KPI metrics fetch error:', err);
    // v2.17.2: On error, restore cached values so cards don't go blank
    var fallback = null;
    try { fallback = JSON.parse(sessionStorage.getItem('zdz_kpi_cache')); } catch(e) {}
    if (fallback && typeof fallback === 'object') {
      Object.keys(fallback).forEach(function(key) {
        if (key.charAt(0) === '_') return;
        var domKey = key.replace(/_/g, '-');
        var el = document.querySelector('[data-zdz-kpi="' + domKey + '"]');
        if (el && fallback[key] && fallback[key].value && fallback[key].value !== '—') {
          el.textContent = fallback[key].value;
          el.classList.remove('kpi-loading');
          el.classList.add('kpi-loaded');
        }
      });
    }
    // Remove shimmer from any remaining cells
    document.querySelectorAll('.kpi-loading').forEach(el => {
      el.classList.remove('kpi-loading');
    });
  });
}

// ---- RECENT APPS ----
function addRecent(appId) {
  state.recentApps = [appId, ...state.recentApps.filter(a => a !== appId)].slice(0, 5);
  try { localStorage.setItem('zdz_recent', JSON.stringify(state.recentApps)); } catch(e) {}
  renderRecentApps();
  // v2.17.1: Track app open for audit log
  var appMeta = (zdzData.apps || []).find(function(a){ return a.id === appId; });
  zdzTrack('app_open', appMeta ? appMeta.nm : appId, appId);
}

function renderRecentApps() {
  const el = document.getElementById('recent-row');
  const section = document.getElementById('recent-section');
  if (!el || !section) return;

  if (!state.recentApps.length) {
    const pinned = PINNED[state.role] || [];
    const apps = pinned.map(id => zdzData.apps.find(a => a.id === id)).filter(Boolean).filter(a => !isSecondarySurface(a));
    if (!apps.length) { section.style.display = 'none'; return; }
    section.style.display = '';
    const label = document.querySelector('#recent-section .section-label');
    if (label) label.textContent = 'Suggested';
    el.innerHTML = apps.map(a => `
      <button class="recent-chip" onclick="openApp('${a.id}')" aria-label="Open ${a.nm}">
        <div class="rc-icon" style="background:${a.cc}"><i data-lucide="${a.icon}"></i></div>
        <span class="rc-name">${a.nm}</span>
      </button>
    `).join('');
  } else {
    // v2.16.0 T6: Show max 3 recent apps; hide section if fewer than 2
    const apps = state.recentApps.slice(0, 3).map(id => zdzData.apps.find(a => a.id === id)).filter(Boolean).filter(a => !isSecondarySurface(a));
    if (apps.length < 2) { section.style.display = 'none'; return; }
    section.style.display = '';
    const label = document.querySelector('#recent-section .section-label');
    if (label) label.textContent = 'Recently Used';
    el.innerHTML = apps.map(a => `
      <button class="recent-chip" onclick="openApp('${a.id}')" aria-label="Open ${a.nm}">
        <div class="rc-icon" style="background:${a.cc}"><i data-lucide="${a.icon}"></i></div>
        <span class="rc-name">${a.nm}</span>
      </button>
    `).join('');
  }
}

// ---- APP GRID ----
// v2.21.0: A plugin may register an app that is openable (via Bridge.loadApp /
// the command palette / "See All" deep-links) but should NOT appear as its own
// springboard/dock tile, recent chip, or search result — e.g. a secondary
// full-screen surface of an app whose PRIMARY tile is a dashboard widget (the
// Media library: the 'zdz-media' widget is the tile; 'zdz-media-all' is the
// full-screen gallery reached from "See All"). Such an app opts out with
// `springboard: false` in its config. Default (key absent) = listed as before,
// so every existing plugin is unaffected. This is the single source of truth
// for that rule; all app-list builders consult it. Loading is NEVER affected —
// Bridge.loadApp / /load-app / the viewport header still resolve the app.
function isSecondarySurface(app) {
  return !!app && app.springboard === false;
}

// v2.21.0: long dock/grid labels (e.g. "Knowledge", "Commission") crowd or wrap
// at the default size. Return ' is-long' for names at/above the length where the
// short labels (Prep, Leads, Media, Camera, Receipt…) never reach, so CSS can
// shrink ONLY those to a single line. Threshold 9 catches "Knowledge" (9) and up;
// every short label is ≤7. Returns '' (no class) for short labels — unchanged.
var ZDZ_LONG_LABEL_MIN = 9;
function longLabelClass(name) {
  return (name && String(name).length >= ZDZ_LONG_LABEL_MIN) ? ' is-long' : '';
}

function getVisibleApps() {
  // v2.17.2: Prefer server-persisted order, fall back to localStorage, then unsorted.
  var saved = null;
  if (zdzData.appOrder && Array.isArray(zdzData.appOrder) && zdzData.appOrder.length) {
    saved = zdzData.appOrder;
  }
  if (!saved) {
    try { saved = JSON.parse(localStorage.getItem('zdz_app_order')); } catch(e) {}
  }
  var apps = (zdzData.apps || []).filter(a => !!a.nm && !!a.icon && !!a.cc && !isSecondarySurface(a));
  // Apply saved order if available
  if (saved && Array.isArray(saved) && saved.length) {
    var order = {};
    saved.forEach(function(id, i) { order[id] = i; });
    apps.sort(function(a, b) {
      var oa = order.hasOwnProperty(a.id) ? order[a.id] : 999;
      var ob = order.hasOwnProperty(b.id) ? order[b.id] : 999;
      return oa - ob;
    });
  }
  return apps;
}

// v2.17.2: Persist dock/sticky bar order to localStorage + server
function saveAppOrder(order) {
  try { localStorage.setItem('zdz_app_order', JSON.stringify(order)); } catch(e) {}
  // Update in-memory so getVisibleApps() reads it this session
  zdzData.appOrder = order;
  // Fire-and-forget REST save
  try {
    fetch(zdzData.apiUrl + 'user-prefs', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': zdzData.nonce },
      body: JSON.stringify({ app_order: order }),
      keepalive: true
    }).catch(function(){});
  } catch(e) {}
}

function renderAppGrid() {
  const container = document.getElementById('app-grid-container');
  if (!container) return;
  const apps = getVisibleApps();

  // Role-adaptive: ≤4 apps → large action cards (zero-scroll, instant access)
  if (apps.length > 0 && apps.length <= 4) {
    let html = '<div class="app-grid-hero">';
    html += apps.map(a => `
      <button class="action-card" onclick="openApp('${a.id}')" aria-label="Open ${a.nm}">
        <div class="ac-icon" style="background:${a.cc}"><i data-lucide="${a.icon}"></i></div>
        <div class="ac-text">
          <div class="ac-name">${a.nm}</div>
          <div class="ac-desc">${a.desc || ''}</div>
        </div>
      </button>
    `).join('');
    html += '</div>';
    container.innerHTML = html;
    return;
  }

  // Standard category grid for 5+ apps
  const grouped = {};
  CATEGORIES.forEach(c => { grouped[c.id] = []; });
  apps.forEach(a => { if (grouped[a.cat]) grouped[a.cat].push(a); });

  let html = '';
  CATEGORIES.forEach(cat => {
    if (!grouped[cat.id]?.length) return;
    html += `<div class="cat-section">
      <div class="cat-label"><span class="cat-dot" style="background:${cat.color}"></span>${cat.id}</div>
      <div class="app-grid">
        ${grouped[cat.id].map(a => `
          <button class="app-ic" onclick="openApp('${a.id}')" aria-label="Open ${a.nm}">
            <div class="ic" style="background:${a.cc}"><i data-lucide="${a.icon}"></i></div>
            <span class="nm${longLabelClass(a.nm)}">${a.nm}</span>
          </button>
        `).join('')}
      </div>
    </div>`;
  });
  container.innerHTML = html;
}

// ---- SEARCH ----
function initSearch() {
  const input = document.getElementById('app-search');
  const results = document.getElementById('search-results');
  if (!input || !results) return;

  input.addEventListener('input', () => {
    const q = input.value.toLowerCase().trim();
    if (!q) { results.classList.remove('show'); return; }
    const apps = getVisibleApps();
    const matches = apps.filter(a =>
      a.nm.toLowerCase().includes(q) ||
      (a.desc && a.desc.toLowerCase().includes(q)) ||
      (a.slash && a.slash.includes(q)) ||
      (a.aliases && a.aliases.some(al => al.includes(q)))
    );
    if (!matches.length) { results.classList.remove('show'); return; }
    results.innerHTML = matches.map(a => `
      <button class="search-item" onclick="openApp('${a.id}');document.getElementById('app-search').value='';document.getElementById('search-results').classList.remove('show')">
        <div class="si-icon" style="background:${a.cc}"><i data-lucide="${a.icon}" style="width:18px;height:18px"></i></div>
        <div class="si-text">
          <div class="si-name">${a.nm}</div>
          <div class="si-hint">${a.desc || ''}</div>
        </div>
        <span class="si-slash">${a.slash || ''}</span>
      </button>
    `).join('');
    results.classList.add('show');
    refreshIcons();
  });

  input.addEventListener('blur', () => {
    setTimeout(() => results.classList.remove('show'), 200);
  });
}

function openApp(appId, options) {
  // The Analytics/Chat app injects a dedicated Chat sub-view (window.ZanaChat).
  // Route to it so KPI-tile prompts and the digest deep-link land on the chat
  // surface with their options intact. Backward-compatible: falls through to the
  // Bridge when the Analytics app is not installed.
  if ((appId === 'sales-analytics' || appId === 'zdz-sales-analytics') &&
      window.ZanaChat && typeof ZanaChat.open === 'function') {
    ZanaChat.open(options || {});
    return;
  }
  if (window.Bridge) {
    Bridge.loadApp(appId, options);
  }
}

// v2.21.0: External deep-link router.
// The zdz-sales-analytics Daily/Weekly Digest (r4) emails an "Open this chat →"
// button pointing at app_url() + '#tsa-session=<id>'. Clicked cold (or while the
// installed PWA is reactivated), the shell must route to the Analytics surface
// instead of dumping the user on the dashboard. The theme's responsibility is to
// open the right app and PRESERVE the hash; the Analytics widget reads
// '#tsa-session=' to select the exact session (it already does this for in-chat
// recall links). Guarded so it can never block boot and never fires for a user
// who doesn't actually have the Analytics app (e.g. a forwarded link).
function zdzRouteDeepLink() {
  try {
    var hash = window.location.hash || '';
    var m = hash.match(/#tsa-session=(\d+)/);
    if (!m) return;
    var sessionId = parseInt(m[1], 10);
    if (!sessionId) return;
    var hasAnalytics = (typeof zdzData !== 'undefined') && Array.isArray(zdzData.apps)
      && zdzData.apps.some(function (a) {
        return a.id === 'sales-analytics' || a.id === 'zdz-sales-analytics';
      });
    if (!hasAnalytics) return;
    // Open Analytics; pass the session as an option (forward-compatible) and leave
    // the hash intact so the widget can resolve '#tsa-session=' on init.
    openApp('sales-analytics', { session: sessionId, source: 'digest-deeplink' });
  } catch (e) { /* deep-link routing must never block boot */ }
}

// ---- KPI CLICK HANDLERS (v2.10.1 — Interactive dashboard KPIs) ----
function initKPIClicks() {
  // v2.14.4.3: Also wire stat-pill cards (sales/tech roles)
  document.querySelectorAll('.kpi-card[data-zdz-kpi-action], .stat-pill[data-zdz-kpi-action]').forEach(card => {
    card.addEventListener('click', () => {
      const action = card.dataset.zdzKpiAction || '';
      Haptics.tap();

      if (action.startsWith('analytics:')) {
        // Open Analytics with a pre-filled prompt
        const prompt = action.slice('analytics:'.length);
        openApp('sales-analytics', { prompt });
      } else if (action.startsWith('app:')) {
        // Navigate to the specified app (scroll to widget or open viewport)
        const appId = action.slice('app:'.length);
        openApp(appId);
      }
    });
  });
}

// ---- DASHBOARD WIDGETS (v2.0) ----
function renderWidgets() {
  const zone = document.getElementById('dash-widget-zone');
  if (!zone) return;

  let widgets = (zdzData.apps || []).filter(a => a.bridge_type === 'inline_widget' && a.widget_html);

  if (widgets.length === 0) {
    zone.style.display = 'none';
    return;
  }

  // v2.14.3.1: Sort by saved widget order. Widgets not in the saved
  // order appear at the end in their original registration order.
  const savedOrder = zdzData.widgetOrder || [];
  if (savedOrder.length > 0) {
    widgets.sort((a, b) => {
      const ai = savedOrder.indexOf(a.id);
      const bi = savedOrder.indexOf(b.id);
      // Both in saved order → sort by saved position
      if (ai !== -1 && bi !== -1) return ai - bi;
      // Only one in saved order → it comes first
      if (ai !== -1) return -1;
      if (bi !== -1) return 1;
      // Neither → keep original order
      return 0;
    });
  }

  zone.style.display = '';
  zone.innerHTML = widgets.map((w, i) => {
    const isFirst = i === 0;
    const isLast = i === widgets.length - 1;
    const arrowUp = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>`;
    const arrowDown = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>`;

    return `
    <div class="dash-widget-container" data-app-id="${w.id}">
      <div class="dash-widget-header">
        <div class="dw-icon" style="background:${w.cc}"><i data-lucide="${w.icon}"></i></div>
        <h3 class="dw-title">${w.nm}</h3>
        <div class="dw-reorder" aria-label="Reorder widget">
          <button class="dw-reorder-btn" data-dir="up" ${isFirst ? 'disabled' : ''} aria-label="Move up" title="Move up">${arrowUp}</button>
          <button class="dw-reorder-btn" data-dir="down" ${isLast ? 'disabled' : ''} aria-label="Move down" title="Move down">${arrowDown}</button>
        </div>
      </div>
      <div class="dash-widget-body">
        ${w.widget_html}
      </div>
    </div>
  `}).join('');

  // v2.20.3: Activate inline <script> tags inside widget HTML.
  // innerHTML injection silently discards scripts (browser security).
  // Re-create them as live script elements so plugin widgets can
  // include inline JS alongside their HTML. External scripts (src)
  // are also handled for completeness.
  // v2.20.3.1: Copy ALL attributes (type, async, defer, crossorigin,
  // data-*, nonce) so future plugins using module scripts or deferred
  // loading work without surprises.
  zone.querySelectorAll('.dash-widget-body script').forEach(dead => {
    const live = document.createElement('script');
    Array.from(dead.attributes).forEach(a => live.setAttribute(a.name, a.value));
    if (!dead.src) { live.textContent = dead.textContent; }
    dead.parentNode.replaceChild(live, dead);
  });

  refreshIcons();
  initWidgetReorder();

  // Notify widget scripts that the DOM is ready
  document.dispatchEvent(new Event('zdz_widgets_rendered'));
}

// v2.14.3.1: Widget reorder — arrow buttons move widgets up/down
// with a smooth animation, then save the order via /zorderz/v1/user-prefs.
function initWidgetReorder() {
  const zone = document.getElementById('dash-widget-zone');
  if (!zone) return;

  zone.addEventListener('click', function (e) {
    const btn = e.target.closest('.dw-reorder-btn');
    if (!btn || btn.disabled) return;

    const container = btn.closest('.dash-widget-container');
    if (!container) return;

    const dir = btn.dataset.dir;
    const sibling = dir === 'up' ? container.previousElementSibling : container.nextElementSibling;
    if (!sibling || !sibling.classList.contains('dash-widget-container')) return;

    // Animate the swap
    const containerRect = container.getBoundingClientRect();
    const siblingRect = sibling.getBoundingClientRect();
    const gap = parseInt(getComputedStyle(zone).gap) || 16;

    if (dir === 'up') {
      const dist = containerRect.top - siblingRect.top;
      const distBack = siblingRect.bottom - containerRect.bottom + gap - (containerRect.top - siblingRect.top - gap);
      container.style.transition = 'transform 0.25s ease';
      sibling.style.transition = 'transform 0.25s ease';
      container.style.transform = `translateY(-${dist}px)`;
      sibling.style.transform = `translateY(${containerRect.height + gap}px)`;

      setTimeout(() => {
        container.style.transition = '';
        container.style.transform = '';
        sibling.style.transition = '';
        sibling.style.transform = '';
        zone.insertBefore(container, sibling);
        updateReorderButtons();
        saveWidgetOrder();
      }, 260);
    } else {
      const dist = siblingRect.top - containerRect.top;
      container.style.transition = 'transform 0.25s ease';
      sibling.style.transition = 'transform 0.25s ease';
      container.style.transform = `translateY(${dist}px)`;
      sibling.style.transform = `translateY(-${containerRect.height + gap}px)`;

      setTimeout(() => {
        container.style.transition = '';
        container.style.transform = '';
        sibling.style.transition = '';
        sibling.style.transform = '';
        zone.insertBefore(sibling, container);
        updateReorderButtons();
        saveWidgetOrder();
      }, 260);
    }

    Haptics.tap();
  });
}

function updateReorderButtons() {
  const zone = document.getElementById('dash-widget-zone');
  if (!zone) return;
  const items = zone.querySelectorAll('.dash-widget-container');
  items.forEach((item, i) => {
    const upBtn = item.querySelector('.dw-reorder-btn[data-dir="up"]');
    const downBtn = item.querySelector('.dw-reorder-btn[data-dir="down"]');
    if (upBtn) upBtn.disabled = (i === 0);
    if (downBtn) downBtn.disabled = (i === items.length - 1);
  });
}

function saveWidgetOrder() {
  const zone = document.getElementById('dash-widget-zone');
  if (!zone) return;
  const order = Array.from(zone.querySelectorAll('.dash-widget-container'))
    .map(el => el.dataset.appId)
    .filter(Boolean);

  // Update local state so pull-to-refresh preserves the order
  zdzData.widgetOrder = order;

  // Persist via REST
  fetch(zdzData.apiUrl + 'user-prefs', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': zdzData.nonce },
    body: JSON.stringify({ widget_order: order }),
    credentials: 'same-origin'
  }).catch(() => {}); // silent — order is already applied visually
}

// ---- SETTINGS ----
function renderSettings() {
  const area = document.getElementById('profile-card-area');
  if (area) {
    area.innerHTML = `
      <div class="profile-card">
        <div class="profile-avatar">${state.user.ini}</div>
        <div class="profile-info">
          <h3>${state.user.name}</h3>
          <p>${state.user.label}</p>
        </div>
      </div>
    `;
  }

  const themes = [
    { id: 'system', label: 'Auto', preview: 'linear-gradient(135deg,#F8FAFC 50%,#1E293B 50%)' },
    { id: 'light', label: 'Light', preview: '#F8FAFC' },
    { id: 'dark', label: 'Dark', preview: '#1E293B' },
    { id: 'sunlight', label: 'Sun ☀', preview: '#FFFFFF' }
  ];
  const tgrid = document.getElementById('theme-grid');
  if (tgrid) {
    // v2.31.0: Larger text (accessibility) — rendered with the theme grid so
    // display prefs live together. Toggles data-zdz-textscale="lg" on <html>.
    const tscOn = document.documentElement.getAttribute('data-zdz-textscale') === 'lg';
    tgrid.innerHTML = themes.map(t => `
      <button class="theme-btn ${state.theme === t.id ? 'active' : ''}" onclick="applyTheme('${t.id}');renderSettings()" aria-label="Theme: ${t.label}">
        <div class="tb-preview" style="background:${t.preview}"></div>
        ${t.label}
      </button>
    `).join('') + `
      <button class="theme-btn ${tscOn ? 'active' : ''}" onclick="toggleTextScale();renderSettings()" aria-pressed="${tscOn}" aria-label="Larger text ${tscOn ? 'on' : 'off'}">
        <div class="tb-preview" style="display:flex;align-items:center;justify-content:center;gap:2px;font-weight:800;color:var(--sys-text);background:var(--sys-surface-raised)"><span style="font-size:12px">A</span><span style="font-size:18px">A</span></div>
        Larger text
      </button>`;
  }

  const sinfo = document.getElementById('settings-info');
  if (sinfo) {
    const isOnline = navigator.onLine;
    sinfo.innerHTML = `
      <h4>System</h4>
      <div class="settings-row">
        <div class="sr-left"><i data-lucide="wifi"></i><span class="sr-label">Network</span></div>
        <div class="sr-right"><span class="status-badge ${isOnline ? 'online' : 'offline'}"><span class="status-dot"></span>${isOnline ? 'Online' : 'Offline'}</span></div>
      </div>
      <div class="settings-row">
        <div class="sr-left"><i data-lucide="cpu"></i><span class="sr-label">Version</span></div>
        <div class="sr-right">${zdzData.themeVersion || 'v6.0'}</div>
      </div>
      <div class="settings-row">
        <div class="sr-left"><i data-lucide="shield"></i><span class="sr-label">Role</span></div>
        <div class="sr-right">${state.user.label}</div>
      </div>
      <div class="settings-row">
        <div class="sr-left"><i data-lucide="layout-grid"></i><span class="sr-label">Apps Available</span></div>
        <div class="sr-right">${getVisibleApps().length}</div>
      </div>
      ${state.isAdmin ? `<div class="settings-row mt-4">
        <button class="btn btn-outline w-full" onclick="window.location.href='/wp-admin/'">
          <i data-lucide="settings-2"></i> WordPress Admin
        </button>
      </div>` : ''}
      ${zdzData.canEnterKiosk ? `<div class="settings-row mt-4">
        <button class="btn btn-outline w-full" onclick="enterKioskDemo()">
          <i data-lucide="monitor-smartphone"></i> Enter Demo / Kiosk Mode
        </button>
      </div>` : ''}
      ${zdzData.kioskDemoActive ? `<div class="settings-row mt-4">
        <button class="btn btn-outline w-full zdz-kiosk-exit-btn" onclick="exitKioskDemo()">
          <i data-lucide="lock-open"></i> Exit Demo Mode (PIN)
        </button>
      </div>` : ''}
      ${zdzData.kioskDemoActive ? '' : `<div class="settings-row mt-4">
        <button class="btn btn-outline w-full" onclick="if(window.confirm('Log out of this account?')){window.location.href='${zdzData.logoutUrl}';}">
          <i data-lucide="log-out"></i> Logout
        </button>
      </div>`}
    `;
  }

  // v2.20.2: Field Preferences card (after System, before App Authorizations)
  renderFieldPreferences();

  // v2.11.0 / v2.13.0: App Authorizations card — FreshBooks + Nutshell.
  renderAppAuthorizations();
}

// ---- KIOSK / DEMO MODE (v2.21.0) ------------------------------------------------
// One-tap, PIN-protected switch into the General (shared-kiosk) account so an
// admin can hand the device to a guest for a demo. The actual identity switch
// is enforced SERVER-SIDE (ZDZ_Kiosk_Demo): for the duration of demo mode every
// request runs as the real General account, so the guest inherits the kiosk's
// all-deny permissions, ephemeral chat, read-only messaging, and locked-down
// dashboard — and cannot reach admin powers even via the API. The admin's true
// login session is untouched and is restored on exit. These client functions
// are just the affordance + PIN prompt; the server is the source of truth.

function enterKioskDemo() {
  Haptics && Haptics.tap && Haptics.tap();
  var pin = window.prompt(
    'Set a PIN to enter Demo / Kiosk Mode.\n\n' +
    'The device will behave as the shared General account. You will need ' +
    'this PIN to exit and return to your admin session, so the person you ' +
    'hand the device to cannot leave demo mode.\n\n' +
    'Enter 4–10 digits:'
  );
  if (pin === null) return;            // cancelled
  pin = (pin || '').replace(/\D/g, '');
  if (pin.length < 4 || pin.length > 10) {
    showToast('PIN must be 4–10 digits.');
    return;
  }
  var confirmPin = window.prompt('Re-enter the PIN to confirm:');
  if (confirmPin === null) return;
  if ((confirmPin || '').replace(/\D/g, '') !== pin) {
    showToast('PINs did not match. Try again.');
    return;
  }

  showToast('Entering Demo Mode…');
  fetch(zdzData.apiUrl + 'kiosk-demo/enter', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': zdzData.nonce },
    body: JSON.stringify({ pin: pin })
  })
    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
    .then(function (res) {
      if (!res.ok || !res.j || res.j.code) {
        var msg = (res.j && (res.j.message || res.j.code)) ? (res.j.message || res.j.code) : 'Could not enter demo mode.';
        showToast(typeof msg === 'string' ? msg : 'Could not enter demo mode.');
        return;
      }
      // The next request runs as the General account; subsequent SPA calls
      // must use the kiosk-identity nonce returned here.
      if (res.j.nonce) { zdzData.nonce = res.j.nonce; }
      window.location.href = (res.j.redirect || '/');
    })
    .catch(function () { showToast('Network error entering demo mode.'); });
}

function exitKioskDemo() {
  Haptics && Haptics.tap && Haptics.tap();
  var pin = window.prompt('Enter the PIN to exit Demo Mode and return to admin:');
  if (pin === null) return;            // cancelled — stay in demo mode
  pin = (pin || '').replace(/\D/g, '');
  if (pin === '') { showToast('PIN required to exit.'); return; }

  showToast('Verifying…');
  fetch(zdzData.apiUrl + 'kiosk-demo/exit', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': zdzData.nonce },
    body: JSON.stringify({ pin: pin })
  })
    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
    .then(function (res) {
      if (!res.ok || !res.j || res.j.code) {
        // Wrong PIN (403) or other error — remain in demo mode.
        showToast('Incorrect PIN.');
        return;
      }
      if (res.j.nonce) { zdzData.nonce = res.j.nonce; }
      window.location.href = (res.j.redirect || '/');
    })
    .catch(function () { showToast('Network error. Still in demo mode.'); });
}

// Persistent indicator so it's always obvious the device is in safe demo mode.
// Rendered once at boot when kioskDemoActive is true. It is intentionally
// minimal and non-interactive except for the PIN-gated exit, so a guest can
// see the state but cannot escalate.
function renderKioskDemoBanner() {
  if (!zdzData.kioskDemoActive) return;
  if (document.getElementById('zdz-kiosk-banner')) return;
  var bar = document.createElement('div');
  bar.id = 'zdz-kiosk-banner';
  bar.setAttribute('role', 'status');
  bar.innerHTML =
    '<span class="zdz-kiosk-banner-label">' +
    '<i data-lucide="shield-check"></i> Demo Mode — shared kiosk view</span>' +
    '<button class="zdz-kiosk-banner-exit" onclick="exitKioskDemo()">Exit (PIN)</button>';
  document.body.appendChild(bar);
  if (window.lucide && lucide.createIcons) { try { lucide.createIcons(); } catch (e) {} }
}


// Collapsible card for per-salesperson notation, walkthrough, and estimation habits.
// Visible to salesperson-level roles and above. Loads lazily on first expand.
var _fpLoaded = false;

function renderFieldPreferences() {
  var fieldRoles = ['administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_tech'];
  if (fieldRoles.indexOf(state.role) === -1) return;

  var sinfo = document.getElementById('settings-info');
  if (!sinfo) return;

  var card = document.getElementById('zdz-field-prefs-card');
  if (!card) {
    card = document.createElement('div');
    card.id = 'zdz-field-prefs-card';
    card.className = 'settings-section zdz-field-prefs-card';
    sinfo.after(card);
  }

  _fpLoaded = false;
  card.innerHTML =
    '<div class="zdz-fp-header" id="zdz-fp-toggle" role="button" tabindex="0" aria-expanded="false" aria-controls="zdz-fp-body">' +
      '<div class="zdz-fp-header-left"><i data-lucide="clipboard-list"></i><h4>Field Preferences</h4></div>' +
      '<i data-lucide="chevron-down" class="zdz-fp-chevron" id="zdz-fp-chevron"></i>' +
    '</div>' +
    '<div id="zdz-fp-body" class="zdz-fp-body" style="display:none">' +
      '<p class="zdz-fp-hint">How you write notes, abbreviations, and walkthrough patterns. ' +
      'Brain Bot and the Estimate Creator use this to parse your handwritten estimates accurately.</p>' +
      '<div id="zdz-fp-fields"><div class="zdz-fp-loading">Loading…</div></div>' +
    '</div>';
  refreshIcons();

  var fpToggle = document.getElementById('zdz-fp-toggle');
  function toggleFieldPrefs() {
    var body = document.getElementById('zdz-fp-body');
    var chevron = document.getElementById('zdz-fp-chevron');
    var willOpen = body.style.display === 'none';
    if (willOpen) {
      body.style.display = 'block';
      if (chevron) chevron.style.transform = 'rotate(180deg)';
      if (!_fpLoaded) loadFieldPreferences();
    } else {
      body.style.display = 'none';
      if (chevron) chevron.style.transform = '';
    }
    fpToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
  }
  fpToggle.addEventListener('click', toggleFieldPrefs);
  // Keyboard parity: the header is role="button", so Enter/Space must toggle it.
  fpToggle.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
      e.preventDefault();
      toggleFieldPrefs();
    }
  });
}

function loadFieldPreferences() {
  _fpLoaded = true;
  // Fetch schema and profile in parallel — schema is the single source of truth
  Promise.all([
    fetch(zdzData.apiUrl + 'user-profile/field-preferences-schema', {
      headers: { 'X-WP-Nonce': zdzData.nonce },
      credentials: 'same-origin'
    }).then(function(r) { return r.json(); }),
    fetch(zdzData.apiUrl + 'user-profile', {
      headers: { 'X-WP-Nonce': zdzData.nonce },
      credentials: 'same-origin'
    }).then(function(r) { return r.json(); })
  ])
  .then(function(results) {
    var schemaResp = results[0];
    var profileResp = results[1];
    if (!schemaResp || !schemaResp.success) throw new Error('schema-load-failed');
    if (!profileResp || !profileResp.success) throw new Error('profile-load-failed');

    var schemaFields = schemaResp.fields; // [{key, type, label, hint}, ...]
    var fp = profileResp.profile.field_preferences || {};
    var tsecProfile = profileResp.profile.tsec_notation_profile || {};

    // One-time migration offer: if zdz_field_preferences is empty but
    // tsec_notation_profile has data, pre-populate the form fields.
    var isEmpty = Object.keys(fp).length === 0;
    var hasTsec = Object.keys(tsecProfile).length > 0;
    if (isEmpty && hasTsec) {
      fp = migrateFromTsecProfile(tsecProfile, schemaFields);
    }

    renderFieldPreferenceFields(schemaFields, fp, isEmpty && hasTsec);
  })
  .catch(function() {
    var c = document.getElementById('zdz-fp-fields');
    if (c) c.innerHTML = '<p class="zdz-fp-error">Failed to load preferences. Try again later.</p>';
  });
}

function migrateFromTsecProfile(tsec, schemaFields) {
  // Map tsec_notation_profile keys to the new zdz_field_preferences schema.
  // Only migrate keys that exist in the current schema.
  var migrated = {};
  schemaFields.forEach(function(f) {
    if (tsec[f.key]) {
      if (f.type === 'string_array') {
        migrated[f.key] = Array.isArray(tsec[f.key]) ? tsec[f.key] : [tsec[f.key]];
      } else {
        migrated[f.key] = String(tsec[f.key]);
      }
    }
  });
  return migrated;
}

function renderFieldPreferenceFields(schemaFields, fp, isMigration) {
  var html = '';

  if (isMigration) {
    html += '<div class="zdz-fp-migration-banner">' +
      '<i data-lucide="info"></i> ' +
      'Pre-populated from your Estimate Creator notation profile. Review and save to confirm.' +
    '</div>';
  }

  // Render form fields dynamically from schema
  schemaFields.forEach(function(f) {
    var val = fp[f.key] || (f.type === 'string_array' ? [] : '');
    var textVal = f.type === 'string_array' ? val.join('\n') : val;
    var rows = f.type === 'string_array' ? 3 : 2;
    html +=
      '<div class="zdz-fp-field">' +
        '<label class="zdz-fp-label" for="zdz-fp-' + f.key + '">' + escapeHtml(f.label) + '</label>' +
        '<p class="zdz-fp-field-hint">' + escapeHtml(f.hint) + '</p>' +
        '<textarea id="zdz-fp-' + f.key + '" class="zdz-fp-textarea" data-fp-key="' + f.key + '" data-fp-type="' + f.type + '" ' +
          'rows="' + rows + '" placeholder="Not set">' +
          escapeHtml(textVal) +
        '</textarea>' +
      '</div>';
  });

  html += '<div class="zdz-fp-actions">' +
    '<button class="btn btn-brand" id="zdz-fp-save-btn"><i data-lucide="save"></i> Save Field Preferences</button>' +
  '</div>';

  var container = document.getElementById('zdz-fp-fields');
  if (container) {
    container.innerHTML = html;
    refreshIcons();

    // Auto-resize textareas
    container.querySelectorAll('.zdz-fp-textarea').forEach(function(ta) {
      autoResizeTextarea(ta);
      ta.addEventListener('input', function() { autoResizeTextarea(this); });
    });

    document.getElementById('zdz-fp-save-btn').addEventListener('click', saveFieldPreferences);
  }
}

function autoResizeTextarea(ta) {
  ta.style.height = 'auto';
  ta.style.height = Math.max(ta.scrollHeight, 56) + 'px';
}

function saveFieldPreferences() {
  var btn = document.getElementById('zdz-fp-save-btn');
  if (!btn || btn.disabled) return;
  btn.disabled = true;
  btn.textContent = 'Saving…';

  // Build payload from DOM — types come from schema via data-fp-type attributes
  var payload = {};
  document.querySelectorAll('.zdz-fp-textarea').forEach(function(ta) {
    var key = ta.getAttribute('data-fp-key');
    var type = ta.getAttribute('data-fp-type');
    var raw = ta.value.trim();
    if (!raw) return;
    if (type === 'string_array') {
      payload[key] = raw.split('\n').map(function(s) { return s.trim(); }).filter(Boolean);
    } else {
      payload[key] = raw;
    }
  });

  fetch(zdzData.apiUrl + 'user-profile/field-preferences', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': zdzData.nonce },
    body: JSON.stringify(payload),
    credentials: 'same-origin'
  })
  .then(function(r) { return r.json(); })
  .then(function(resp) {
    if (!resp || !resp.success) throw new Error(resp.error || 'save-failed');
    showToast('Field preferences saved');
    if (typeof Haptics !== 'undefined') Haptics.success();
  })
  .catch(function(e) {
    showToast('Save failed — ' + (e.message || 'try again'));
    if (typeof Haptics !== 'undefined') Haptics.error();
  })
  .finally(function() {
    btn.disabled = false;
    btn.innerHTML = '<i data-lucide="save"></i> Save Field Preferences';
    refreshIcons();
  });
}

// ---- APP AUTHORIZATIONS (v2.11.0 / v2.13.0) --------------------------------
function renderAppAuthorizations() {
  const sinfo = document.getElementById('settings-info');
  if (!sinfo) return;

  let card = document.getElementById('zdz-app-auth-card');
  if (!card) {
    card = document.createElement('div');
    card.id = 'zdz-app-auth-card';
    card.className = 'settings-section zdz-app-auth-card';
    card.innerHTML = `
      <h4>App Authorizations</h4>
      <div id="zdz-app-auth-rows" class="zdz-app-auth-rows">
        <div class="settings-row"><div class="sr-left"><span class="sr-label">Loading…</span></div></div>
      </div>
    `;
    // Insert after Field Preferences card, or after System info if no field prefs
    var anchor = document.getElementById('zdz-field-prefs-card') || sinfo;
    anchor.after(card);
  }

  fetch(zdzData.apiUrl + 'app-authorizations', {
    headers: { 'X-WP-Nonce': zdzData.nonce },
    credentials: 'same-origin'
  })
  .then(r => r.json())
  .then(resp => {
    if (!resp || !resp.success) throw new Error('auth-status-failed');
    const d  = resp.data || {};
    const fb = d.freshbooks || {};
    const ns = d.nutshell   || {};
    const cal = d.calendars || null; // v1.1.0 — Connected Calendars (present only when a scheduler plugin registers it via zdz_app_authorizations)
    const rows = document.getElementById('zdz-app-auth-rows');
    if (!rows) return;

    const isAdmin = !!(zdzData && zdzData.isAdmin);

    const badge = on => on
      ? '<span class="status-badge online"><span class="status-dot"></span>Connected</span>'
      : '<span class="status-badge offline"><span class="status-dot"></span>Not connected</span>';

    // ---- FreshBooks row (unchanged — per-user flow) ----
    const fbBtnLabel = fb.connected ? 'Re-authorize FreshBooks' : 'Authorize FreshBooks';
    const fbDisabled = fb.configured ? '' : 'disabled';
    const fbHint = fb.configured
      ? 'Connects this account to FreshBooks so estimates and invoices can be created on your behalf.'
      : 'Waiting for an administrator to paste the FreshBooks Client ID and Client Secret in wp-admin.';

    // ---- Nutshell row (company-wide — v2.13.0) ----
    let nsHint, nsAction;
    if (ns.connected && isAdmin) {
      nsHint = 'Company account is connected as <strong>' + escapeHtml(ns.email || '') + '</strong>. ' +
               'Everyone on the team uses this same Nutshell connection — no individual setup required.';
      nsAction = `
        <div class="settings-row mt-4">
          <button id="zdz-authorize-ns-btn" class="btn btn-outline w-full">
            <i data-lucide="key-round"></i> Update Company Credentials
          </button>
        </div>`;
    } else if (ns.connected && !isAdmin) {
      nsHint = 'Your team\'s Nutshell connection is active. Nothing for you to set up — estimates and leads sync automatically through the company account.';
      nsAction = ''; // read-only for non-admins
    } else if (!ns.connected && isAdmin) {
      nsHint = 'Set this once on behalf of the whole team. You\'ll need the company\'s Nutshell login email + an API key from <strong>Nutshell → Setup → API Keys</strong>. Every Zorderz user will inherit this connection.';
      nsAction = `
        <div class="settings-row mt-4">
          <button id="zdz-authorize-ns-btn" class="btn btn-brand w-full">
            <i data-lucide="key-round"></i> Set Company Credentials
          </button>
        </div>`;
    } else {
      nsHint = '<em>Not yet connected.</em> Ask an administrator to configure Nutshell from their Settings view — once set, it works for everyone automatically.';
      nsAction = ''; // no button for non-admins
    }

    // ---- Connected Calendars block (v1.1.0 — scheduler plugin, per-user) ----
    // Rendered only when a scheduler plugin registers `calendars` via the
    // zdz_app_authorizations filter (typically a per-user, non-kiosk
    // connection). The button deep-links into the schedule widget's existing
    // connect modal via a neutral query var the scheduler plugin listens for.
    let calBlock = '';
    if (cal) {
      const calHint = cal.connected
        ? 'Your outside calendars (Google / Microsoft Exchange) are connected — the team scheduler treats their events as busy time. Only you see the details; teammates just see that you\'re busy.'
        : 'Connect your own Google or Microsoft (Exchange) calendar so the team scheduler knows when you\'re busy outside this app. Only you see event details — teammates just see busy time.';
      const calBtnLabel = cal.connected
        ? ('Manage Calendars' + (cal.count ? ' (' + cal.count + ')' : ''))
        : 'Connect a Calendar';
      const calBtnClass = cal.connected ? 'btn-outline' : 'btn-brand';
      calBlock = `
      <div class="zdz-auth-divider"></div>
      <div class="settings-row">
        <div class="sr-left"><i data-lucide="calendar-check"></i><span class="sr-label">Connected Calendars <span class="zdz-auth-scope">(your calendars)</span></span></div>
        <div class="sr-right">${badge(!!cal.connected)}</div>
      </div>
      <p class="zdz-auth-hint">${calHint}</p>
      <div class="settings-row mt-4">
        <button id="zdz-connect-cal-btn" class="btn ${calBtnClass} w-full">
          <i data-lucide="calendar-plus"></i> ${calBtnLabel}
        </button>
      </div>`;
    }

    rows.innerHTML = `
      <div class="settings-row">
        <div class="sr-left"><i data-lucide="link"></i><span class="sr-label">FreshBooks</span></div>
        <div class="sr-right">${badge(fb.connected)}</div>
      </div>
      <p class="zdz-auth-hint">${fbHint}</p>
      <div class="settings-row mt-4">
        <button id="zdz-authorize-fb-btn" class="btn btn-brand w-full" ${fbDisabled}>
          <i data-lucide="shield-check"></i> ${fbBtnLabel}
        </button>
      </div>

      <div class="zdz-auth-divider"></div>

      <div class="settings-row">
        <div class="sr-left"><i data-lucide="users"></i><span class="sr-label">Nutshell CRM <span class="zdz-auth-scope">(company account)</span></span></div>
        <div class="sr-right">${badge(ns.connected)}</div>
      </div>
      <p class="zdz-auth-hint">${nsHint}</p>
      ${nsAction}
      ${calBlock}
    `;

    const fbBtn = document.getElementById('zdz-authorize-fb-btn');
    if (fbBtn && !fbBtn.disabled) fbBtn.addEventListener('click', startFreshBooksAuthorize);

    // Only wire up the Nutshell button if it actually exists (admin paths only)
    const nsBtn = document.getElementById('zdz-authorize-ns-btn');
    if (nsBtn) nsBtn.addEventListener('click', () => openNutshellModal(ns.email || ''));

    // v1.1.0 — Connected Calendars: deep-link into the schedule widget's connect
    // modal (the scheduler plugin owns the OAuth flow; we just route to it via a
    // neutral namespaced query var the scheduler port must listen for).
    const calBtn = document.getElementById('zdz-connect-cal-btn');
    if (calBtn) calBtn.addEventListener('click', () => {
      window.location.href = window.location.origin + '/?zdz_connect_calendar=open';
    });

    refreshIcons();
  })
  .catch(() => {
    const rows = document.getElementById('zdz-app-auth-rows');
    if (rows) rows.innerHTML = '<div class="settings-row"><div class="sr-left"><span class="sr-label">Unavailable right now</span></div></div>';
  });
}

function escapeHtml(str) {
  return String(str).replace(/[&<>"']/g, function (c) {
    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'})[c];
  });
}

// v2.11.0: Start the FreshBooks OAuth flow from the front-end.
function startFreshBooksAuthorize() {
  const btn = document.getElementById('zdz-authorize-fb-btn');
  if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
  showToast('Opening FreshBooks…');
  fetch(zdzData.apiUrl + 'fb-auth-start', {
    headers: { 'X-WP-Nonce': zdzData.nonce },
    credentials: 'same-origin'
  })
  .then(r => r.json().then(j => ({ ok: r.ok, body: j })))
  .then(({ ok, body }) => {
    if (!ok || !body || !body.success) {
      const msg = (body && body.message) ? body.message : 'Unable to start FreshBooks authorization.';
      if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); }
      showToast(msg, 6000);
      return;
    }
    window.location.href = body.data.auth_url;
  })
  .catch(() => {
    if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); }
    showToast('Network error. Please try again.', 5000);
  });
}

// v2.13.0: Nutshell authorize modal — admin-only. Sets the COMPANY credentials.
function openNutshellModal(currentEmail) {
  closeNutshellModal(); // idempotent

  const overlay = document.createElement('div');
  overlay.id = 'zdz-ns-overlay';
  overlay.className = 'zdz-ns-overlay';
  overlay.innerHTML = `
    <div class="zdz-ns-modal" role="dialog" aria-modal="true" aria-labelledby="zdz-ns-title">
      <div class="zdz-ns-header">
        <h3 id="zdz-ns-title"><i data-lucide="key-round"></i> Nutshell — Company Credentials</h3>
        <button type="button" class="btn-icon" id="zdz-ns-close" aria-label="Close">
          <i data-lucide="x" style="width:20px;height:20px"></i>
        </button>
      </div>
      <div class="zdz-ns-body">
        <p class="zdz-ns-hint" style="margin-bottom:4px">
          These credentials are shared by <strong>every Zorderz user</strong>. Paste the
          company Nutshell login + API key once — the rest of the team will not need to do anything.
        </p>
        <label class="zdz-ns-label">Nutshell Login Email
          <input type="email" id="zdz-ns-email" autocomplete="email"
                 value="${escapeHtml(currentEmail || '')}"
                 placeholder="you@company.com" />
        </label>
        <label class="zdz-ns-label">API Key
          <input type="password" id="zdz-ns-key" autocomplete="off"
                 placeholder="Paste your Nutshell API key" />
        </label>
        <p class="zdz-ns-hint">
          Find the key at <strong>Nutshell → Setup → API Keys</strong>. Stored encrypted
          and cascaded to all Zorderz apps (Estimates, Surveys, Leads, Analytics).
        </p>
        <div class="zdz-ns-error" id="zdz-ns-error" style="display:none"></div>
      </div>
      <div class="zdz-ns-footer">
        <button type="button" class="btn btn-outline" id="zdz-ns-cancel">Cancel</button>
        <button type="button" class="btn btn-brand"   id="zdz-ns-save">
          <i data-lucide="save"></i> Save &amp; Connect
        </button>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
  refreshIcons();
  setTimeout(() => { overlay.classList.add('show'); }, 10);

  const close = () => closeNutshellModal();
  overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
  document.getElementById('zdz-ns-close').addEventListener('click', close);
  document.getElementById('zdz-ns-cancel').addEventListener('click', close);
  document.getElementById('zdz-ns-save').addEventListener('click', saveNutshellCredentials);

  // Focus handling
  const emailInput = document.getElementById('zdz-ns-email');
  const keyInput   = document.getElementById('zdz-ns-key');
  setTimeout(() => { (currentEmail ? keyInput : emailInput).focus(); }, 60);

  // Enter submits
  [emailInput, keyInput].forEach(el => {
    el.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); saveNutshellCredentials(); }
      if (e.key === 'Escape') { close(); }
    });
  });
}

function closeNutshellModal() {
  const o = document.getElementById('zdz-ns-overlay');
  if (o) o.remove();
}

function saveNutshellCredentials() {
  const email = (document.getElementById('zdz-ns-email') || {}).value || '';
  const key   = (document.getElementById('zdz-ns-key')   || {}).value || '';
  const err   = document.getElementById('zdz-ns-error');
  const btn   = document.getElementById('zdz-ns-save');

  const show = msg => { if (err) { err.textContent = msg; err.style.display = 'block'; } };
  if (err) { err.style.display = 'none'; err.textContent = ''; }

  if (!email || email.indexOf('@') < 1) { show('Please enter a valid email address.'); return; }
  if (!key || key.length < 16) { show('That API key looks too short. Double-check the value from Nutshell.'); return; }

  if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }

  fetch(zdzData.apiUrl + 'ns-auth-save', {
    method: 'POST',
    headers: {
      'X-WP-Nonce': zdzData.nonce,
      'Content-Type': 'application/json'
    },
    credentials: 'same-origin',
    body: JSON.stringify({ email: email, api_key: key })
  })
  .then(r => r.json().then(j => ({ ok: r.ok, body: j })))
  .then(({ ok, body }) => {
    if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); }
    if (!ok || !body || !body.success) {
      show((body && body.message) ? body.message : 'Unable to save Nutshell credentials.');
      return;
    }
    closeNutshellModal();
    showToast('✓ Company Nutshell connection saved — all users inherit this.');
    renderAppAuthorizations(); // refresh status badge
  })
  .catch(() => {
    if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); }
    show('Network error. Please try again.');
  });
}

// ---- COMMAND PALETTE ----
// ---- COMMAND PALETTE ----
// v2.20.0: The older simple app-search version has been removed.
// The enhanced version with Brain Bot routing and fuzzy match is
// defined below (see the second initCommandPalette block).

// ---- APP VIEWPORT CONTROLS ----
function initAppViewport() {
  // Back button in the app header
  const backBtn = document.getElementById('app-back');
  if (backBtn) {
    backBtn.addEventListener('click', () => {
      if (window.Bridge && Bridge.currentApp) {
        // Go back in history so popstate fires cleanly
        history.back();
      }
    });
  }

  // Browser / phone back button closes the active app
  window.addEventListener('popstate', (e) => {
    if (window.Bridge && Bridge.currentApp) {
      Bridge.closeApp();
    }
  });
}

// ---- PULL-TO-REFRESH (v2.14.3.1) ----
// On iOS the browser chrome disappears on scroll, locking users out of the
// browser's refresh bar. The body has overscroll-behavior-y:none which kills
// the browser's native PTR. This adds a pull-down gesture at the top of any
// sub-view that triggers a full page reload.
//
// Does NOT conflict with plugin-level PTR (TSA, TSIM) because those attach
// to their own internal scroll containers (#tsa-w-messages, #zim-w-messages).
// The theme PTR only fires when the .sub-view itself is at scrollTop 0.
function initPullToRefresh() {
  const viewMain = document.getElementById('view-main');
  if (!viewMain) return;

  let startY = 0;
  let startX = 0;
  let pulling = false;
  let indicator = null;
  let startTime = 0;
  const threshold = 45;

  viewMain.addEventListener('touchstart', function (e) {
    // Skip interactive elements — long-pressing text, tapping inputs, etc.
    // Without this, text selection triggers a refresh.
    const tag = (e.target.tagName || '').toLowerCase();
    if (tag === 'textarea' || tag === 'input' || tag === 'select' || tag === 'button') return;
    if (e.target.isContentEditable) return;
    // Also skip if inside a contenteditable ancestor or a form control wrapper
    if (e.target.closest('textarea, input, select, button, [contenteditable="true"]')) return;

    // Find the currently active sub-view
    const active = viewMain.querySelector('.sub-view.active');
    if (!active) return;

    // Only activate when the sub-view is scrolled to its very top
    if (active.scrollTop <= 0) {
      // Don't activate if the touch originated inside a nested scrollable
      // that has its own PTR (e.g., .tsa-w-messages, .zim-w-messages).
      // Those elements handle their own pull-to-refresh.
      const nested = e.target.closest('.tsa-w-messages, #tsa-w-messages, .zim-w-messages, #zim-w-messages');
      if (nested && nested.scrollTop <= 0) return; // let the plugin PTR handle it
      if (nested) return; // nested scroller not at top — let it scroll normally

      startY = e.touches[0].clientY;
      startX = e.touches[0].clientX;
      startTime = Date.now();
      pulling = true;
    }
  }, { passive: true });

  viewMain.addEventListener('touchmove', function (e) {
    if (!pulling) return;
    const active = viewMain.querySelector('.sub-view.active');
    if (!active || active.scrollTop > 0) { pulling = false; return; }

    const deltaY = e.touches[0].clientY - startY;
    const deltaX = Math.abs(e.touches[0].clientX - startX);

    // If moving more horizontally than vertically, this is a swipe/selection — abort.
    if (deltaX > Math.abs(deltaY) + 5) { pulling = false; return; }

    // If touch has been held > 300ms without significant movement, it's a
    // long-press (text selection). Cancel PTR.
    if (deltaY < 15 && Date.now() - startTime > 300) { pulling = false; return; }

    if (deltaY > 15) {
      if (!indicator) {
        indicator = document.createElement('div');
        indicator.className = 'zdz-pull-indicator';
        indicator.textContent = '\u2193 Pull to refresh';
        active.insertBefore(indicator, active.firstChild);
      }

      const progress = Math.min(deltaY / threshold, 1);
      indicator.style.height = Math.min(deltaY * 0.5, 44) + 'px';
      indicator.style.opacity = progress;
      indicator.textContent = progress >= 1 ? '\u21BB Release to refresh' : '\u2193 Pull to refresh';
    }
  }, { passive: true });

  viewMain.addEventListener('touchend', function () {
    if (!pulling) return;
    pulling = false;

    if (indicator) {
      const wasReady = indicator.textContent.indexOf('Release') !== -1;
      indicator.remove();
      indicator = null;

      if (wasReady) {
        // Full page reload — the theme-level refresh re-fetches everything.
        showToast('Refreshing…');
        setTimeout(function () { window.location.reload(); }, 150);
      }
    }
  }, { passive: true });
}

// ---- LONG PRESS SUNLIGHT ----
function initLongPressSunlight() {
  // Long-press the Dashboard nav item to toggle sunlight mode
  const dashBtn = document.querySelector('.ni[data-view="sv-dash"]');
  if (!dashBtn) return;
  let timer = null;
  dashBtn.addEventListener('touchstart', () => {
    timer = setTimeout(() => {
      const newTheme = state.theme === 'sunlight' ? 'system' : 'sunlight';
      applyTheme(newTheme);
      renderSettings();
      showToast(newTheme === 'sunlight' ? '☀ Sunlight Mode ON' : '🌙 Sunlight Mode OFF');
      Haptics.success();
    }, 600);
  }, { passive: true });
  dashBtn.addEventListener('touchend', () => clearTimeout(timer));
  dashBtn.addEventListener('touchmove', () => clearTimeout(timer));
}

// ---- PREFETCH TOP APPS ----
function prefetchTopApps() {
  const apps = getVisibleApps();
  const pinned = PINNED[state.role] || [];
  const topApps = pinned.slice(0, 2).map(id => apps.find(a => a.id === id)).filter(Boolean);
  topApps.forEach(app => {
    if (app.bridge_type === 'iframe' && app.url) {
      const link = document.createElement('link');
      link.rel = 'prefetch';
      link.href = app.url;
      document.head.appendChild(link);
    }
  });
}

// ---- v2.16.0 T16: Human-readable time-ago helper for KPI freshness ----
function formatTimeAgo(ts) {
  if (!ts) return '';
  const diff = (Date.now() / 1000) - ts;
  if (diff < 60) return 'just now';
  if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
  if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
  return new Date(ts * 1000).toLocaleDateString();
}

// ---- v2.14.4 A4: APP DOCK (icon grid above KPI cards) ----
function renderAppDock() {
  var dock = document.getElementById('app-dock');
  if (!dock) return;
  var allApps = getVisibleApps();
  if (!allApps.length) { dock.style.display = 'none'; return; }
  if (allApps.length >= 7) dock.classList.add('dock-many');
  else dock.classList.remove('dock-many');
  var last = allApps.length - 1;
  dock.innerHTML = allApps.map(function (a, i) {
    var pos = i === 0 ? 'first' : i === last ? 'last' : '';
    return '<button class="dock-app" data-app-id="' + a.id + '" data-idx="' + i + '"' + (pos ? ' data-pos="' + pos + '"' : '') + ' aria-label="' + a.nm + '" title="' + a.nm + '">' +
      '<div class="dock-icon" style="background:' + a.cc + '"><i data-lucide="' + a.icon + '"></i></div>' +
      '<span class="dock-label' + longLabelClass(a.nm) + '">' + a.nm + '</span>' +
      '<div class="dock-reorder-arrows">' +
        '<span class="dock-arr dock-arr-left" data-dir="up" role="button" aria-label="Move left"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></span>' +
        '<span class="dock-arr dock-arr-right" data-dir="down" role="button" aria-label="Move right"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>' +
      '</div>' +
    '</button>';
  }).join('');
  refreshIcons();
  initDockReorder(dock);
}

/* ── v2.17.3: Unified Reorder System (dock + sticky bar) ──────── */
var _tsEditMode = false;

function getDockOrder(container) {
  var btns = container.querySelectorAll('[data-app-id]');
  var order = [];
  btns.forEach(function(b) { if (b.dataset.appId) order.push(b.dataset.appId); });
  return order;
}

/** Update data-idx and data-pos on all items in a container */
function reindexItems(container) {
  var items = container.querySelectorAll('[data-app-id]');
  var last = items.length - 1;
  items.forEach(function(b, i) {
    b.dataset.idx = i;
    if (i === 0) b.dataset.pos = 'first';
    else if (i === last) b.dataset.pos = 'last';
    else delete b.dataset.pos;
  });
}

/** Swap two adjacent items with animation */
function swapItems(container, idx, dir) {
  var items = Array.from(container.querySelectorAll('[data-app-id]'));
  var targetIdx = dir === 'up' ? idx - 1 : idx + 1;
  if (targetIdx < 0 || targetIdx >= items.length) return;
  var el = items[idx];
  var target = items[targetIdx];
  el.classList.add('dock-swapping');
  target.classList.add('dock-swapping');
  if (dir === 'up') {
    container.insertBefore(el, target);
  } else {
    container.insertBefore(target, el);
  }
  reindexItems(container);
  Haptics.tap();
  setTimeout(function() {
    el.classList.remove('dock-swapping');
    target.classList.remove('dock-swapping');
  }, 200);
}

/** After a swap in one container, reorder the other to match */
function syncContainers(sourceContainer) {
  var order = getDockOrder(sourceContainer);
  var dock = document.getElementById('app-dock');
  var strip = document.querySelector('.sticky-app-strip');
  var target = (sourceContainer === dock) ? strip : dock;
  if (!target) return;
  order.forEach(function(appId) {
    var node = target.querySelector('[data-app-id="' + appId + '"]');
    if (node) target.appendChild(node);
  });
  reindexItems(target);
}

function enterAppEditMode() {
  if (_tsEditMode) return;
  _tsEditMode = true;
  Haptics.success();
  var dock = document.getElementById('app-dock');
  var stickyWrap = document.getElementById('dash-sticky');
  var strip = stickyWrap ? stickyWrap.querySelector('.sticky-app-strip') : null;
  if (dock) dock.classList.add('dock-edit-mode');
  if (strip) strip.classList.add('dock-edit-mode');
  // Pin sticky bar visible so user can reorder there too
  if (stickyWrap) stickyWrap.classList.add('visible', 'edit-pinned');
  // Add compact Done button inside sticky bar (always reachable)
  if (stickyWrap && !stickyWrap.querySelector('.dock-edit-done-sticky')) {
    var stickyDone = document.createElement('button');
    stickyDone.className = 'dock-edit-done dock-edit-done-sticky';
    stickyDone.textContent = 'Done ✓';
    stickyDone.addEventListener('click', function(e) { e.stopPropagation(); exitAppEditMode(); });
    stickyDone.addEventListener('touchend', function(e) { e.preventDefault(); e.stopPropagation(); exitAppEditMode(); });
    stickyWrap.appendChild(stickyDone);
  }
  // Also add Done button below dock for when user is scrolled to top
  if (dock && !dock.parentNode.querySelector('.dock-edit-done:not(.dock-edit-done-sticky)')) {
    var done = document.createElement('button');
    done.className = 'dock-edit-done';
    done.textContent = 'Done Reordering';
    done.addEventListener('click', function(e) { e.stopPropagation(); exitAppEditMode(); });
    dock.parentNode.insertBefore(done, dock.nextSibling);
  }
  // Tap outside dock/sticky → exit edit mode (pointer + touch for iOS)
  setTimeout(function() {
    document.addEventListener('pointerdown', _tsEditOutsideHandler, true);
    document.addEventListener('touchend', _tsEditOutsideTouchHandler, true);
  }, 250);
}

function _tsEditOutsideHandler(e) {
  if (!_tsEditMode) return;
  var dock = document.getElementById('app-dock');
  var stickyWrap = document.getElementById('dash-sticky');
  if ((dock && dock.contains(e.target)) ||
      (stickyWrap && stickyWrap.contains(e.target)) ||
      e.target.closest('.dock-edit-done')) return;
  exitAppEditMode();
}

function _tsEditOutsideTouchHandler(e) {
  if (!_tsEditMode) return;
  // Use changedTouches for touchend
  var touch = e.changedTouches ? e.changedTouches[0] : null;
  if (!touch) return;
  var target = document.elementFromPoint(touch.clientX, touch.clientY);
  if (!target) return;
  var dock = document.getElementById('app-dock');
  var stickyWrap = document.getElementById('dash-sticky');
  if ((dock && dock.contains(target)) ||
      (stickyWrap && stickyWrap.contains(target)) ||
      target.closest('.dock-edit-done')) return;
  exitAppEditMode();
}

function exitAppEditMode() {
  if (!_tsEditMode) return;
  _tsEditMode = false;
  document.removeEventListener('pointerdown', _tsEditOutsideHandler, true);
  document.removeEventListener('touchend', _tsEditOutsideTouchHandler, true);
  var dock = document.getElementById('app-dock');
  var stickyWrap = document.getElementById('dash-sticky');
  var strip = stickyWrap ? stickyWrap.querySelector('.sticky-app-strip') : null;
  if (dock) dock.classList.remove('dock-edit-mode');
  if (strip) strip.classList.remove('dock-edit-mode');
  // Remove pinned state — let the scroll handler re-evaluate visibility
  if (stickyWrap) {
    stickyWrap.classList.remove('edit-pinned');
    stickyWrap.classList.remove('visible');
  }
  document.querySelectorAll('.dock-edit-done').forEach(function(d) { d.remove(); });
  // Save order from dock (canonical)
  if (dock) {
    var order = getDockOrder(dock);
    saveAppOrder(order);
  }
  showToast('App order saved ✓');
  // Rebuild sticky bar to match new order
  initStickyAppBar();
}

/** Attach long-press + chevron handlers to a container */
function initReorderHandlers(container, itemSelector, arrowSelector) {
  var longPressTimer = null;
  var startX = 0, startY = 0;

  container.addEventListener('pointerdown', function(e) {
    var btn = e.target.closest(itemSelector);
    if (!btn || e.target.closest(arrowSelector)) return;
    startX = e.clientX; startY = e.clientY;
    longPressTimer = setTimeout(function() {
      longPressTimer = null;
      enterAppEditMode();
    }, 500);
  });
  container.addEventListener('pointermove', function(e) {
    if (longPressTimer) {
      var dx = e.clientX - startX, dy = e.clientY - startY;
      if (Math.sqrt(dx*dx + dy*dy) > 10) { clearTimeout(longPressTimer); longPressTimer = null; }
    }
  });
  container.addEventListener('pointerup', function() {
    if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
  });
  container.addEventListener('pointercancel', function() {
    if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
  });

  container.addEventListener('click', function(e) {
    var arr = e.target.closest(arrowSelector);
    if (arr && _tsEditMode) {
      e.preventDefault(); e.stopPropagation();
      var btn = arr.closest(itemSelector);
      var idx = parseInt(btn.dataset.idx, 10);
      swapItems(container, idx, arr.dataset.dir);
      syncContainers(container);
      return;
    }
    if (_tsEditMode) { e.preventDefault(); e.stopPropagation(); return; }
    var appBtn = e.target.closest(itemSelector);
    if (appBtn && appBtn.dataset.appId) { openApp(appBtn.dataset.appId); }
  }, true);
}

function initDockReorder(dock) {
  initReorderHandlers(dock, '.dock-app', '.dock-arr');
}

// ---- v2.16.0 T4 / v2.17.3: STICKY APP BAR ----
function initStickyAppBar() {
  var dock = document.getElementById('app-dock');
  var sticky = document.getElementById('dash-sticky');
  var dashTop = document.getElementById('dash-top');
  if (!dock || !sticky) return;

  // Measure dash-top height for positioning
  function measureDashTop() {
    if (dashTop) {
      var h = dashTop.getBoundingClientRect().height;
      document.documentElement.style.setProperty('--dash-top-h', Math.round(h) + 'px');
    }
    // v2.20.0 r4: Measure the sticky icon bar height so widget headers
    // can stick below it instead of behind it. Set to 0 when hidden.
    if (sticky) {
      if (sticky.classList.contains('visible')) {
        var sh = sticky.getBoundingClientRect().height;
        document.documentElement.style.setProperty('--dash-sticky-h', Math.round(sh) + 'px');
      } else {
        document.documentElement.style.setProperty('--dash-sticky-h', '0px');
      }
    }
  }
  measureDashTop();
  var resizeTimer;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(measureDashTop, 150);
  });

  var allApps = getVisibleApps();
  if (!allApps.length) { sticky.style.display = 'none'; return; }
  var last = allApps.length - 1;

  var strip = document.createElement('div');
  strip.className = 'sticky-app-strip';
  strip.innerHTML = allApps.map(function (a, i) {
    var pos = i === 0 ? 'first' : i === last ? 'last' : '';
    return '<button class="sticky-app-btn" data-app-id="' + a.id + '" data-idx="' + i + '"' + (pos ? ' data-pos="' + pos + '"' : '') + ' aria-label="' + a.nm + '" title="' + a.nm + '">' +
      '<div class="sticky-app-icon" style="background:' + a.cc + '"><i data-lucide="' + a.icon + '"></i></div>' +
      '<span class="sticky-app-label">' + a.nm + '</span>' +
      '<div class="sticky-reorder-arrows">' +
        '<span class="sticky-arr sticky-arr-left" data-dir="up" role="button" aria-label="Move left"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></span>' +
        '<span class="sticky-arr sticky-arr-right" data-dir="down" role="button" aria-label="Move right"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>' +
      '</div>' +
    '</button>';
  }).join('');
  sticky.innerHTML = '';
  sticky.appendChild(strip);
  refreshIcons();

  // Reorder handlers for sticky bar
  initReorderHandlers(strip, '.sticky-app-btn', '.sticky-arr');

  // v2.17.2: Ensure sticky starts hidden, disconnect old observer/listener
  sticky.classList.remove('visible');
  if (window._tsStickyObs) { try { window._tsStickyObs.disconnect(); } catch(e){} }
  if (window._tsStickyScroll) {
    var oldRoot = document.getElementById('sv-dash') || dock.closest('.sub-view');
    if (oldRoot) oldRoot.removeEventListener('scroll', window._tsStickyScroll);
  }
  // Remove old sentinel if it exists
  var oldSentinel = document.getElementById('dock-sentinel');
  if (oldSentinel) oldSentinel.remove();

  var scrollRoot = document.getElementById('sv-dash') || dock.closest('.sub-view');
  if (scrollRoot) {
    // Calculate threshold: show sticky bar once 2 rows of the dock grid are scrolled
    // behind the fixed header. We measure the 4th dock-app child (end of row 2 on mobile)
    // or the 6th (end of row 2 on 3-col desktop). Fall back to a fixed pixel value.
    function _getStickyThreshold() {
      var apps = dock.querySelectorAll('.dock-app');
      // On a 2-col mobile grid, the 4th app ends row 2.
      // On a 3-col desktop grid, the 6th app ends row 2.
      // We detect by comparing the offsetTop of the 3rd vs 1st app.
      var cols = 2;
      if (apps.length >= 3 && apps[2].offsetTop === apps[0].offsetTop) cols = 3;
      var endOfRow2 = cols * 2; // 4 for 2-col, 6 for 3-col
      var target = apps[Math.min(endOfRow2 - 1, apps.length - 1)];
      if (target) {
        // We want the sticky bar to appear when this app has scrolled ABOVE the header.
        // Get bottom edge of target relative to the dock's top.
        return target.offsetTop + target.offsetHeight;
      }
      return 300; // fallback: ~300px
    }

    function _onDashScroll() {
      if (_tsEditMode) return;

      // v2.20.1: Re-measure dash-top height on EVERY scroll tick.
      // The old approach measured once on init, but the height can change
      // after font loading, icon rendering, or compact mode transitions.
      // A stale --dash-top-h causes the sticky bar to misposition.
      if (dashTop) {
        var currentH = Math.round(dashTop.getBoundingClientRect().height);
        var storedH = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--dash-top-h')) || 0;
        if (currentH !== storedH) {
          document.documentElement.style.setProperty('--dash-top-h', currentH + 'px');
        }
      }

      // How far the dock's top has scrolled above the scroll container's visible area
      var dockRect = dock.getBoundingClientRect();
      var rootRect = scrollRoot.getBoundingClientRect();
      // Distance the dock top has moved above the scroll container top (header area)
      var scrolledPast = rootRect.top - dockRect.top;
      var threshold = _getStickyThreshold();

      // v2.20.1: Compact search bar — triggers at 40% of the sticky threshold
      // so the greeting is already hidden and search is small BEFORE the
      // icon strip slides in. --dash-top-h is re-measured continuously at
      // the top of this handler, so no explicit remeasurement needed here.
      var compactThreshold = Math.max(threshold * 0.4, 40);
      if (dashTop) {
        if (scrolledPast >= compactThreshold) {
          if (!dashTop.classList.contains('dash-compact')) {
            dashTop.classList.add('dash-compact');
          }
        } else {
          if (dashTop.classList.contains('dash-compact')) {
            dashTop.classList.remove('dash-compact');
          }
        }
      }

      if (scrolledPast >= threshold) {
        if (!sticky.classList.contains('visible')) {
          sticky.classList.add('visible');
          // v2.20.1: Mark dash-top so box-shadow gap cover only activates
          // when BOTH compact AND sticky are active
          if (dashTop) dashTop.classList.add('dash-sticky-on');
          // v2.20.0 r4: Update --dash-sticky-h when icon bar appears
          requestAnimationFrame(function() {
            var sh = sticky.getBoundingClientRect().height;
            document.documentElement.style.setProperty('--dash-sticky-h', Math.round(sh) + 'px');
          });
        }
      } else {
        if (sticky.classList.contains('visible')) {
          sticky.classList.remove('visible');
          if (dashTop) dashTop.classList.remove('dash-sticky-on');
          // v2.20.0 r4: Reset --dash-sticky-h when icon bar disappears
          document.documentElement.style.setProperty('--dash-sticky-h', '0px');
        }
      }
    }
    window._tsStickyScroll = _onDashScroll;
    scrollRoot.addEventListener('scroll', _onDashScroll, { passive: true });

    // v2.20.0 r5: Force scrollLeft=0 on every scroll event. iOS WebKit
    // sometimes allows a few pixels of horizontal scroll even with
    // overflow-x:hidden when inertia scrolling or rubber-banding occurs.
    // This immediately resets any horizontal displacement.
    scrollRoot.addEventListener('scroll', function() {
      if (scrollRoot.scrollLeft !== 0) {
        scrollRoot.scrollLeft = 0;
      }
    }, { passive: true });
    // Run once after layout settles to set initial state
    requestAnimationFrame(function() {
      requestAnimationFrame(_onDashScroll);
    });
  }
}

// ---- v2.14.4 A8: FUZZY NAME / APP MATCHING ----
function fuzzyMatch(needle, haystack) {
  // Lowercase, strip non-alpha, and compare
  var n = needle.toLowerCase().replace(/[^a-z0-9]/g, '');
  var h = haystack.toLowerCase().replace(/[^a-z0-9]/g, '');
  if (!n) return 0;
  if (h === n) return 1.0;       // exact match
  if (h.includes(n)) return 0.9; // substring match
  // Check if needle matches after common letter omissions (silent letters, typos)
  // E.g. "jonathon" matches "jonathan" (one vowel off)
  var ni = 0;
  var matched = 0;
  for (var hi = 0; hi < h.length && ni < n.length; hi++) {
    if (h[hi] === n[ni]) { matched++; ni++; }
  }
  var score = ni === n.length ? (matched / Math.max(h.length, n.length)) : 0;
  // Bonus if all chars were found in sequence
  if (ni === n.length && matched === n.length) score = Math.max(score, 0.7);
  return score;
}

// ---- v2.23.0: INLINE ASK FIELD (type directly; results drop down beneath) ----
// Replaces the pop-up command-palette overlay. The ask field lives inline in the
// dashboard header (#dash-ask); typing renders results into #cmd-results, shown as
// a dropdown. Empty field → dropdown hidden (just the field). openCmdPalette /
// closeCmdPalette are kept as compatibility shims (other code + inline onclicks
// call them) but now just focus the field / hide the dropdown — no overlay.
function initCommandPalette() {
  var field = document.getElementById('dash-ask');
  var input = document.getElementById('cmd-input');
  var results = document.getElementById('cmd-results');
  if (!input || !results) return;

  // Analytical query detection keywords
  var ANALYTICS_WORDS = ['revenue','invoice','estimate','how many','how much',
    'total','this month','this week','today','ytd','mtd','compare','trend',
    'customer','lead','survey','paid','billing','sales','job','average',
    'last month','last week','last year','yoy','growth','decline'];

  function showResults() {
    results.classList.add('show');
    if (input.setAttribute) input.setAttribute('aria-expanded', 'true');
  }
  function hideResults() {
    results.classList.remove('show');
    if (input.setAttribute) input.setAttribute('aria-expanded', 'false');
  }

  // Compatibility shims for existing callers / inline onclicks.
  window.openCmdPalette = function () { input.focus(); };
  window.closeCmdPalette = function () {
    hideResults();
    // Clearing keeps the dashboard tidy after an action fires.
    input.value = '';
  };

  // Render-on-type: show the dropdown only when there's a query.
  input.addEventListener('input', function () {
    var q = input.value;
    renderCmdResults(q);
    if (q && q.trim()) { showResults(); } else { hideResults(); }
  });

  // Re-open the dropdown on focus if there's already text.
  input.addEventListener('focus', function () {
    if (input.value && input.value.trim()) { renderCmdResults(input.value); showResults(); }
  });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { hideResults(); input.blur(); }
    if (e.key === 'Enter') {
      var first = results.querySelector('.cmd-item');
      if (first) first.click();
    }
  });

  // Click outside the ask field closes the dropdown (without clearing the text).
  document.addEventListener('click', function (e) {
    if (field && !field.contains(e.target)) { hideResults(); }
  });

  // Ctrl/Cmd-K focuses the inline field; Escape closes the dropdown.
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
      e.preventDefault();
      input.focus();
    }
    if (e.key === 'Escape' && results.classList.contains('show')) {
      hideResults();
    }
  });

  function isAnalyticalQuery(q) {
    var lower = q.toLowerCase();
    return ANALYTICS_WORDS.some(function (w) { return lower.includes(w); });
  }

  // v2.21.1 (cross-app orchestrator): detect a customer-document LOOKUP query
  // for palette LABELING only. Mirrors the bot's two-signal test (RULE TT2):
  // a lookup needs a named person AND retrieval framing, and must NOT be an
  // aggregate/analytic question. Returns the extracted customer name (string)
  // to show on the row, or null if it's not a lookup. This never changes the
  // routing — the query still goes to the Brain Bot, which makes the real call.
  function detectLookupIntent(raw) {
    if (!raw) return null;
    var q = raw.trim();
    var lower = q.toLowerCase();

    // Aggregate / analytic framing wins — these are NOT lookups even with a noun.
    if (/\b(how many|how much|total|average|avg|count|number of|by (salesperson|rep|city|source|product|type|month)|this (month|quarter|year|week)|last (month|quarter|year|week)|ytd|mtd|qtd|breakdown|compare|trend|ranking|top \d)\b/.test(lower)) {
      return null;
    }

    var nouns = '(?:estimate|estimates|quote|quotes|bid|bids|invoice|invoices|bill|bills|job|jobs|work|paperwork|account)';

    // Pattern A: "<noun> for <Name>"  e.g. "estimate for Sam Rivera"
    var m = q.match(new RegExp('\\b' + nouns + '\\s+for\\s+([A-Za-z][\\w.\\-]*(?:\\s+[A-Za-z][\\w.\\-]*){0,3})$', 'i'));
    if (m && m[1]) return cleanName(m[1]);

    // Pattern B: "pull up / show / find / open / look up <Name>'s <noun>"
    m = q.match(new RegExp('\\b(?:pull up|pull|show me|show|find|open|look up|lookup|get)\\s+([A-Za-z][\\w.\\-]*(?:\\s+[A-Za-z][\\w.\\-]*){0,3})(?:\'s|s\')\\s+' + nouns, 'i'));
    if (m && m[1]) return cleanName(m[1]);

    // Pattern C: "<Name>'s <noun>"  e.g. "Rivera's estimate", "Steve's jobs"
    m = q.match(new RegExp('^([A-Za-z][\\w.\\-]*(?:\\s+[A-Za-z][\\w.\\-]*){0,3})(?:\'s|s\')\\s+' + nouns + '\\b', 'i'));
    if (m && m[1]) return cleanName(m[1]);

    // Pattern D: "show me / pull up / find the <Name> <noun>"  e.g. "show me the Rivera estimate"
    m = q.match(new RegExp('\\b(?:pull up|show me|show|find|open|look up|get)\\s+(?:the\\s+)?([A-Za-z][\\w.\\-]*(?:\\s+[A-Za-z][\\w.\\-]*){0,2})\\s+' + nouns + '\\b', 'i'));
    if (m && m[1] && !/^(my|our|all|any|recent|open|last|this)$/i.test(m[1])) return cleanName(m[1]);

    // Pattern E: "what's on <Name>'s <noun>"  e.g. "what's on Steve's quote?"
    m = q.match(new RegExp('^\\s*what(?:\'s|s| is| are)\\s+on\\s+([A-Za-z][\\w.\\-]*(?:\\s+[A-Za-z][\\w.\\-]*){0,3})(?:\'s|s\')\\s+' + nouns + '\\b', 'i'));
    if (m && m[1]) return cleanName(m[1]);

    return null;

    function cleanName(s) {
      var name = s.replace(/\s+/g, ' ').trim();
      // Guard: a real customer name doesn't start with an interrogative or verb.
      // (Stops Pattern C from over-capturing "what is on Rivera" etc.)
      if (/^(what|who|when|where|why|how|is|are|was|were|do|does|did|can|could|the|a|an|my|our|all|any)\b/i.test(name)) {
        // Keep only the trailing 1–2 tokens, which are the actual name.
        var toks = name.split(' ');
        name = toks.slice(Math.max(0, toks.length - 2)).join(' ');
        // Drop a leading preposition if the trim left one ("on Rivera" → "Rivera").
        name = name.replace(/^(on|for|to|of|about|re)\s+/i, '');
      }
      return name;
    }
  }

  // ── v2.21.4 (cross-app orchestrator): the dashboard "ask" router ──
  // Turns the top-of-dashboard ask field into an operator entry point. It
  // CLASSIFIES a natural-language command into one of:
  //   • { kind:'shell', ... }  — an instant in-shell action (e.g. open Camera
  //                              with a sticky pre-label). No Brain Bot round-trip.
  //   • { kind:'route', ... }  — route to the Brain Bot (sales-analytics) with the
  //                              prompt + an orchestrator_hint so the user sees the
  //                              intent was understood; the bot/engine does the work.
  //   • null                    — not a recognized command; fall through to the
  //                              normal "Ask Brain Bot" behavior (nothing regresses).
  // It is deterministic (regex + the existing detectLookupIntent), so the clear
  // commands work WITHOUT any Poe change and answer instantly. Anything it can't
  // classify still goes to the bot exactly as before.
  function zdzOrchestratorRoute(raw) {
    if (!raw) return null;
    var q = String(raw).trim();
    var lower = q.toLowerCase();

    // ---- 1. CAMERA pre-label: "open the camera and label all my photos as 'before'",
    // "take a photo with the phrase 'before' added", "snap some before pics", etc.
    // Signal A: a camera/photo-capture intent (open the camera OR take/snap a photo).
    // Signal B (for the shell action): a label directive. Handled in-shell (instant).
    var cameraOpen =
         /\b(open|launch|start|fire up|bring up|go to|use|pull up)\b[^.]*\bcamera\b/.test(lower)
      || /\bcamera\b[^.]*\b(open|on|up|please)\b/.test(lower)
      || /^camera\b/.test(lower)
      // capture verbs: take/snap/shoot/grab/capture (typo-tolerant: tak(e), snp, etc.)
      // + a photo noun (photo/picture/pic/shot/image/selfie, typo-tolerant).
      || (/\b(tak(?:e|es|ing)?|snap|snp|shoot|grab|captur(?:e|ing)?|get)\b/.test(lower)
          && /\b(photos?|phot|pics?|pix|pictures?|pics?|shots?|images?|selfies?)\b/.test(lower))
      // "a <label> photo/pic" implies capture even without an explicit verb
      || /\b(a|some|the)\s+[\w'-]+\s+(photos?|pics?|pictures?|shots?)\b/.test(lower)
      || /\b(photo|picture|pic)\s+session\b/.test(lower)
      || /\bstart\b[^.]*\b(shooting|taking|snapping)\b/.test(lower);
    if (cameraOpen) {
      var label = extractPrelabel(q);
      var hasCamera = getVisibleApps().some(function (a) {
        return a.id === 'camera' || a.id === 'zdz-camera';
      });
      if (hasCamera) {
        // v2.24.0: a camera-capture command ALWAYS opens the camera in-shell —
        // with OR without a pre-label. Previously only the labeled form became a
        // shell action and a bare "take a picture" fell through to the Brain Bot
        // (which tried to "take a picture" itself — wrong). Now any recognized
        // capture intent opens the real camera; the label is applied if present.
        var camName = label
          ? 'Open Camera — label photos "' + label + '"'
          : 'Open Camera';
        var camDesc = label
          ? 'Tags every photo you take as "' + label + '" until you stop'
          : 'Opens the camera to take photos';
        return {
          kind: 'shell',
          name: camName,
          desc: camDesc,
          icon: 'camera',
          color: '#0EA5E9',
          run: function () {
            // v2.24.0: confirm the launch with a haptic tap (the shutter-button
            // haptic itself lives in the zdz-camera plugin — see handoff doc).
            try { Haptics.tap(); } catch (e) {}
            // The camera app registers as 'zdz-camera' (and its launch handler only
            // claims that id); use whichever id is actually present.
            var camApp = getVisibleApps().filter(function (a) { return a.id === 'zdz-camera' || a.id === 'camera'; })[0];
            var opts = { source: 'orchestrator' };
            if (label) { opts.prelabel = label; }
            openApp(camApp ? camApp.id : 'zdz-camera', opts);
          }
        };
      }
      // No camera app available → fall through to normal handling.
    }

    // ---- 1a. COMMISSION for a NAMED person → inline card (v2.28.0). "commission
    // for Alex, May 2026", "Alex's commission for May", "give me Riley's
    // commission". A figure for a specific salesperson is a bounded answer, so we
    // render it INLINE under the field (headline + expandable drill-down) instead
    // of opening the whole widget — like the contact card. Checked BEFORE the
    // generic launch so "commission for Alex" ≠ "open the Commission app". A
    // bare "open commission" / "commission for last month" (no person) has no
    // subject here, returns null, and falls through to the launch below.
    var commCard = zdzDetectCommissionInline(q, lower);
    if (commCard) return commCard;

    // ---- 1b. GENERIC APP LAUNCH (v2.27.0): "open the schedule", "new estimate
    // for the Smith job", "start a sketch", "check stock for sliders", "pull up
    // commission for last month", etc. Like the Camera block, these are INSTANT
    // in-shell shell actions — openApp() jumps to the app's dashboard widget (no
    // Brain Bot round-trip). Each app can optionally capture a bit of context
    // (a customer/job name, a date/period) passed through openApp options so the
    // widget can pre-focus. Camera is handled above (it has bespoke label logic);
    // email/contact/document lookups are handled below and take precedence over a
    // bare "open" only when their stronger signals match — so we run this AFTER
    // camera but it returns null unless an explicit launch verb + app is present.
    var launch = zdzDetectAppLaunch(q, lower);
    if (launch) return launch;

    // ---- 2. EMAIL compose: "write/draft/send an email to Sam Carter about ..." ----
    // Checked BEFORE contact: "email <Name> about X" is a COMPOSE intent, not a
    // request to look up <Name>'s email address. Compose opens the full chat (the
    // editable house-voice draft card lives there).
    var emailTo = detectEmailIntent(q);
    if (emailTo) {
      return {
        kind: 'route',
        appId: 'sales-analytics',
        name: 'Draft an email to ' + emailTo,
        desc: 'Writes it in the house voice — click to send',
        icon: 'mail',
        color: '#6366F1',
        options: { prompt: q, orchestrator_hint: { verb: 'email', to: emailTo } }
      };
    }

    // ---- 3. CONTACT lookup: "contact info for Chris Rivera", "Steve's phone number"
    // Contextual delivery: a contact is a bounded fact, so we render it INLINE on
    // the dashboard via /zorderz/v1/orchestrate (no chat switch, no Poe round-trip).
    // If the result is rich/denied/ambiguous, the inline card offers "Open in
    // chat →" to hand off to the full Analytics surface (see renderInlineContact).
    var contactName = detectContactIntent(q);
    if (contactName) {
      return {
        kind: 'inline',
        verb: 'contact',
        query: contactName,
        rawQuery: q,              // server re-classifies from the exact words
        name: 'Get ' + contactName + '’s contact info',
        desc: 'Phone, email & address — shown right here',
        icon: 'contact',
        color: '#6366F1',
        // Fallback prompt if the user chooses the chat handoff.
        options: { prompt: q, orchestrator_hint: { verb: 'contact', query: contactName } }
      };
    }

    // ---- 4. DOCUMENT lookup: "estimate for Sam Rivera", "pull up Rivera's quote" ----
    // Inline too: a short open-docs summary renders here, with "Open in chat →" for
    // the full interactive estimate cards. Server (/orchestrate) is the authority.
    var lookupName = detectLookupIntent(q);
    if (lookupName) {
      return {
        kind: 'inline',
        verb: 'lookup',
        query: lookupName,
        rawQuery: q,
        name: 'Look up ' + lookupName,
        desc: 'Their open estimates & invoices — shown right here',
        icon: 'file-search',
        color: '#6366F1',
        options: { prompt: q, orchestrator_hint: { verb: 'lookup', query: lookupName } }
      };
    }

    return null;

    // Extract the pre-label word/phrase from a camera command. Handles quoted and
    // UNQUOTED forms, and several natural phrasings ("with the phrase 'before' added",
    // "labeled before", "a before photo", "tagged X", "call them X").
    function extractPrelabel(s) {
      var m;
      // 1) ANY quoted token wins — that's almost always the intended label.
      //    e.g. take a photo with the phrase "before" added  /  label them 'before'
      m = s.match(/["'“”]([^"'“”]{1,40})["'“”]/);
      if (m && m[1] && cleanLabel(m[1])) return cleanLabel(m[1]);
      // 2) "...the phrase/word/label/tag <X> (added|applied|on them)" — unquoted
      m = s.match(/\b(?:phrase|word|label|tag|note|caption)\s+([A-Za-z0-9][\w \-]{0,30}?)\s*(?:added|applied|on (?:them|each|all)|to (?:each|all|every))?\s*(?:\.|,|$)/i);
      if (m && m[1] && cleanLabel(m[1])) return cleanLabel(m[1]);
      // 3) "(pre)label/tag/mark/call (them/all/these/the photos) (as|to|with) <X>"
      m = s.match(/\b(?:pre[\s-]?label|label|tag|mark|call|name)\b\s+(?:them|all|these|the\s+(?:photos?|pics?|images?))?\s*(?:as|to|with)?\s*([A-Za-z0-9][\w \-]{0,30}?)\s*(?:\.|,|$|photos?|pics?|images?)/i);
      if (m && m[1] && cleanLabel(m[1])) return cleanLabel(m[1]);
      // 4) "(take|snap) ... a/some <X> photo(s)/pic(s)"  e.g. "take a before photo"
      m = s.match(/\b(?:tak(?:e|ing)?|snap|snp|shoot|grab|captur(?:e|ing)?|get)\b[^]*?\b(?:a|some|the)\s+([A-Za-z][\w \-]{0,20}?)\s+(?:photos?|phot|pics?|pix|pictures?|shots?|images?)\b/i);
      if (m && m[1] && cleanLabel(m[1])) return cleanLabel(m[1]);
      // 5) "labeled/labelled/tagged/marked/called <X>" (unquoted, anywhere)
      m = s.match(/\b(?:labell?ed|tagged|marked|named|called)\s+["'“]?([A-Za-z0-9][\w \-]{0,30}?)["'”]?\s*(?:\.|,|$)/i);
      if (m && m[1] && cleanLabel(m[1])) return cleanLabel(m[1]);
      return '';
    }
    function cleanLabel(s) {
      var v = String(s).replace(/\s+/g, ' ').trim();
      // Drop leading filler/connectors.
      v = v.replace(/^(?:the|a|an|as|to|with|phrase|word|label|tag|of)\s+/i, '');
      // Drop trailing nouns/verbs the capture may have swept in.
      v = v.replace(/\s+(?:photos?|pics?|pictures?|images?|shots?|examples?|added|applied|on|to|each|all|every|them)$/i, '');
      v = v.trim();
      // Reject pure filler that isn't a real label.
      if (/^(?:them|all|these|those|it|that|this|each|every|some|the|a|an|my|our|photo|photos|pic|pics|picture|pictures)$/i.test(v)) return '';
      return v;
    }

    // Contact-intent detector — typo/punctuation tolerant by design (field use is
    // messy: "what';s devin riveras info/", "devin rivera info", "whats Devin's #").
    // Strategy: (1) bail on analytics/aggregate framing; (2) NORMALIZE away stray
    // punctuation (apostrophes, semicolons, slashes, extra spaces); (3) fire if an
    // "info word" and a plausible person-name BOTH appear — in any order. The
    // server still resolves/redacts; over-firing just means a card instead of chat.
    function detectContactIntent(s) {
      var raw = String(s || '');
      var lo = raw.toLowerCase();
      // Analytics/aggregate framing wins — never a single-contact lookup.
      if (/\b(how many|how much|count|number of|list all|list the|total|average|avg|by (salesperson|rep|city|zip|source|product|type|month)|this (month|quarter|year|week)|last (month|quarter|year|week)|ytd|mtd|qtd|breakdown|revenue|sales|top \d)\b/.test(lo)) return null;

      var infoTerm = '(?:contact|info|information|details?|number|phone|e-?mail|cell|address|card|reach)';

      // ── Pass 1: the clean, structured patterns (fast path, precise name capture) ──
      var nameRe = '([A-Za-z][\\w.\\-]*(?:\\s+[A-Za-z][\\w.\\-]*){0,3})';
      var m;
      m = raw.match(new RegExp('\\b' + infoTerm + '(?:\\s+(?:info|information|details?|number|card))?\\s+(?:for|on|of|about)\\s+' + nameRe, 'i'));
      if (m && m[1]) return cleanName(m[1]);
      m = raw.match(new RegExp('\\b' + nameRe + '(?:\'s|’s|s\')\\s+' + infoTerm + '\\b', 'i'));
      if (m && m[1]) return cleanName(m[1]);
      m = raw.match(new RegExp('\\bhow (?:do|can) i (?:reach|contact|get a hold of|call|email)\\s+' + nameRe, 'i'));
      if (m && m[1]) return cleanName(m[1]);
      m = raw.match(new RegExp('^(?:who(?:\'s| is)|look up|lookup|pull up)\\s+' + nameRe + '\\??$', 'i'));
      if (m && m[1] && !/^(my|our|all|the|a|an|everyone|anyone)\b/i.test(m[1])) return cleanName(m[1]);

      // ── Pass 2: punctuation-tolerant fallback (catches mangled typing) ──
      // Normalize: drop apostrophes/possessives/semicolons/slashes, collapse spaces.
      var norm = lo
        .replace(/['’;:/\\?!.,]+/g, ' ')   // strip stray punctuation incl. ';' and '/'
        .replace(/\b([a-z]+)s\b/g, '$1s')  // (no-op safeguard; keeps tokens intact)
        .replace(/\s+/g, ' ')
        .trim();
      var hasInfoWord = new RegExp('\\b' + infoTerm + '\\b').test(norm);
      if (!hasInfoWord) return null;

      // Remove the question/filler words + the info word itself, leaving the name.
      var leftover = norm
        .replace(new RegExp('\\b' + infoTerm + '\\b', 'g'), ' ')
        .replace(/\b(what|whats|what s|who|whos|who s|is|are|the|a|an|me|my|our|give|get|find|show|pull|up|look|lookup|please|can|you|i|for|on|of|about|to|s)\b/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
      // What's left should look like a name: 1–4 alphabetic tokens.
      if (leftover && /^[a-z][a-z .\-]{1,40}$/.test(leftover)) {
        var toks = leftover.split(' ').filter(Boolean);
        if (toks.length >= 1 && toks.length <= 4) {
          // Reject obvious non-names (single super-common words).
          if (!/^(everyone|anyone|someone|customer|customers|client|clients|them|people)$/.test(leftover)) {
            return cleanName(leftover);
          }
        }
      }
      return null;
    }

    // Email-compose detector. Verb (write/draft/send/email) + recipient name.
    function detectEmailIntent(s) {
      var nameRe = '([A-Za-z][\\w.\\-]*(?:\\s+[A-Za-z][\\w.\\-]*){0,3})';
      var m = s.match(new RegExp('\\b(?:write|draft|compose|send|shoot|fire off)\\s+(?:an?\\s+)?(?:email|e-mail|note|message)\\s+(?:to\\s+)?' + nameRe, 'i'));
      if (m && m[1]) return cleanName(m[1]);
      m = s.match(new RegExp('\\bemail\\s+' + nameRe + '\\b', 'i'));
      if (m && m[1] && !/^(me|us|them|him|her|everyone|the)\b/i.test(m[1])) return cleanName(m[1]);
      return null;
    }

    function cleanName(s) {
      var name = String(s).replace(/\s+/g, ' ').trim();
      // Strip a leading possessive fragment left by "what's"/"that's" etc.
      // ("s Sam Rivera" → "Sam Rivera").
      name = name.replace(/^s\s+/i, '');
      // Strip leading filler verbs/pronouns/determiners the regex may have swept in
      // ("give me Chris Rivera" → "Chris Rivera"). Repeat until none remain.
      var fillerRe = /^(?:get|give|grab|find|pull|look|show|me|us|the|a|an|my|our|up|to|for|of|please|can|you|i|me)\b\s*/i;
      while (fillerRe.test(name)) { name = name.replace(fillerRe, ''); }
      // Drop trailing connector words the regex may have swept in.
      name = name.replace(/\s+\b(about|re|regarding|confirming|to confirm|and|for|that|when|tomorrow|today|asap)\b.*$/i, '');
      if (/^(what|who|when|where|why|how|is|are|was|were|do|does|did|could)\b/i.test(name)) {
        var toks = name.split(' ');
        name = toks.slice(Math.max(0, toks.length - 2)).join(' ');
        name = name.replace(/^(on|for|to|of|about|re)\s+/i, '');
      }
      return name.trim();
    }
  }

  // ── v2.28.0: Commission-for-a-named-person inline detector. Mirrors the
  // server ZDZ_Orchestrator::detect_commission (the server stays authoritative;
  // this produces the instant row label + drives the inline fetch). Returns a
  // kind:'inline' verb:'commission' route, or null. Requires "commission(s)"
  // AND a person (name or "my"), so aggregates ("total commissions this month",
  // "commission by rep") fall through to the launch/Brain-Bot paths.
  function zdzDetectCommissionInline(q, lower) {
    if (!lower) return null;
    var hasComm = lower.indexOf('commission') !== -1;
    // EARNINGS SHORTHAND (v2.28.6): on this sales dashboard, an unambiguous
    // personal-earnings question with NO other app keyword means "commission"
    // even when the word is absent — e.g. "how much did Jordan earn last month",
    // "what did Sam make", "how much does Alex take home". Require a
    // did/does/do <Name> <earn-verb> OR <Name> earned/made/took-home frame so
    // generic "how do I earn rewards" never matches.
    var earnFrame = /\b(?:did|does|do)\s+[A-Za-z][\w.\-]*(?:\s+[A-Za-z][\w.\-]*){0,2}?\s+(?:earn|earns|earned|make|makes|made|get|gets|got|bring in|brings in|brought in|pull|pulls|pulled|take home|takes home|took home)\b/i.test(q)
      || /\b[A-Za-z][\w.\-]*(?:\s+[A-Za-z][\w.\-]*){0,2}?\s+(?:earned|made|brought in|pulled in|took home)\b/i.test(q);
    if (!hasComm && !earnFrame) return null;
    // PERSON-SPECIFIC override (v2.28.6): a possessive-name query like
    // "Jordan's commission rate" / "what's Sam's May commission" is about ONE
    // person, so it must NOT be swept into the company-wide rollup skip below
    // (which would otherwise catch "commission rate(s)", "total commissions", etc.).
    var personSpecific = /\b[A-Za-z][\w.\-]*(?:'s|’s)\s+(?:(?:this|last|next)\s+(?:month|quarter|year|week)\s+|(?:january|february|march|april|may|june|july|august|september|october|november|december|q[1-4]|mtd|ytd|\d{4})\s+)?commissions?\b/i.test(q);
    // COMPANY-WIDE / cross-person framing → chat (a real rollup, no single person):
    // "commission by salesperson", "did we pay everyone", "average commission",
    // "commission report", "how do commissions work". Note: "by PRODUCT/type" is
    // NOT in this list — for a NAMED person it's a valid drill-down (focus below).
    if (!personSpecific && /\b(by (?:salesperson|rep|person|month)|everyone|all (?:reps|salespeople|staff)|the (?:whole )?team|company-?wide|total commissions?|did we pay|do we pay|we paid|our (?:total |average )?commission|average commission|commission report|how do commissions?|how does commission|commission rates?|commission structure|commission plan)\b/.test(lower)) return null;

    // Words that are NOT a person — used to reject a bad subject capture. Includes
    // periods, launch verbs, and common verbs/adjectives the name regex might grab
    // ("make", "earn", "average", "did", "pay", etc.).
    var STOP = /^(i|have|has|had|commission|commissions|this|last|next|for|of|the|a|an|report|summary|by|total|owed|due|rate|rates|check|breakdown|history|my|me|mine|our|us|we|today|tomorrow|yesterday|month|year|quarter|week|q[1-4]|january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sep|sept|oct|nov|dec|mtd|ytd|qtd|open|launch|start|pull|show|go|goto|check|view|bring|jump|fire|new|create|add|begin|see|calculate|calc|make|makes|made|making|earn|earns|earned|earning|get|gets|got|pay|paid|paying|average|avg|much|did|do|does|done|is|are|was|were|how|what|whats)\b/i;
    // A person name: 1–3 capitalized-ish tokens, NOT followed by "for"/period.
    // Stop the capture before a trailing "for <period>" so "Alex for May" → "Alex".
    var NAME = '([A-Za-z][\\w.\\-]*(?:\\s+[A-Za-z][\\w.\\-]*){0,2}?)';

    // Leading filler/verbs/pronouns the regex may sweep into the name.
    var LEAD = /^(?:give|show|get|grab|find|pull|look|tell|me|us|the|a|an|my|our|up|and|or|vs|versus|commission|commissions|to|for|of|on|in|into|at|toward|towards|count|counts|counted|counting|please|can|you|i|what|whats|what's|is|are|was|were|do|does|did|could|how|much|see|make|makes|made|earn|earns|earned|pay|paid|rate|basis|math|break|breakdown|down|drill|detail|details)\b\s*/i;
    function takeName(s) {
      if (!s) return '';
      var n = String(s).replace(/\s+/g, ' ').trim();
      // v2.28.7 — never let a first-person/auxiliary fragment become a name
      // ("I", "have I", "did I"): these are self-references, handled by isSelf.
      if (/^(?:i|have i|has i|had i|did i|do i|i have|i did)$/i.test(n)) return '';
      // Strip leading filler repeatedly ("is Aarons" → "Aarons", "up" → "").
      while (LEAD.test(n)) { n = n.replace(LEAD, ''); }
      // Strip an orphaned leading "s " left when "what's"/"that's" backtracks the
      // possessive regex onto the bare "s" (v2.28.6) — no real name is a lone "s".
      n = n.replace(/^s\s+/i, '');
      // Trim a trailing "for/in/this/last ..." / period fragment.
      n = n.replace(/\s+\b(for|in|during|this|last|next|on)\b.*$/i, '').trim();
      n = n.replace(/\s+\b(january|february|march|april|may|june|july|august|september|october|november|december|today|tomorrow|yesterday|month|year|quarter|week|mtd|ytd|q[1-4]|\d{4})\b.*$/i, '').trim();
      // Keep at most the last 2 tokens (a name is 1–2 words: "a salesperson", "a specialist").
      var toks = n.split(' ').filter(Boolean);
      if (toks.length > 2) { n = toks.slice(-2).join(' '); toks = n.split(' '); }
      // Strip a bare trailing-s possessive on the LAST token ("Aarons" → "Alex"),
      // but leave names that legitimately end in "ss" and 1-letter initials alone.
      var last = toks[toks.length - 1];
      if (last && /^[A-Za-z][\w.\-]*[a-rt-z]s$/i.test(last) && last.length > 2) {
        toks[toks.length - 1] = last.slice(0, -1);
        n = toks.join(' ');
      }
      if (n === '' || STOP.test(n) || n.length < 2) return '';
      return n;
    }

    // v2.28.7 — self also matches first-person EARNINGS phrasing so
    // "how much did I earn this month" / "what have I made" resolves to the
    // current user instead of trying to read "I"/"have I" as a salesperson name.
    var isSelf = /\b(my|me|mine)\b/.test(lower)
      || /\b(?:did|do|have|has|how much (?:have|did))?\s*i\s+(?:have\s+)?(?:earn|earned|make|made|made|get|got|take home|took home|bring in|brought in|pull|pulled)\b/i.test(q)
      || /\bmy\s+(?:total\s+)?(?:pay|earnings|take)\b/i.test(lower);
    var subject = '';
    var m;
    // A period/time word that can sit BETWEEN the name and "commission"
    // ("Alex's MAY commission", "Alex's LAST MONTH commission"). We allow it
    // optionally in the patterns so it doesn't get captured as the name.
    var PERIODW = '(?:(?:this|last|next)\\s+(?:month|quarter|year|week)\\s+|(?:january|february|march|april|may|june|july|august|september|october|november|december|q[1-4]|mtd|ytd|\\d{4})\\s+)?';
    // PERSON tokens that are clearly a name (1–2), used where we need to be strict.
    var PNAME = '([A-Za-z][\\w.\\-]*(?:\\s+[A-Za-z][\\w.\\-]*)?)';

    // "<Name>'s [period] commission" — strongest signal; period word optional.
    if ((m = q.match(new RegExp('\\b' + PNAME + '(?:\'s|’s)\\s+' + PERIODW + 'commissions?\\b', 'i')))) {
      subject = takeName(m[1]);
    }
    // v2.28.7 — MULTI-PERSON ("Jordan and Sam commission", "Alex & Riley",
    // "commission for Jordan and Sam"): the card shows ONE person, so we take
    // the FIRST valid name and flag it; the row label notes others were named.
    var multiNames = null;
    if (!subject) {
      var mm2 = q.match(new RegExp('\\b' + PNAME + '\\s+(?:and|&|,|or|vs|versus)\\s+' + PNAME + '(?:\\s+(?:and|&|,)\\s+' + PNAME + ')?\\s+(?:[a-z]+\\s+)?commissions?\\b', 'i'))
             || q.match(new RegExp('\\bcommissions?\\s+(?:for|of)\\s+' + PNAME + '\\s+(?:and|&|,)\\s+' + PNAME, 'i'));
      if (mm2) {
        var first = takeName(mm2[1]);
        var second = takeName(mm2[2] || '');
        if (first) { subject = first; if (second) multiNames = [first, second].concat(mm2[3] ? [takeName(mm2[3])] : []).filter(Boolean); }
      }
    }
    // "commission(s) for/of/owed to <Name>".
    if (!subject && (m = q.match(new RegExp('\\bcommissions?\\s+(?:for|of|owed to|earned by|due to)\\s+' + NAME, 'i')))) subject = takeName(m[1]);
    // "the rate/basis on <Name>'s commission" — "what's the rate on Alex's …".
    if (!subject && (m = q.match(new RegExp('\\b(?:rate|basis|math|breakdown)\\s+(?:on|for|of)\\s+' + PNAME + '(?:\'s|’s)?\\s+' + PERIODW + 'commissions?', 'i')))) subject = takeName(m[1]);
    // "did/does <Name> earn/make/get …".
    if (!subject && (m = q.match(new RegExp('\\b(?:did|does|do)\\s+' + NAME + '\\s+(?:earn|earns|earned|make|makes|made|get|gets|got|bring in|brings in|brought in|pull|pulls|pulled|take home|takes home|took home)', 'i')))) subject = takeName(m[1]);
    // "<Name> earned/made/took home …" (the "how much" variant where the verb follows the name).
    if (!subject && (m = q.match(new RegExp('\\b' + NAME + '\\s+(?:earned|made|brought in|pulled in|took home)\\b', 'i')))) subject = takeName(m[1]);
    // "… commission … for <Name>" (verb phrasing with an explicit "for").
    if (!subject && (m = q.match(new RegExp('\\bcommissions?\\b.*?\\bfor\\s+' + NAME, 'i')))) subject = takeName(m[1]);
    // "make up <Name>'s [period] commission" / "in <Name>'s commission" — drill-down
    // phrasings where the name+possessive precede an intervening period word.
    if (!subject && (m = q.match(new RegExp('\\b(?:up|in|on|of|toward|towards|count toward|count towards|counts toward|counted toward)\\s+' + PNAME + '(?:\'s|’s)?\\s+' + PERIODW + 'commissions?', 'i')))) subject = takeName(m[1]);
    // "<Name> [period] commission" (loosest; period word optional). takeName()
    // rejects any non-name token via the STOP list.
    if (!subject && (m = q.match(new RegExp('\\b' + PNAME + '\\s+' + PERIODW + 'commissions?\\b', 'i')))) {
      var cand = m[1].replace(/^([A-Za-z][\w.\-]*[a-rt-z])s$/i, '$1'); // Aarons→Alex
      subject = takeName(cand);
    }

    if (!subject && !isSelf) return null;

    // Period phrase (optional) for the row label.
    var period = '';
    var pm = q.match(/\b((?:january|february|march|april|may|june|july|august|september|october|november|december)(?:\s+\d{4})?|(?:first|second|third|fourth|1st|2nd|3rd|4th)\s+quarter(?:\s+\d{4})?|q[1-4](?:\s+\d{4})?|this (?:month|quarter|year)|last (?:month|quarter|year)|quarter to date|month to date|year to date|year-to-date|qtd|mtd|ytd|\d{4}-\d{2}|\d{4})\b/i);
    if (pm) period = pm[1];

    // SMART FOCUS (v2.28.2): the card always leads with the headline figure and
    // keeps the detail boxes COLLAPSED — unless the request explicitly asks about
    // a section, in which case we auto-open just that one. Only set when the user
    // clearly wants a drill-down; a plain "Alex's commission" leaves all closed.
    var focus = '';
    if (/\b(by product|by category|product (?:line|split|breakdown|mix)|which products?|what products?)\b/.test(lower)) focus = 'products';
    else if (/\b(which jobs?|what jobs?|jobs? (?:counted|list|breakdown|make up|are in)|per job|by job|list (?:the )?jobs?|what invoices?|which invoices?)\b/.test(lower)) focus = 'jobs';
    else if (/\b(how (?:is|was|are) .*calculated|how .*calculate|what(?:'s| is) the (?:rate|basis)|the rate on|commission rate|what rate|breakdown of (?:the )?(?:rate|math)|how much .*net|net commissionable)\b/.test(lower)) focus = 'basis';
    else if (/\b(breakdown|break it down|detail|details|drill (?:down|in))\b/.test(lower) && /\bproduct/.test(lower)) focus = 'products';

    var who = subject || 'my';
    var label = subject ? (subject + '’s commission') : 'My commission';
    if (multiNames && multiNames.length > 1) { label = subject + '’s commission (of ' + multiNames.length + ' — showing ' + subject + ')'; }
    var focusLabel = focus === 'products' ? ' — by product' : (focus === 'jobs' ? ' — jobs' : (focus === 'basis' ? ' — how it’s calculated' : ''));
    return {
      kind: 'inline',
      verb: 'commission',
      query: who,
      rawQuery: q,                 // server re-classifies from the exact words
      focus: focus,                // '' | 'jobs' | 'products' | 'basis' → auto-open
      name: label + (period ? ' — ' + period : '') + (period ? '' : focusLabel),
      desc: focus
        ? 'Opens straight to ' + (focus === 'products' ? 'the product split' : (focus === 'jobs' ? 'the jobs' : 'the rate basis')) + ' — shown right here'
        : 'The figure with a tap-to-expand breakdown — shown right here',
      icon: 'calculator',
      color: '#10B981',
      options: { prompt: q, orchestrator_hint: { verb: 'commission', subject: who, period: period, focus: focus } }
    };
  }

  // ── v2.27.0: Generic app-launch intent detector for the dashboard ask field.
  // Recognizes "<launch verb> <app>" (+ optional context) and returns a
  // kind:'shell' action that opens the app's dashboard widget via openApp().
  // Data-driven so adding an app is one row. Each entry:
  //   id       — the registered app id (must match zdzData.apps), tried in order;
  //              the first id present in getVisibleApps() wins.
  //   match    — RegExp of app name/aliases (word-boundaried, case-insensitive).
  //   icon,cc  — row icon + color (fallbacks; real app config overrides on launch).
  //   verb     — short label verb ("Open", "Start", "Check").
  //   context  — optional fn(q, lower) → {options, suffix} to capture a customer/
  //              job name or date/period and reflect it in the row + openApp opts.
  // We require an explicit launch verb OR the app name at the very start, so we
  // never hijack analytic questions ("how many estimates…") — those fall through.
  function zdzDetectAppLaunch(q, lower) {
    if (!lower) return null;

    // A launch verb anywhere, OR the query starting with the app word.
    var LAUNCH_VERB = /\b(open|launch|start|go to|goto|bring up|pull up|show|show me|take me to|jump to|fire up|new|create|add|begin|check|view|play)\b/;
    // v2.28.7 — tolerate common dictation/typing slips on the launch verb
    // ("opne", "oepn", "luanch", "strat", "shwo", "chekc") via a tight
    // edit-distance-1 check against the single-word verbs. Analytic questions
    // ("how many estimates") contain no near-verb word, so they still fall through.
    var SINGLE_VERBS = ['open','launch','start','show','create','begin','check','view'];
    // Damerau-Levenshtein (optimal string alignment), early-out beyond 1. Adjacent
    // transposition counts as distance 1 — that's the common dictation/typing slip.
    function dl1(a, b) {
      if (a === b) return 0;
      var la = a.length, lb = b.length;
      if (Math.abs(la - lb) > 1) return 9;
      var prev2 = [], prev = [], cur = [];
      for (var j = 0; j <= lb; j++) prev[j] = j;
      for (var i = 1; i <= la; i++) {
        cur = [i];
        for (var k = 1; k <= lb; k++) {
          var cost = a[i-1] === b[k-1] ? 0 : 1;
          var val = Math.min(prev[k] + 1, cur[k-1] + 1, prev[k-1] + cost);
          if (i > 1 && k > 1 && a[i-1] === b[k-2] && a[i-2] === b[k-1]) val = Math.min(val, prev2[k-2] + 1);
          cur[k] = val;
        }
        prev2 = prev; prev = cur;
      }
      return prev[lb];
    }
    function fuzzyVerb(lo) {
      var words = lo.split(/[^a-z]+/);
      for (var w = 0; w < words.length; w++) {
        var wd = words[w];
        if (wd.length < 4) continue;            // >=4 so 'how'/'many'/'new' can't false-match
        for (var v = 0; v < SINGLE_VERBS.length; v++) {
          var vb = SINGLE_VERBS[v];
          if (Math.abs(wd.length - vb.length) <= 1 && wd !== vb && dl1(wd, vb) <= 1) return true;
        }
      }
      return false;
    }
    var hasVerb = LAUNCH_VERB.test(lower) || fuzzyVerb(lower);

    // Helper: capture a trailing "for <name/job>" or "<name>'s" context phrase.
    function grabFor(s) {
      var m = s.match(/\bfor\s+([A-Za-z][\w'’.\-]*(?:\s+[A-Za-z][\w'’.\-]*){0,3})/i);
      if (m && m[1]) {
        var n = m[1].replace(/\s+\b(job|estimate|quote|account|today|tomorrow|this week|please)\b.*$/i, '').trim();
        // v2.28.10 — drop a leading article/possessive so "for the Smith job" → "Smith",
        // "for a Johnson estimate" → "Johnson", "for my Wilson job" → "Wilson".
        n = n.replace(/^(?:the|a|an|my|our|their|his|her)\s+/i, '').trim();
        return n;
      }
      return '';
    }
    // Helper: capture a date / period phrase for schedule & commission.
    function grabPeriod(s) {
      var m = s.match(/\b(today|tomorrow|yesterday|this (?:week|month|quarter|year)|last (?:week|month|quarter|year)|next (?:week|month)|month to date|year to date|mtd|ytd|q[1-4]|january|february|march|april|may|june|july|august|september|october|november|december|mon(?:day)?|tue(?:sday)?|wed(?:nesday)?|thu(?:rsday)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?)\b/i);
      return m ? m[1] : '';
    }

    // requireVerb:true rows ONLY launch on an explicit launch verb (new/open/…),
    // never on a bare "<app> for <name>" — so "estimate for Steve" / "stock for
    // sliders" still fall through to the inline document/contact lookups below.
    // Rows without requireVerb may also launch when the query STARTS with the app
    // word (e.g. typing "schedule" or "commission" alone).
    var TABLE = [
      { ids:['scheduler'],            re:/\b(schedule|scheduler|calendar|appointments?|availability)\b/,         icon:'calendar',     cc:'#0EA5E9', verb:'Open',  ctx:function(s){var p=grabPeriod(s); return p?{options:{focus:p}, suffix:' — '+p}:null;} },
      { ids:['estimate-creator'],     re:/\b(estimate|estimates|quote|quotes|bid|bids|proposal)\b/,             icon:'file-text',    cc:'#F59E0B', verb:'New',   requireVerb:true, ctx:function(s){var n=grabFor(s); return n?{options:{customer:n}, suffix:' for '+n}:null;} },
      { ids:['zdz-sketch-pad','sketch'], re:/\b(sketch|sketchpad|draw(?:ing)?|doodle|diagram)\b/,                 icon:'pencil',       cc:'#F59E0B', verb:'Start', ctx:null },
      { ids:['stock-checker'],        re:/\b(stock|inventory|in stock|on hand)\b/,                              icon:'package',      cc:'#3B82F6', verb:'Check', requireVerb:true, ctx:function(s){var n=grabFor(s); return n?{options:{query:n}, suffix:' for '+n}:null;} },
      { ids:['commission-calculator'], re:/\b(commission|commissions|payroll|installer pay|sales ledger|my pay)\b/, icon:'calculator',  cc:'#10B981', verb:'Open',  ctx:function(s){var p=grabPeriod(s); return p?{options:{period:p}, suffix:' — '+p}:null;} },
      { ids:['lead-generator'],       re:/\b(leads?|lead generator|prospects?)\b/,                              icon:'target',       cc:'#22C55E', verb:'Open',  requireVerb:true, ctx:null },
      { ids:['satisfaction-surveys'], re:/\b(surveys?|satisfaction|csat)\b/,                                    icon:'clipboard-check', cc:'#3B82F6', verb:'Open', requireVerb:true, ctx:null },
      { ids:['prep'],                 re:/\b(prep|cutter|cut(?:ting)? queue|fabricat|shop queue)\b/,            icon:'scissors',     cc:'#F59E0B', verb:'Open',  ctx:null },
      { ids:['receipts'],             re:/\b(receipts?)\b/,                                                     icon:'receipt',      cc:'#0EA5E9', verb:'Open',  requireVerb:true, ctx:null },
      { ids:['knowledge-vault'],      re:/\b(knowledge|knowledge vault|knowledge base)\b/,                      icon:'book-open',    cc:'#22C55E', verb:'Open',  ctx:null },
      { ids:['internal-messaging'],   re:/\b(messages?|messaging|team chat|inbox)\b/,                           icon:'message-square', cc:'#3B82F6', verb:'Open', requireVerb:true, ctx:null },
      { ids:['zdz-media','zdz-media-all'], re:/\b(media|photos? library|gallery|image library)\b/,                icon:'image',        cc:'#64748B', verb:'Open',  ctx:null },
      { ids:['game'],                 re:/\b(game|play a game)\b/,                                              icon:'gamepad-2',    cc:'#84CC16', verb:'Open',  ctx:null }
    ];

    // v2.28.1: aggregate/analytic COMMISSION questions ("commission by
    // salesperson", "commission report for the team", "average commission",
    // "how much commission did we pay") must NOT launch the widget — they're
    // questions for the Brain Bot. Skip the launch entirely so they fall through
    // to chat. (A named-person commission was already handled as an inline card
    // before the launch detector ran.)
    var commAggregate = /\bcommissions?\b/.test(lower)
      && /\b(by (?:salesperson|rep|person|product|type|month)|report|the (?:whole )?team|everyone|company-?wide|did we pay|do we pay|we paid|our (?:total |average )?commission|average commission|total commissions?|how do commissions?|how does commission|commission rates?|commission structure)\b/.test(lower);

    for (var i = 0; i < TABLE.length; i++) {
      var row = TABLE[i];
      if (!row.re.test(lower)) continue;
      // Don't launch the Commission app on an aggregate/analytic commission query.
      if (commAggregate && row.ids.indexOf('commission-calculator') !== -1) return null;
      // Require a launch verb, OR (for non-requireVerb rows) the query begins with
      // the app word (e.g. "schedule" / "commission" typed alone).
      var startsWithApp = !row.requireVerb && row.re.test(lower.slice(0, 14));
      if (!hasVerb && !startsWithApp) continue;
      // Resolve the first registered+visible id for this app.
      var visible = (typeof getVisibleApps === 'function') ? getVisibleApps() : (zdzData.apps || []);
      var appId = null;
      for (var k = 0; k < row.ids.length; k++) {
        if (visible.some(function (a) { return a.id === row.ids[k]; })) { appId = row.ids[k]; break; }
      }
      if (!appId) continue; // app not installed/visible → let it fall through
      var appCfg = visible.filter(function (a) { return a.id === appId; })[0] || {};
      var appName = appCfg.nm || appId;
      var ctx = row.ctx ? row.ctx(q) : null;
      var opts = { source: 'orchestrator' };
      if (ctx && ctx.options) { for (var key in ctx.options) opts[key] = ctx.options[key]; }
      return {
        kind: 'shell',
        name: row.verb + ' ' + appName + (ctx && ctx.suffix ? ctx.suffix : ''),
        desc: 'Jumps to ' + appName + ' on your dashboard',
        icon: appCfg.icon || row.icon,
        color: appCfg.cc || row.cc,
        run: (function (id, o) {
          return function () { try { Haptics.tap(); } catch (e) {} openApp(id, o); };
        })(appId, opts)
      };
    }
    return null;
  }

  function renderCmdResults(q) {
    // v2.21.0: drop secondary surfaces (springboard:false) from the palette —
    // both the empty-query quick-launch list and the fuzzy name/desc matches
    // below read from this one array.
    var allApps = (zdzData.apps || []).filter(function (a) { return !isSecondarySurface(a); });
    var query = q.toLowerCase().trim();

    // ── Empty query: show all apps as quick-launch ──
    if (!query) {
      var display = allApps.slice(0, 10);
      results.innerHTML = display.map(function (a) {
        return '<button class="cmd-item" onclick="closeCmdPalette();openApp(\'' + a.id + '\')">' +
          '<div class="ci-icon" style="background:' + a.cc + '"><i data-lucide="' + a.icon + '"></i></div>' +
          '<div class="ci-text"><div class="ci-name">' + a.nm + '</div><div class="ci-desc">' + (a.desc || '') + '</div></div>' +
          '<span class="ci-shortcut">' + (a.slash || '') + '</span></button>';
      }).join('');
      refreshIcons();
      return;
    }

    var html = '';

    // ── v2.20.0: ALWAYS show "Ask Brain Bot" as the primary action ──
    // v2.20.0 r4: Guard against TSA (sales-analytics) being inactive.
    // v2.26.3: Check the REGISTERED apps (zdzData.apps), not getVisibleApps().
    // Analytics is a secondary surface (springboard:false) so it no longer shows
    // as a top-bar icon — but it IS still registered/active and must remain
    // reachable from search as "Ask Brain Bot". Keying on getVisibleApps() would
    // silently drop the row once Analytics left the springboard.
    var hasBrainBot = (zdzData.apps || []).some(function(a) {
      return a && (a.id === 'sales-analytics' || a.id === 'zdz-sales-analytics');
    });
    // v2.21.4 (cross-app orchestrator): consult the deterministic router first.
    // A 'shell' action (e.g. open Camera w/ a pre-label) becomes its OWN primary
    // row and runs instantly. A 'route' relabels the Brain Bot row and carries an
    // orchestrator_hint. Anything else falls through to the plain "Ask Brain Bot".
    var route = zdzOrchestratorRoute(q.trim());

    if (route && route.kind === 'inline') {
      // Inline-answer row: clicking (or Enter) fetches the result and renders a
      // compact card IN the palette, instead of opening the full chat.
      window.__tsPendingInline = route;
      html += '<button class="cmd-item cmd-item-analytics" onclick="zdzRunInline()">' +
        '<div class="ci-icon" style="background:' + route.color + '"><i data-lucide="' + route.icon + '"></i></div>' +
        '<div class="ci-text"><div class="ci-name">' + escapeHtml(route.name) + '</div><div class="ci-desc">' + escapeHtml(route.desc) + '</div></div>' +
        '<span class="ci-shortcut" style="font-size:11px;color:' + route.color + '">⏎</span></button>' +
        '<div id="zdz-inline-answer"></div>';
    } else if (route && route.kind === 'shell') {
      // Stash the action so the inline onclick can call it without serializing.
      window.__tsPendingShellAction = route.run;
      html += '<button class="cmd-item cmd-item-analytics" onclick="closeCmdPalette();(window.__tsPendingShellAction&&window.__tsPendingShellAction())">' +
        '<div class="ci-icon" style="background:' + route.color + '"><i data-lucide="' + route.icon + '"></i></div>' +
        '<div class="ci-text"><div class="ci-name">' + escapeHtml(route.name) + '</div><div class="ci-desc">' + escapeHtml(route.desc) + '</div></div>' +
        '<span class="ci-shortcut" style="font-size:11px;color:' + route.color + '">⏎</span></button>';
    } else if (hasBrainBot) {
      var truncated = q.trim().length > 50 ? q.trim().substring(0, 50) + '…' : q.trim();

      // Default Brain Bot row.
      var biName = 'Ask Brain Bot';
      var biIcon = 'sparkles';
      var biDesc = '"' + truncated + '"';
      var biOptions = { prompt: q.trim() };

      // A recognized 'route' relabels the row + attaches the structured hint.
      if (route && route.kind === 'route') {
        biName    = route.name;
        biIcon    = route.icon;
        biDesc    = route.desc;
        biOptions = route.options;
      }

      // Stash options so the inline onclick passes the hint through to the app.
      window.__tsPendingAskOptions = biOptions;
      html += '<button class="cmd-item cmd-item-analytics" onclick="closeCmdPalette();openApp(\'sales-analytics\',window.__tsPendingAskOptions||{})">' +
        '<div class="ci-icon" style="background:#6366F1"><i data-lucide="' + biIcon + '"></i></div>' +
        '<div class="ci-text"><div class="ci-name">' + escapeHtml(biName) + '</div><div class="ci-desc">' + escapeHtml(biDesc) + '</div></div>' +
        '<span class="ci-shortcut" style="font-size:11px;color:#6366F1">⏎</span></button>';
    }

    // ── 1. App name matches (fuzzy) ──
    var appMatches = allApps.map(function (a) {
      var nameScore = fuzzyMatch(query, a.nm);
      var descScore = a.desc ? fuzzyMatch(query, a.desc) * 0.5 : 0;
      var aliasScore = 0;
      if (a.aliases) {
        a.aliases.forEach(function (al) {
          aliasScore = Math.max(aliasScore, fuzzyMatch(query, al));
        });
      }
      return { app: a, score: Math.max(nameScore, descScore, aliasScore) };
    }).filter(function (m) { return m.score >= 0.5; })
      .sort(function (a, b) { return b.score - a.score; });

    if (appMatches.length) {
      html += appMatches.map(function (m) {
        return '<button class="cmd-item" onclick="closeCmdPalette();openApp(\'' + m.app.id + '\')">' +
          '<div class="ci-icon" style="background:' + m.app.cc + '"><i data-lucide="' + m.app.icon + '"></i></div>' +
          '<div class="ci-text"><div class="ci-name">' + m.app.nm + '</div><div class="ci-desc">' + (m.app.desc || '') + '</div></div></button>';
      }).join('');
    }

    // ── 2. App matches follow (already rendered above) ──
    // The Brain Bot row is always first, app matches follow.

    results.innerHTML = html;
    refreshIcons();
  }
}

// ---- v2.22.0: INLINE ORCHESTRATOR ANSWER (dashboard "ask" field) ----
// The SERVER is the authority. We send the raw query to /zorderz/v1/orchestrate, which
// classifies it (deterministic, Poe-free) and — for the read verbs — returns the
// resolved data in the same call. This makes phrasing robustness a server concern,
// not a JS-regex guess: even if the palette row label guessed wrong, the action
// reflects what the server actually found. 'chat' (or any miss) hands off to the
// full Brain Bot. Contact/lookup render as compact cards with an "Open in chat →".
// v2.28.8 — INSTANT SKELETON. The client detector already knows the verb,
// subject and period before the server replies, so we paint a real card frame
// immediately (subject + period + a shimmering figure placeholder) instead of a
// blank "Looking that up…". On a cache hit the server returns in ~50ms and the
// skeleton is barely seen; on a cold FreshBooks call the box still feels alive.
function zdzInlineSkeleton(route) {
  var hint = (route && route.options && route.options.orchestrator_hint) || {};
  var verb = (route && route.verb) || hint.verb || '';
  var esc = (typeof escapeHtml === 'function') ? escapeHtml : function (x){ return String(x==null?'':x); };
  if (verb === 'commission') {
    var subj = hint.subject && hint.subject !== 'my' ? hint.subject : 'Your';
    var who  = (subj === 'Your') ? 'Your commission' : (subj + '\u2019s commission');
    var per  = hint.period ? (' \u00b7 ' + esc(hint.period)) : '';
    return '<div class="zdz-inline-card zdz-cc-card zdz-skeleton">' +
      '<div class="zdz-cc-head">' +
        '<span class="zdz-cc-amount zdz-shimmer">$\u2009\u2009\u2009\u2009</span>' +
        '<span class="zdz-cc-who">' + esc(who) + per + '</span>' +
      '</div>' +
      '<div class="zdz-cc-basis zdz-shimmer-line"></div>' +
    '</div>';
  }
  if (verb === 'contact') {
    var nm = (route && route.query) ? esc(route.query) : 'Contact';
    return '<div class="zdz-inline-card zdz-contact-card zdz-skeleton">' +
      '<div class="zdz-ct-name">' + nm + '</div>' +
      '<div class="zdz-ct-row zdz-shimmer-line"></div>' +
      '<div class="zdz-ct-row zdz-shimmer-line"></div>' +
    '</div>';
  }
  if (verb === 'lookup') {
    return '<div class="zdz-inline-card zdz-skeleton">' +
      '<div class="zdz-ct-row zdz-shimmer-line"></div>' +
      '<div class="zdz-ct-row zdz-shimmer-line"></div>' +
      '<div class="zdz-ct-row zdz-shimmer-line"></div>' +
    '</div>';
  }
  return '<div class="zdz-inline-card zdz-inline-loading">Looking that up\u2026</div>';
}

function zdzRunInline() {
  var route = window.__tsPendingInline;
  if (!route) return;
  var mount = document.getElementById('zdz-inline-answer');
  if (!mount) return;

  mount.innerHTML = zdzInlineSkeleton(route);

  var q = (route.query && route.rawQuery) ? route.rawQuery : (route.rawQuery || route.query || '');
  var url = zdzData.apiUrl + 'orchestrate?query=' + encodeURIComponent(q);
  fetch(url, { headers: { 'X-WP-Nonce': zdzData.nonce } })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      if (!res || res.route !== 'inline') {
        // Server says this isn't an inline answer → open the full chat.
        mount.innerHTML = '';
        zdzInlineHandoff(route);
        return;
      }
      // v2.28.11: stash any secondary-intent hint on the route so every renderer
      // can surface the "+ also: …" chip uniformly (contact gets res.result, not
      // the outer res, so it would otherwise miss res.secondary).
      if (route && typeof route === 'object') route.secondary = res.secondary || null;
      if (res.verb === 'contact') {
        renderInlineContact(mount, res.result || {}, route);
      } else if (res.verb === 'lookup') {
        renderInlineDocs(mount, res, route);
      } else if (res.verb === 'commission') {
        renderInlineCommission(mount, res, route);
      } else {
        mount.innerHTML = '';
        zdzInlineHandoff(route);
      }
    })
    .catch(function () {
      // Network/endpoint failure → graceful handoff to the full chat.
      mount.innerHTML = '';
      zdzInlineHandoff(route);
    });
}

// Open the full Analytics chat with the original prompt (the handoff path).
function zdzInlineHandoff(route) {
  closeCmdPalette();
  openApp('sales-analytics', (route && route.options) || {});
}

// v2.28.11: run the SECONDARY intent of a compound ask in place. classify()
// resolves only the first intent; when it also reports a `secondary` hint
// ("Devin's phone and Jordan's commission" → primary commission + secondary
// contact), the card shows a "+ also: …" chip that re-runs the inline answer for
// the second half here — so the dropped intent is one tap away instead of lost.
function zdzRunInlineSecondary(secQuery) {
  if (!secQuery) return;
  // Reflect it in the search box (if open) so the user sees what's being shown,
  // then re-run the inline pipeline against the secondary text.
  try {
    var input = document.getElementById('cmd-input');
    if (input) input.value = secQuery;
  } catch (e) {}
  window.__tsPendingInline = { query: secQuery, rawQuery: secQuery, options: { prompt: secQuery } };
  zdzRunInline();
}

// Build the "+ also: <label>" chip HTML from a classify() `secondary` hint, or ''.
function zdzSecondaryChip(secondary) {
  if (!secondary || !secondary.query || !secondary.verb) return '';
  var esc = (typeof escapeHtml === 'function') ? escapeHtml : function (s) { return String(s == null ? '' : s); };
  var verbLabel = secondary.verb === 'commission' ? 'commission'
                : secondary.verb === 'contact' ? 'contact'
                : secondary.verb === 'lookup' ? 'documents' : 'more';
  // Encode the query for a safe inline onclick (single-quoted JS string arg).
  var safeArg = String(secondary.query).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  return '<button class="zdz-inline-chip zdz-inline-more" onclick="zdzRunInlineSecondary(\'' + safeArg + '\')">' +
           '+ also: ' + esc(secondary.query) + ' (' + verbLabel + ') →' +
         '</button>';
}

// Render a document-lookup result (estimates/invoices) inline. A single doc shows
// a compact summary; several show a short list. Either way, "Open in chat →" hands
// off to the full Analytics view where the interactive estimate cards live.
function renderInlineDocs(mount, res, route) {
  var esc = (typeof escapeHtml === 'function') ? escapeHtml : function (s) { return String(s == null ? '' : s); };
  var r = res.result || {};
  if (r.error || r.needs_clarify || res.render === 'message') {
    var msg = r.message || r.error || 'No open estimates or invoices found.';
    mount.innerHTML =
      '<div class="zdz-inline-card">' +
        '<div class="zdz-inline-msg">' + esc(msg) + '</div>' +
        '<button class="zdz-inline-chat" onclick="zdzInlineHandoff(window.__tsPendingInline)">Open in chat →</button>' +
      '</div>';
    return;
  }
  var docs = r.documents || [];
  var name = r.customer_name || (route && route.query) || 'that customer';
  var rows = docs.slice(0, 6).map(function (d) {
    var num = d.estimate_num || d.invoice_num || d.number || '';
    var status = d.status_text || d.status || '';
    var total = (d.total_hidden ? '' : (d.total != null ? ('$' + d.total) : ''));
    var label = (d.doc_type ? (d.doc_type.charAt(0).toUpperCase() + d.doc_type.slice(1)) : 'Doc') + (num ? ' #' + num : '');
    var meta = [status, total].filter(Boolean).join(' · ');
    return '<div class="zdz-inline-row"><span class="zdz-inline-ic">📄</span>' + esc(label) + (meta ? ' — ' + esc(meta) : '') + '</div>';
  }).join('');

  // v2.28.11: CLOSEST-MATCH BANNER — the document card resolves the customer
  // through TSEC_TSA_Bridge, which (like the contact bridge) can accept a match
  // on a shared last name when the asked first name matched nobody (live:
  // "estimates for Sam Rivera" returned Chris Rivera's docs). When the bridge
  // flags closest_match, say so instead of presenting the wrong customer's
  // documents as exact.
  var askedBanner = '';
  if (r.closest_match && r.asked) {
    askedBanner =
      '<div class="zdz-inline-note" style="color:var(--sys-warn,#b45309);">' +
        'No exact match for "' + esc(r.asked) + '" — showing the closest customer, ' +
        esc(name) + '. Open in chat to search more.' +
      '</div>';
  }
  // v2.28.11: "+ also: …" chip for a compound ask's second intent (route carries it).
  var moreChip = zdzSecondaryChip(route && route.secondary);

  mount.innerHTML =
    '<div class="zdz-inline-card">' +
      '<div class="zdz-inline-name">' + esc(name) + ' — open documents</div>' +
      askedBanner +
      rows +
      '<button class="zdz-inline-chat" onclick="zdzInlineHandoff(window.__tsPendingInline)">Open in chat →</button>' +
      moreChip +
    '</div>';
}

// v2.28.0: Render a commission result inline — headline figure up front, with
// collapsible drill-down (jobs counted, rate basis, by-product split). The full
// detail is CONTAINED but collapsed for a fast, scannable answer. Denied/kiosk
// and clarify states show the bridge's friendly message + a chat handoff.
function renderInlineCommission(mount, res, route) {
  var esc = (typeof escapeHtml === 'function') ? escapeHtml : function (s) { return String(s == null ? '' : s); };
  var r = res.result || {};
  function money(n) { return '$' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

  // Non-figure states (denied on kiosk, error, no-match) → message + handoff.
  // v2.28.11: ALSO treat "no amount produced" as a non-figure state. The bridge
  // returns success:true WITHOUT an `amount` key when the named person isn't a
  // salesperson with a commission profile (it sets `message` instead) — see
  // TSCC_TSA_Bridge::commission_calc_for_tsa. Previously this fell through to the
  // card and money(undefined) rendered a misleading "$0.00 · Zachary", reading as
  // "earned $0" rather than "not a known rep". A real rep with genuinely zero
  // commission DOES carry amount:0, so this only catches the not-found case.
  var hasAmount = (r.amount !== null && r.amount !== undefined);
  if (res.render === 'denied' || res.render === 'error' || res.render === 'clarify' || !r.success || !hasAmount) {
    var msg = r.message || r.error || 'I couldn’t pull that commission figure.';
    mount.innerHTML =
      '<div class="zdz-inline-card">' +
        '<div class="zdz-inline-msg">' + esc(msg) + '</div>' +
        '<button class="zdz-inline-chat" onclick="zdzInlineHandoff(window.__tsPendingInline)">Ask in chat →</button>' +
      '</div>';
    return;
  }

  var b = r.breakdown || {};
  var subject = r.subject || (route && route.query) || 'Salesperson';
  var period = r.period || '';

  // Build the collapsible sections (only those with content).
  var sections = '';

  // 1) Jobs counted.
  var jobs = b.jobs || [];
  if (jobs.length) {
    var jobRows = jobs.slice(0, 12).map(function (j) {
      var head = (j.number ? '#' + esc(j.number) : 'Job') + (j.customer ? ' · ' + esc(j.customer) : '');
      var right = money(j.commission);
      return '<div class="zdz-cc-row"><span class="zdz-cc-row-l">' + head + '</span><span class="zdz-cc-row-r">' + right + '</span></div>';
    }).join('');
    sections += zdzCcSection('jobs', 'Jobs counted', '(' + (b.job_count || jobs.length) + ')', jobRows);
  }

  // 2) Rate basis.
  var basisBits = [];
  if (b.rate) basisBits.push('<div class="zdz-cc-row"><span class="zdz-cc-row-l">Rate</span><span class="zdz-cc-row-r">' + esc(b.rate) + '</span></div>');
  if (b.net != null) basisBits.push('<div class="zdz-cc-row"><span class="zdz-cc-row-l">Net commissionable</span><span class="zdz-cc-row-r">' + money(b.net) + '</span></div>');
  if (b.invoice_count != null) basisBits.push('<div class="zdz-cc-row"><span class="zdz-cc-row-l">Invoices</span><span class="zdz-cc-row-r">' + esc(String(b.invoice_count)) + '</span></div>');
  if (basisBits.length) sections += zdzCcSection('basis', 'How it’s calculated', '', basisBits.join(''));

  // 3) By product line.
  var prod = b.product_split || [];
  if (prod.length) {
    var prodRows = prod.map(function (p) {
      return '<div class="zdz-cc-row"><span class="zdz-cc-row-l">' + esc(p.label) + '</span><span class="zdz-cc-row-r">' + money(p.net) + ' net</span></div>';
    }).join('');
    sections += zdzCcSection('products', 'By product line', '', prodRows);
  }

  mount.innerHTML =
    '<div class="zdz-inline-card zdz-cc-card">' +
      '<div class="zdz-cc-head">' +
        '<span class="zdz-cc-amount">' + money(r.amount) + '</span>' +
        '<span class="zdz-cc-who">' + esc(subject) + (period ? ' · ' + esc(period) : '') + '</span>' +
      '</div>' +
      (r.basis ? '<div class="zdz-cc-basis">' + esc(r.basis) + '</div>' : '') +
      sections +
      // v2.28.8 — show data recency when this came from the 10-min cache so the
      // figure is trusted; a live (just-computed) figure shows nothing.
      (r.cached && r.cached_at ? '<div class="zdz-cc-fresh"><span class="zdz-cc-dot">\u25CF</span> Updated ' + esc(r.cached_at) + '</div>' : '') +
      '<div class="zdz-cc-actions">' +
        '<button class="zdz-inline-chip" onclick="zdzCommissionOpenWidget()">Open in Commission ↗</button>' +
        '<button class="zdz-inline-chip" onclick="zdzInlineHandoff(window.__tsPendingInline)">Ask in chat ↗</button>' +
        zdzSecondaryChip(res.secondary) +
      '</div>' +
    '</div>';

  // Wire the collapsible section toggles.
  Array.prototype.forEach.call(mount.querySelectorAll('.zdz-cc-sec-head'), function (h) {
    h.addEventListener('click', function () {
      var sec = h.parentNode;
      sec.classList.toggle('is-open');
    });
  });

  // v2.28.2: SMART AUTO-EXPAND. The card defaults to all-collapsed (just the
  // headline), but if the request explicitly asked about a section, open ONLY
  // that one. focus comes from the client route (route.focus) or, if the server
  // forwarded it, res.focus / the orchestrator hint.
  var focus = (route && route.focus)
    || (res && res.focus)
    || (route && route.options && route.options.orchestrator_hint && route.options.orchestrator_hint.focus)
    || '';
  if (focus) {
    var target = mount.querySelector('.zdz-cc-sec[data-sec="' + focus + '"]');
    if (target) target.classList.add('is-open');
  }
}

// One collapsible section: a tappable header (title + optional count + caret)
// and a hidden body that expands on click.
function zdzCcSection(key, title, count, bodyHtml) {
  var esc = (typeof escapeHtml === 'function') ? escapeHtml : function (s) { return String(s == null ? '' : s); };
  return '<div class="zdz-cc-sec" data-sec="' + key + '">' +
    '<button type="button" class="zdz-cc-sec-head">' +
      '<span class="zdz-cc-sec-title">' + esc(title) + (count ? ' <span class="zdz-cc-sec-count">' + esc(count) + '</span>' : '') + '</span>' +
      '<span class="zdz-cc-caret">▸</span>' +
    '</button>' +
    '<div class="zdz-cc-sec-body">' + bodyHtml + '</div>' +
  '</div>';
}

// Open the full Commission widget from the inline card (carries the prompt so
// the widget/orchestrator hint can pre-focus the salesperson + period).
function zdzCommissionOpenWidget() {
  var route = window.__tsPendingInline || {};
  var opts = { source: 'orchestrator' };
  var hint = (route.options && route.options.orchestrator_hint) || {};
  if (hint.subject) opts.salesperson = hint.subject;
  if (hint.period) opts.period = hint.period;
  closeCmdPalette();
  openApp('commission-calculator', opts);
}

function renderInlineContact(mount, res, route) {
  if (!res || typeof res !== 'object') { mount.innerHTML = ''; zdzInlineHandoff(route); return; }

  var esc = (typeof escapeHtml === 'function') ? escapeHtml : function (s) { return String(s == null ? '' : s); };
  var render = res.render || '';

  // Error / clarify / denied → short message, with a chat handoff to dig deeper.
  if (render === 'error' || render === 'clarify' || render === 'denied' || render === 'message') {
    var msg = res.message || res.error || 'No contact details available.';
    var nm = (res.contact && res.contact.name) ? res.contact.name : '';
    var loc = (res.contact && res.contact.city) ? res.contact.city : '';
    var sub = (nm ? ('<div class="zdz-inline-sub"><strong>' + esc(nm) + '</strong>' + (loc ? ' — ' + esc(loc) : '') + '</div>') : '');
    mount.innerHTML =
      '<div class="zdz-inline-card">' +
        '<div class="zdz-inline-msg">' + esc(msg) + '</div>' + sub +
        '<button class="zdz-inline-chat" onclick="zdzInlineHandoff(window.__tsPendingInline)">Open in chat →</button>' +
      '</div>';
    return;
  }

  // Card: a resolved contact with details.
  var c = res.contact || {};
  var rows = '';
  if (c.phone) {
    var tel = String(c.phone).replace(/[^0-9+]/g, '');
    rows += '<a class="zdz-inline-row" href="tel:' + esc(tel) + '"><span class="zdz-inline-ic">📞</span>' + esc(c.phone) + '</a>';
  }
  if (c.email) {
    rows += '<a class="zdz-inline-row" href="mailto:' + esc(c.email) + '"><span class="zdz-inline-ic">✉️</span>' + esc(c.email) + '</a>';
  }
  if (c.address) {
    rows += '<div class="zdz-inline-row"><span class="zdz-inline-ic">📍</span>' + esc(c.address) + '</div>';
  } else if (c.city) {
    rows += '<div class="zdz-inline-row"><span class="zdz-inline-ic">📍</span>' + esc(c.city) + '</div>';
  }

  var header = esc(c.name || 'Contact');
  if (c.company && c.company !== c.name) header += ' <span class="zdz-inline-co">· ' + esc(c.company) + '</span>';

  // v2.28.11: CLOSEST-MATCH BANNER — when the bridge resolved a different person
  // than was asked (fuzzy surname / dropped first name), say so up front instead
  // of presenting the wrong contact as exact (live: "who is Sam Rivera" →
  // Chris Rivera). The card still shows the match (it's useful), but labeled.
  var askedBanner = '';
  if (res.closest_match && res.asked) {
    askedBanner =
      '<div class="zdz-inline-note" style="color:var(--sys-warn,#b45309);">' +
        'No exact match for "' + esc(res.asked) + '" — showing the closest contact, ' +
        esc(c.name || 'this customer') + '. Open in chat to search more.' +
      '</div>';
  }

  // A note from the bridge (e.g. "no email on file") and the chat handoff.
  var note = res.message ? '<div class="zdz-inline-note">' + esc(res.message) + '</div>' : '';

  // v2.28.11: "+ also: …" chip for a compound ask's second intent (route carries it).
  var moreChip = zdzSecondaryChip(route && route.secondary);

  mount.innerHTML =
    '<div class="zdz-inline-card">' +
      '<div class="zdz-inline-name">' + header + '</div>' +
      askedBanner +
      rows + note +
      '<button class="zdz-inline-chat" onclick="zdzInlineHandoff(window.__tsPendingInline)">Open in chat →</button>' +
      moreChip +
    '</div>';
}

// ---- v2.14.4 D1: ADD-TO-HOME-SCREEN BANNER ----
function initInstallBanner() {
  // Skip if already running as installed web app
  // Note: iOS 26 defaults all home screen shortcuts to standalone mode,
  // so this check remains valid — if the user is in standalone, they
  // already have it "installed".
  var isStandalone = window.matchMedia('(display-mode: standalone)').matches ||
                     window.navigator.standalone === true;
  if (isStandalone) return;

  // v2.18.0: Time-limited dismissal (7 days) instead of permanent
  try {
    var dismissed = localStorage.getItem('zdz_install_dismissed');
    if (dismissed) {
      var dismissedAt = parseInt(dismissed, 10);
      if (dismissedAt && (Date.now() - dismissedAt) < 7 * 24 * 60 * 60 * 1000) {
        return; // Still within 7-day cooldown
      }
      // Expired — remove and re-show
      localStorage.removeItem('zdz_install_dismissed');
    }
  } catch(e) {}

  var banner = document.getElementById('zdz-install-banner');
  if (!banner) return;

  var howBtn = document.getElementById('zdz-install-how');
  var dismissBtn = document.getElementById('zdz-install-dismiss');
  var textEl = banner.querySelector('.zdz-install-text');

  // v2.17.1: Detect platform and browser for specific install instructions
  var ua = navigator.userAgent || '';
  var isIOS = /iphone|ipad|ipod/i.test(ua);
  var isSafari = /safari/i.test(ua) && !/chrome|crios|fxios|edgios/i.test(ua);
  var isChrome = /chrome/i.test(ua) && !/edg/i.test(ua);
  var isEdge = /edg/i.test(ua);
  var isFirefox = /firefox|fxios/i.test(ua);
  var isMac = /macintosh/i.test(ua);
  var isAndroid = /android/i.test(ua);

  // v2.18.0: Improved copy — emphasize the benefit, not the action
  if (isIOS && isSafari) {
    if (textEl) textEl.textContent = 'Get faster loading & full-screen mode';
    if (howBtn) howBtn.textContent = 'Add to Home Screen';
  } else if (isIOS) {
    // Non-Safari iOS browser — need to open in Safari first
    if (textEl) textEl.textContent = 'Open in Safari to install as a full-screen app';
    if (howBtn) howBtn.textContent = 'Copy Link';
  } else {
    if (textEl) textEl.textContent = 'Install Zorderz for faster loading';
    if (howBtn) howBtn.textContent = 'Install';
  }

  // Show after a brief delay so it doesn't flash on first paint
  setTimeout(function () {
    banner.style.display = 'flex';
  }, 2000);

  if (howBtn) {
    howBtn.addEventListener('click', function () {
      // If we have a deferred prompt (Chrome/Edge), use it for one-click install
      if (window._tsDeferredPrompt) {
        window._tsDeferredPrompt.prompt();
        window._tsDeferredPrompt.userChoice.then(function () {
          banner.style.display = 'none';
          window._tsDeferredPrompt = null;
        });
        return;
      }
      // iOS Safari — show the visual step-by-step guide overlay
      if (isIOS && isSafari) {
        var guide = document.getElementById('zdz-ios-install-guide');
        if (guide) {
          guide.style.display = 'flex';
          banner.style.display = 'none';

          // v2.18.0: Add bounce animation to the guide arrow
          var arrow = document.getElementById('zdz-ios-guide-arrow');
          if (arrow) arrow.classList.add('zdz-arrow-bounce');

          // Close handlers
          var closeBtn = document.getElementById('zdz-ios-guide-close');
          var backdrop = guide.querySelector('.zdz-ios-guide-backdrop');
          function closeGuide() {
            guide.style.display = 'none';
            banner.style.display = 'flex';
            var arrow2 = document.getElementById('zdz-ios-guide-arrow');
            if (arrow2) arrow2.classList.remove('zdz-arrow-bounce');
          }
          if (closeBtn) closeBtn.onclick = closeGuide;
          if (backdrop) backdrop.onclick = closeGuide;
        }
        return;
      }
      // v2.18.0: Non-Safari iOS — show dedicated guide overlay with Copy Link
      if (isIOS) {
        var nsGuide = document.getElementById('zdz-nonsafari-guide');
        if (nsGuide) {
          nsGuide.style.display = 'flex';
          banner.style.display = 'none';
          var nsCopy = document.getElementById('zdz-nonsafari-copy');
          var nsClose = document.getElementById('zdz-nonsafari-close');
          var nsBackdrop = document.getElementById('zdz-nonsafari-backdrop');
          function closeNsGuide() {
            nsGuide.style.display = 'none';
            banner.style.display = 'flex';
          }
          if (nsCopy) {
            nsCopy.addEventListener('click', function() {
              try {
                navigator.clipboard.writeText(window.location.origin).then(function() {
                  nsCopy.textContent = '✓ Copied!';
                  nsCopy.style.background = '#059669';
                  setTimeout(function() {
                    nsCopy.textContent = 'Copy Link';
                    nsCopy.style.background = '';
                  }, 2000);
                }).catch(function() {
                  showToast('Open ' + location.hostname + ' in Safari to install', 6000);
                });
              } catch(e) {
                showToast('Open ' + location.hostname + ' in Safari to install', 6000);
              }
            });
          }
          if (nsClose) nsClose.onclick = closeNsGuide;
          if (nsBackdrop) nsBackdrop.onclick = closeNsGuide;
        } else {
          showToast('Open ' + location.hostname + ' in Safari → tap ⋯ → Share → Add to Home Screen', 8000);
        }
        return;
      }
      // Other platforms — show platform-specific instructions
      if (isChrome && !isAndroid) {
        showToast('Look for the install icon (⊕) in the right side of the address bar, or go to Menu (⋮) → "Save and share" → "Install Zorderz"', 8000);
      } else if (isEdge) {
        showToast('Click Menu (⋯) → Apps → "Install Zorderz" — or look for the install icon in the address bar', 7000);
      } else if (isFirefox) {
        showToast('Firefox doesn\'t support app install — use Chrome or Edge for the best experience, or bookmark this page', 7000);
      } else if (isSafari && isMac) {
        showToast('In Safari: File → "Add to Dock" to install Zorderz as a desktop app', 6000);
      } else {
        showToast('Check your browser menu for "Install app" or "Add to Home Screen"', 6000);
      }
    });
  }

  if (dismissBtn) {
    dismissBtn.addEventListener('click', function () {
      banner.style.display = 'none';
      // v2.18.0: Store timestamp instead of boolean (7-day cooldown)
      try { localStorage.setItem('zdz_install_dismissed', String(Date.now())); } catch(e) {}
    });
  }

  // Listen for the native install prompt (Chrome/Edge/Android) — enables one-click
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    window._tsDeferredPrompt = e;
    if (howBtn) {
      howBtn.textContent = 'Install';
    }
    if (textEl) {
      textEl.textContent = 'Install Zorderz for faster loading';
    }
    // Show banner immediately if it was hidden
    banner.style.display = 'flex';
  });

  // Hide banner if app gets installed
  window.addEventListener('appinstalled', function () {
    banner.style.display = 'none';
    window._tsDeferredPrompt = null;
    try { localStorage.setItem('zdz_install_dismissed', String(Date.now())); } catch(e) {}
    showToast('✓ Zorderz installed');
  });
}

// ---- PLUGIN CONTRACT (v2.14.3) ----
// Explicitly expose functions that plugins call via window.*.
// These are already top-level in this script, but explicit assignment
// documents the contract and guards against future module refactoring.
window.switchView    = switchView;
window.refreshIcons  = refreshIcons;
window.openApp       = openApp;
window.showToast     = showToast;
window.applyTheme    = applyTheme;
window.zdzRunInline    = zdzRunInline;     // v2.21.5: inline orchestrator answer
window.zdzInlineHandoff = zdzInlineHandoff;

// ---- INIT ----

/* v2.15.0: Boot recovery timer — if zdz-ready hasn't fired after 5 seconds,
 * the skeleton hides and a retry option appears. This catches JS errors,
 * script load failures, and edge cases where the SPA never renders. */
(function () {
  var recoveryTimer = setTimeout(function () {
    if (document.body.classList.contains('zdz-ready')) return;
    // Remove skeleton
    var skel = document.querySelector('.zdz-skeleton');
    if (skel) skel.remove();
    // Show content even in error state
    document.body.classList.add('zdz-ready');
    // Inject recovery UI
    var main = document.getElementById('sv-dash');
    if (main) {
      var recovery = document.createElement('div');
      recovery.style.cssText = 'padding:40px 20px;text-align:center;';
      recovery.innerHTML = '<p style="margin-bottom:16px;color:var(--sys-text-sec,#64748B);">Something went wrong loading the app.</p>'
        + '<button onclick="location.reload()" style="padding:12px 24px;border-radius:8px;background:var(--sys-brand,#2C5F8A);color:#fff;font-weight:600;font-size:17px;cursor:pointer;border:none;">Tap to Retry</button>';
      main.prepend(recovery);
    }
  }, 5000);
  // Clear the timer once boot succeeds
  window.__tsClearRecovery = function () { clearTimeout(recoveryTimer); };
})();

document.addEventListener('DOMContentLoaded', () => {
  try {
  // Standard nav items (Apps — more may be injected by plugins)
  document.querySelectorAll('.ni').forEach(btn => {
    btn.addEventListener('click', () => switchView(btn.dataset.view));
  });

  // Logo button → Settings
  const logoBtn = document.getElementById('bnav-logo');
  if (logoBtn) {
    logoBtn.addEventListener('click', () => switchView('sv-settings'));
  }

  applyTheme(state.theme);
  renderGreeting();
  renderBuildStamp();      // v2.24.2: show the running theme build (stale-shell check)
  renderLeadsTile();       // v2.21.3: "N new leads" tile from the unified feed
  renderKioskDemoBanner();  // v2.21.0: show persistent demo-mode indicator if active
  renderAppDock();       // v2.14.4 A4
  initStickyAppBar();    // v2.16.0 T4
  renderQuickStats();
  fetchKPIMetrics();
  renderRecentApps();
  renderAppGrid();
  renderWidgets();
  renderSettings();
  prefetchTopApps();

  initSearch();
  initCommandPalette();
  initAppViewport();
  initLongPressSunlight();
  initPullToRefresh();
  initInstallBanner();   // v2.14.4 D1

  window.addEventListener('online', () => { showToast('Back online ✓'); renderSettings(); });
  window.addEventListener('offline', () => { showToast('You are offline'); renderSettings(); });

  // v2.15.0: Connection quality indicator — surfaces "Low signal" badge
  // when Network Information API reports a slow effective type so field
  // techs understand why things are slower (cellular at job sites).
  try {
    var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if (conn && conn.effectiveType && /^(slow-2g|2g)$/.test(conn.effectiveType)) {
      showToast('Low signal — some features may be slower', 4000);
    }
  } catch(e) {}

  // Listen for system theme changes to update the nav logo
  window.matchMedia('(prefers-color-scheme:dark)').addEventListener('change', () => {
    if (state.theme === 'system') updateNavLogo();
  });

  // v2.14.4 A5: Update logo when crossing the sidebar breakpoint (tablet rotation)
  window.matchMedia('(min-width: 820px)').addEventListener('change', () => {
    updateNavLogo();
  });

  document.body.classList.add('zdz-ready');
  if (window.__tsClearRecovery) window.__tsClearRecovery();
  refreshIcons();

  // v2.21.0: route an external #tsa-session=<id> deep link (TSA digest "Open this
  // chat →") now that the shell + Bridge are ready, and again whenever the hash
  // changes (an installed PWA is reactivated rather than cold-booted on link tap).
  zdzRouteDeepLink();
  window.addEventListener('hashchange', zdzRouteDeepLink);

  // v2.17.1: Track dashboard load and start session heartbeat
  zdzTrack('dashboard_load', 'SPA shell ready');
  setInterval(function () {
    if (document.visibilityState === 'visible') {
      zdzTrack('heartbeat', state.currentView);
    }
  }, 15 * 60 * 1000); // Every 15 minutes while tab is visible

  } catch (bootErr) {
    // v2.15.0: If boot crashes, ensure the user can still see something
    console.error('[Zorderz] Boot failed:', bootErr);
    document.body.classList.add('zdz-ready');
    if (window.__tsClearRecovery) window.__tsClearRecovery();
    var dash = document.getElementById('sv-dash');
    if (dash && !dash.querySelector('.zdz-boot-error')) {
      var errDiv = document.createElement('div');
      errDiv.className = 'zdz-boot-error';
      errDiv.style.cssText = 'padding:40px 20px;text-align:center;';
      errDiv.innerHTML = '<p style="margin-bottom:16px;color:var(--sys-text-sec,#64748B);">Something went wrong. Tap to retry.</p>'
        + '<button onclick="location.reload()" style="padding:12px 24px;border-radius:8px;background:var(--sys-brand,#2C5F8A);color:#fff;font-weight:600;font-size:17px;cursor:pointer;border:none;">Retry</button>';
      dash.prepend(errDiv);
    }
  }
});


// ============================================================================
// v2.11.0: OAuth return handler — shows a toast and jumps to Settings when
// FreshBooks sends the user back with ?zdz_authorized=freshbooks in the URL.
// ============================================================================
(function () {
  try {
    var qs = new URLSearchParams(window.location.search);
    var flag = qs.get('zdz_authorized');
    var err  = qs.get('zdz_auth_error');
    if (!flag && !err) return;

    qs.delete('zdz_authorized');
    qs.delete('zdz_auth_error');
    var clean = window.location.pathname + (qs.toString() ? ('?' + qs.toString()) : '') + window.location.hash;
    window.history.replaceState({}, '', clean);

    var ran = function () {
      if (err) {
        showToast('FreshBooks authorization failed: ' + err, 8000);
      } else if (flag === 'freshbooks') {
        showToast('✓ FreshBooks authorized successfully');
      } else {
        showToast('✓ ' + flag + ' authorized successfully');
      }
      if (typeof switchView === 'function') switchView('sv-settings');
      if (typeof renderSettings === 'function') renderSettings();
    };
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', ran);
    } else {
      ran();
    }
  } catch (e) { /* no-op */ }
})();


// ── PHASE 4 · H4 (v2.30.0): iOS 26 platform hardening ──────────────────
// 1. Ask for durable storage once per boot: without persist(), iOS can evict
//    IndexedDB/CacheStorage for an installed PWA under pressure (camera-queue
//    photos live there). Silent no-op elsewhere.
// 2. Keyboard-dismiss re-sync: iOS 26 sometimes leaves the layout offset
//    after the keyboard closes (visualViewport shrinks-then-grows but the
//    fixed chrome doesn't repaint — the chat-composer misalignment class).
//    When the visual viewport returns to full height, force one layout tick.
(() => {
  try {
    if (navigator.storage && navigator.storage.persist) {
      navigator.storage.persist().catch(() => {});
    }
  } catch (e) { /* no-op */ }
  try {
    if (window.visualViewport) {
      let t = 0;
      window.visualViewport.addEventListener('resize', () => {
        clearTimeout(t);
        t = setTimeout(() => {
          if (Math.abs(window.innerHeight - window.visualViewport.height) < 2) {
            // Keyboard just closed — nudge a reflow so fixed bars re-anchor.
            document.documentElement.style.minHeight = '100.01%';
            requestAnimationFrame(() => { document.documentElement.style.minHeight = ''; });
          }
        }, 120);
      });
    }
  } catch (e) { /* no-op */ }
})();
