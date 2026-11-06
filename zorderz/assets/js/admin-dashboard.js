(function () {
    'use strict';

    /* ---------------------------------------------------------------
       Zorderz Admin Dashboard
       WordPress Admin  -  User Management & Audit Log
    --------------------------------------------------------------- */

    // ── Constants ──────────────────────────────────────────────────
    /* A role is shown by its shape (ROLE_SHAPES below), in neutral gray: a role has no colour of its own. */

    var ACTION_COLORS = {
        login:          '#15803d',
        logout:         '#64748b',
        failed_login:   '#dc2626',
        role_change:    '#7c3aed',
        permission:     '#2563eb',
        safe_mode:      '#b45309',
        app_launch:     '#0891b2',
        settings:       '#6d28d9',
        create:         '#15803d',
        update:         '#2563eb',
        delete:         '#dc2626'
    };

    var PAGE_SIZE = 20;

    // ── State ──────────────────────────────────────────────────────
    var state = {
        activeTab:       'users',
        users:           [],
        filteredUsers:   [],
        searchQuery:     '',
        roleFilter:      '',
        stats:           { total: 0, admins: 0, sales: 0, operators: 0 },

        // Panel
        panelOpen:       false,
        panelUser:       null,
        panelTab:        'details',
        panelActivity:   [],
        panelPerms:      {},

        // Audit log
        auditEntries:    [],
        auditPage:       1,
        auditTotalPages: 1,
        auditTotal:      0,
        auditSearch:     '',
        auditAction:     '',
        auditUser:       '',
        auditDateFrom:   '',
        auditDateTo:     '',
        auditActions:    [],
        auditUsers:      [],

        loading:         false
    };

    // ── REST API Helper ────────────────────────────────────────────
    function api(endpoint, opts) {
        opts = opts || {};
        var url = zdzAdminData.restUrl + endpoint;
        var headers = {
            'Content-Type': 'application/json',
            'X-WP-Nonce':   zdzAdminData.nonce
        };
        var fetchOpts = Object.assign({ headers: headers }, opts);
        if (fetchOpts.body && typeof fetchOpts.body === 'object' && !(fetchOpts.body instanceof FormData)) {
            fetchOpts.body = JSON.stringify(fetchOpts.body);
        }
        return fetch(url, fetchOpts).then(function (r) {
            var total = r.headers.get('X-WP-Total');
            var totalPages = r.headers.get('X-WP-TotalPages');
            return r.json().then(function (data) {
                if (total !== null) {
                    data._total = parseInt(total, 10);
                    data._totalPages = parseInt(totalPages, 10);
                }
                return data;
            });
        });
    }

    // ── UI Helpers ─────────────────────────────────────────────────

    /** HTML-escape a string */
    function esc(str) {
        if (str === null || str === undefined) return '';
        var el = document.createElement('span');
        el.textContent = String(str);
        return el.innerHTML;
    }

    /** Relative time display */
    function timeAgo(dateStr) {
        if (!dateStr) return 'Never';
        var now = Date.now();
        var then = new Date(dateStr).getTime();
        if (isNaN(then)) return 'Never';
        var diff = Math.floor((now - then) / 1000);
        if (diff < 0) return 'just now';
        if (diff < 60)    return diff + 's ago';
        if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 2592000) return Math.floor(diff / 86400) + 'd ago';
        if (diff < 31536000) return Math.floor(diff / 2592000) + 'mo ago';
        return Math.floor(diff / 31536000) + 'y ago';
    }

    /** Refresh Lucide icons in DOM */
    function refreshIcons() {
        if (typeof lucide !== 'undefined' && lucide.createIcons) {
            lucide.createIcons();
        }
    }

    /** Toast notification */
    function toast(msg, type) {
        type = type || 'success';
        var container = document.getElementById('zdz-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'zdz-toast-container';
            container.style.cssText = 'position:fixed;top:40px;right:20px;z-index:100100;display:flex;flex-direction:column;gap:8px;pointer-events:none;';
            document.body.appendChild(container);
        }
        var t = document.createElement('div');
        var bgColor = type === 'error' ? '#dc2626' : type === 'warning' ? '#b45309' : '#15803d';
        t.style.cssText = 'background:' + bgColor + ';color:#fff;padding:12px 20px;border-radius:8px;font-size:14px;box-shadow:0 4px 12px rgba(0,0,0,.15);pointer-events:auto;opacity:0;transform:translateX(40px);transition:all .3s ease;max-width:360px;';
        t.textContent = msg;
        container.appendChild(t);
        requestAnimationFrame(function () {
            t.style.opacity = '1';
            t.style.transform = 'translateX(0)';
        });
        setTimeout(function () {
            t.style.opacity = '0';
            t.style.transform = 'translateX(40px)';
            setTimeout(function () {
                if (t.parentNode) t.parentNode.removeChild(t);
            }, 300);
        }, 3500);
    }

    /** Get role label */
    function roleLabel(slug) {
        return (zdzAdminData.roles && zdzAdminData.roles[slug]) ? zdzAdminData.roles[slug] : slug;
    }

    /* Person badges: the shape says the privilege (triangle Sales/Field Tech/Shop Foreman, square
       Operator, pentagon Administrator, hexagon Owner, circle WordPress Administrator, plain gray the kiosk),
       the tone is the person's own (ZDZ_Person_Color). Drawn by the kit (ZDZ.ui.badge). */
    var ROLE_SHAPES = { administrator: 'circle', zdz_owner: 'hexagon', zdz_admin: 'pentagon', zdz_operator: 'square',
        zdz_sales: 'triangle', zdz_tech: 'triangle', zdz_mfg: 'triangle', zdz_general: 'kiosk' };
    function shapeOf(user) { return user.shape || ROLE_SHAPES[user.role] || 'triangle'; }
    function kit() { return window.ZDZ && window.ZDZ.ui && window.ZDZ.ui.badge ? window.ZDZ.ui : null; }

    /** The person's badge (their initials, or the first letter of their name before they have initials). */
    function avatarHtml(user, size) {
        var text = user.initials ? String(user.initials).toUpperCase()
            : (user.display_name || user.email || '?').charAt(0).toUpperCase();
        var K = kit();
        if (!K) {
            return '<span style="font-weight:700;">' + esc(text) + '</span>';
        }
        return K.badge(shapeOf(user), { color: user.color || '#E1E5EA', ink: user.color_ink || '#1F2933',
            edge: user.color_edge || '#5E6976', text: text, size: size && size >= 40 ? 'lg' : '',
            label: text + ' · ' + roleLabel(user.role) });
    }

    /** Role badge HTML: the role's shape and its name, in neutral gray (no more color per role). */
    function roleBadgeHtml(role) {
        var K = kit();
        return '<span class="zdz-role-badge">' + (K ? K.badge(ROLE_SHAPES[role] || 'triangle', { size: 'sm' }) : '') +
            '<span>' + esc(roleLabel(role)) + '</span></span>';
    }

    /** Action badge HTML */
    function actionBadgeHtml(action) {
        var color = ACTION_COLORS[action] || '#64748b';
        return '<span style="display:inline-block;padding:2px 10px;border-radius:9999px;font-size:12px;font-weight:500;background:' + color + '18;color:' + color + ';border:1px solid ' + color + '30;">' + esc(action) + '</span>';
    }

    /** Build app pills for user row */
    function appPillsHtml(user) {
        var apps = zdzAdminData.apps || [];
        var allowed = [];
        var denied = [];
        var perms = user.app_permissions || {};

        apps.forEach(function (app) {
            var perm = perms[app.id];
            if (perm === 'deny') {
                denied.push(app);
            } else {
                allowed.push(app);
            }
        });

        var html = '';
        var show = allowed.slice(0, 3);
        show.forEach(function (app) {
            var color = app.color || '#2563eb';
            html += '<span style="display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:9999px;font-size:11px;background:' + color + '14;color:' + color + ';border:1px solid ' + color + '28;white-space:nowrap;">' + esc(app.name) + '</span> ';
        });

        if (denied.length > 0) {
            html += '<span style="display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:9999px;font-size:11px;background:#dc262614;color:#dc2626;border:1px solid #dc262628;white-space:nowrap;">' + denied.length + ' denied</span>';
        }
        return html;
    }

    // ── Loading Indicator ──────────────────────────────────────────
    function showLoading() {
        state.loading = true;
        var el = document.getElementById('zdz-loading');
        if (el) el.style.display = 'flex';
    }

    function hideLoading() {
        state.loading = false;
        var el = document.getElementById('zdz-loading');
        if (el) el.style.display = 'none';
    }

    // ── Main Render ────────────────────────────────────────────────
    function renderShell() {
        var root = document.getElementById('zdz-admin-dashboard');
        if (!root) return;

        root.innerHTML = '' +
            '<style>' +
                '#zdz-admin-dashboard { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1e293b; }' +
                '#zdz-admin-dashboard * { box-sizing: border-box; }' +
                '.zdz-tab-bar { display:flex; gap:0; border-bottom:2px solid #e2e8f0; margin-bottom:24px; }' +
                '.zdz-tab-btn { padding:10px 24px; font-size:14px; font-weight:600; color:#64748b; background:none; border:none; border-bottom:2px solid transparent; margin-bottom:-2px; cursor:pointer; transition:all .2s; }' +
                '.zdz-tab-btn:hover { color:#1e293b; }' +
                '.zdz-tab-btn.active { color:#2563eb; border-bottom-color:#2563eb; }' +
                '.zdz-stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:24px; }' +
                '.zdz-stat-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px; display:flex; align-items:center; gap:16px; }' +
                '.zdz-stat-icon { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; }' +
                '.zdz-stat-value { font-size:28px; font-weight:700; line-height:1; }' +
                '.zdz-stat-label { font-size:13px; color:#64748b; margin-top:2px; }' +
                '.zdz-toolbar { display:flex; align-items:center; gap:12px; margin-bottom:16px; flex-wrap:wrap; }' +
                '.zdz-toolbar input, .zdz-toolbar select { height:38px; padding:0 12px; border:1px solid #d1d5db; border-radius:8px; font-size:13px; background:#fff; outline:none; transition:border-color .2s; }' +
                '.zdz-toolbar input:focus, .zdz-toolbar select:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.1); }' +
                '.zdz-toolbar .zdz-search { width:260px; padding-left:36px; background-image:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'16\' height=\'16\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%2394a3b8\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\'%3E%3Ccircle cx=\'11\' cy=\'11\' r=\'8\'/%3E%3Cline x1=\'21\' y1=\'21\' x2=\'16.65\' y2=\'16.65\'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:12px center; }' +
                '.zdz-btn { height:38px; padding:0 16px; border-radius:8px; font-size:13px; font-weight:500; cursor:pointer; border:1px solid #d1d5db; background:#fff; color:#374151; display:inline-flex; align-items:center; gap:6px; transition:all .15s; }' +
                '.zdz-btn:hover { background:#f8fafc; border-color:#94a3b8; }' +
                '.zdz-btn-primary { background:#2563eb; color:#fff; border-color:#2563eb; }' +
                '.zdz-btn-primary:hover { background:#1d4ed8; }' +
                '.zdz-btn-sm { height:32px; padding:0 12px; font-size:12px; }' +
                '.zdz-table-wrap { background:#fff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; }' +
                '.zdz-table { width:100%; border-collapse:collapse; }' +
                '.zdz-table th { text-align:left; padding:12px 16px; font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.05em; background:#f8fafc; border-bottom:1px solid #e2e8f0; white-space:nowrap; }' +
                '.zdz-table td { padding:12px 16px; font-size:13px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }' +
                '.zdz-table tbody tr { cursor:pointer; transition:background .15s; }' +
                '.zdz-table tbody tr:hover { background:#f8fafc; }' +
                '.zdz-table tbody tr:last-child td { border-bottom:none; }' +
                /* Panel */
                '.zdz-panel-overlay { position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:99999; opacity:0; transition:opacity .3s; pointer-events:none; }' +
                '.zdz-panel-overlay.open { opacity:1; pointer-events:auto; }' +
                /* top:32px accounts for WP admin bar; 46px on mobile admin */
                '.zdz-panel { position:fixed; top:32px; right:0; bottom:0; width:540px; max-width:100vw; background:#fff; z-index:100000; box-shadow:-8px 0 30px rgba(0,0,0,.12); transform:translateX(100%); transition:transform .3s ease; display:flex; flex-direction:column; }' +
                '@media(max-width:782px){ .zdz-panel { top:46px; } }' +
                '.zdz-panel.open { transform:translateX(0); }' +
                '.zdz-panel-header { padding:16px 20px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; gap:12px; flex-shrink:0; }' +
                '.zdz-panel-close { width:36px; height:36px; border-radius:8px; border:1px solid #cbd5e1; background:#f8fafc; color:#475569; cursor:pointer; display:flex; align-items:center; justify-content:center; margin-left:auto; flex-shrink:0; transition:all .15s; }' +
                '.zdz-panel-close:hover { background:#fee2e2; border-color:#fca5a5; color:#dc2626; }' +
                '.zdz-panel-close svg { stroke:currentColor; }' +
                '.zdz-panel-tabs { display:flex; gap:0; border-bottom:1px solid #e2e8f0; flex-shrink:0; }' +
                '.zdz-panel-tab { padding:10px 20px; font-size:13px; font-weight:600; color:#64748b; background:none; border:none; border-bottom:2px solid transparent; margin-bottom:-1px; cursor:pointer; transition:all .2s; }' +
                '.zdz-panel-tab:hover { color:#1e293b; }' +
                '.zdz-panel-tab.active { color:#2563eb; border-bottom-color:#2563eb; }' +
                '.zdz-panel-body { flex:1 1 auto; min-height:0; overflow-y:auto !important; padding:24px; -webkit-overflow-scrolling:touch; }' +
                /* #zdz-panel-content is the render target that sits between .zdz-panel
                   (the fixed, height-constrained flex column) and the header/body/footer.
                   Without these rules it was a plain block with no height and no flex, which
                   BROKE the flex chain — so .zdz-panel-body's flex:1/overflow never applied and
                   the panel could not scroll to its bottom. Make it a column that fills .zdz-panel
                   so the body can take the leftover height and scroll internally. */
                '#zdz-panel-content { display:flex; flex-direction:column; height:100%; min-height:0; overflow:hidden; }' +
                '.zdz-panel-footer { flex-shrink:0; padding:14px 24px calc(14px + env(safe-area-inset-bottom)); border-top:1px solid #e2e8f0; background:#fff; display:flex; justify-content:flex-end; gap:10px; }' +
                '.zdz-panel-footer:empty { display:none; }' +
                '@media(max-width:600px){ .zdz-panel-footer { padding:12px 16px calc(12px + env(safe-area-inset-bottom)); } }' +
                '.zdz-field { margin-bottom:18px; }' +
                '.zdz-field label { display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px; }' +
                '.zdz-field input, .zdz-field select, .zdz-field textarea { width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:13px; outline:none; transition:border-color .2s; font-family:inherit; }' +
                '.zdz-field input:focus, .zdz-field select:focus, .zdz-field textarea:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.1); }' +
                '.zdz-field textarea { min-height:80px; resize:vertical; }' +
                '.zdz-toggle { display:flex; align-items:center; gap:10px; cursor:pointer; }' +
                '.zdz-toggle-track { width:42px; height:24px; border-radius:12px; background:#d1d5db; position:relative; transition:background .2s; flex-shrink:0; }' +
                '.zdz-toggle-track.on { background:#2563eb; }' +
                '.zdz-toggle-knob { width:20px; height:20px; border-radius:50%; background:#fff; position:absolute; top:2px; left:2px; transition:left .2s; box-shadow:0 1px 3px rgba(0,0,0,.15); }' +
                '.zdz-toggle-track.on .zdz-toggle-knob { left:20px; }' +
                /* 3-state perm toggle */
                '.zdz-perm-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }' +
                '.zdz-perm-card { border:1px solid #e2e8f0; border-radius:10px; padding:14px; display:flex; align-items:center; gap:12px; }' +
                '.zdz-perm-card .app-icon { width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0; }' +
                '.zdz-perm-toggle { display:flex; border:1px solid #e2e8f0; border-radius:6px; overflow:hidden; margin-left:auto; flex-shrink:0; }' +
                '.zdz-perm-toggle button { padding:4px 10px; font-size:11px; font-weight:600; border:none; cursor:pointer; background:#fff; color:#94a3b8; transition:all .15s; }' +
                '.zdz-perm-toggle button.allow-active { background:#15803d; color:#fff; }' +
                '.zdz-perm-toggle button.default-active { background:#64748b; color:#fff; }' +
                '.zdz-perm-toggle button.deny-active { background:#dc2626; color:#fff; }' +
                /* Activity timeline */
                '.zdz-timeline { position:relative; padding-left:28px; }' +
                '.zdz-timeline::before { content:""; position:absolute; left:10px; top:4px; bottom:4px; width:2px; background:#e2e8f0; }' +
                '.zdz-timeline-item { position:relative; padding-bottom:20px; }' +
                '.zdz-timeline-dot { position:absolute; left:-24px; top:4px; width:14px; height:14px; border-radius:50%; border:2px solid #e2e8f0; background:#fff; }' +
                '.zdz-timeline-item .zdz-tl-time { font-size:12px; color:#94a3b8; }' +
                '.zdz-timeline-item .zdz-tl-desc { font-size:13px; color:#1e293b; margin-top:2px; }' +
                '.zdz-timeline-item .zdz-tl-meta { font-size:12px; color:#94a3b8; margin-top:4px; display:flex; gap:12px; }' +
                /* Pagination */
                '.zdz-pagination { display:flex; align-items:center; justify-content:space-between; padding:16px; border-top:1px solid #e2e8f0; }' +
                '.zdz-pagination-info { font-size:13px; color:#64748b; }' +
                '.zdz-pagination-btns { display:flex; gap:4px; }' +
                '.zdz-pagination-btns button { min-width:36px; height:36px; padding:0 10px; border-radius:8px; border:1px solid #e2e8f0; background:#fff; font-size:13px; cursor:pointer; transition:all .15s; }' +
                '.zdz-pagination-btns button:hover:not(:disabled) { background:#f1f5f9; }' +
                '.zdz-pagination-btns button:disabled { opacity:.4; cursor:not-allowed; }' +
                '.zdz-pagination-btns button.active { background:#2563eb; color:#fff; border-color:#2563eb; }' +
                /* Loading */
                '#zdz-loading { position:absolute; inset:0; background:rgba(255,255,255,.7); display:none; align-items:center; justify-content:center; z-index:10; border-radius:12px; }' +
                '.zdz-spinner { width:36px; height:36px; border:3px solid #e2e8f0; border-top-color:#2563eb; border-radius:50%; animation:zdz-spin .7s linear infinite; }' +
                '@keyframes zdz-spin { to { transform:rotate(360deg); } }' +
                /* Safe mode badge */
                '.zdz-safe-badge { display:inline-block; padding:2px 8px; border-radius:9999px; font-size:11px; font-weight:600; background:#dc262614; color:#dc2626; border:1px solid #dc262628; }' +
                /* The Usage tab */
                '.zdz-usage-note { font-size:13px; color:#475569; margin:0 0 12px; max-width:960px; }' +
                '.zdz-usage-note.is-warn { color:#92400e; background:#fffbeb; border:1px solid #f59e0b; border-radius:8px; padding:8px 12px; }' +
                '.zdz-usage-h { font-size:15px; font-weight:700; margin:24px 0 8px; color:#1e293b; }' +
                '.zdz-usage-block { margin-bottom:16px; overflow-x:auto; position:relative; }' +   /* relative: wp-admin's absolute .screen-reader-text labels stay inside the scroll box */
                '.zdz-usage-table tbody tr { cursor:default; }' +
                '.zdz-usage-table tbody tr[data-usage-app], .zdz-usage-table tbody tr[data-usage-user] { cursor:pointer; }' +
                '.zdz-usage-table tbody th { text-align:left; padding:12px 16px; font-size:13px; font-weight:600; color:#1e293b; text-transform:none; letter-spacing:0; background:none; border-bottom:1px solid #f1f5f9; }' +
                '.zdz-usage-empty { text-align:center; color:#64748b; padding:24px; }' +
                '.zdz-usage-grid-wrap { padding:12px 16px; }' +
                '.zdz-usage-grid { border-collapse:separate; border-spacing:2px; font-size:11px; color:#475569; }' +
                '.zdz-usage-grid caption { text-align:left; font-size:13px; font-weight:600; color:#1e293b; padding-bottom:8px; }' +
                '.zdz-usage-grid th { font-weight:500; padding:0 4px; text-align:left; white-space:nowrap; }' +
                '.zdz-usage-grid td { width:22px; height:18px; border-radius:3px; background:#f1f5f9; }' +
                '.zdz-usage-grid td.lv1, .zdz-usage-legend .lv1 { background:#bfdbfe; } .zdz-usage-grid td.lv2, .zdz-usage-legend .lv2 { background:#60a5fa; }' +
                '.zdz-usage-grid td.lv3, .zdz-usage-legend .lv3 { background:#2563eb; } .zdz-usage-grid td.lv4, .zdz-usage-legend .lv4 { background:#1e3a8a; }' +
                '.zdz-usage-legend { font-size:11px; color:#64748b; margin-top:6px; display:flex; align-items:center; gap:3px; }' +
                '.zdz-usage-legend i { display:inline-block; width:14px; height:12px; border-radius:2px; }' +
                '.zdz-usage-unused { margin:0 0 16px; padding:0; list-style:none; display:flex; flex-wrap:wrap; gap:8px; }' +
                '.zdz-usage-unused li { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:6px 10px; font-size:13px; }' +
                '.zdz-usage-unused li span { color:#64748b; }' +
                '.zdz-usage-stream { margin:16px 0; } .zdz-usage-stream summary { cursor:pointer; font-weight:600; font-size:13px; padding:6px 0; }' +
                /* Responsive */
                '@media(max-width:900px){ .zdz-stats-row{grid-template-columns:repeat(2,1fr);} .zdz-perm-grid{grid-template-columns:1fr;} .zdz-panel{width:100vw;} }' +
                '@media(max-width:600px){ .zdz-stats-row{grid-template-columns:1fr;} .zdz-toolbar{flex-direction:column;align-items:stretch;} .zdz-toolbar .zdz-search{width:100%;} .zdz-panel-header{padding:12px 16px;gap:10px;} .zdz-panel-body{padding:16px;} .zdz-perm-card{padding:10px;gap:8px;} .zdz-perm-toggle button{padding:3px 7px;font-size:10px;} }' +
            '</style>' +
            '<div style="position:relative;">' +
                '<div id="zdz-loading"><div class="zdz-spinner"></div></div>' +
                '<!-- Tab Bar -->' +
                '<div class="zdz-tab-bar">' +
                    '<button class="zdz-tab-btn active" data-tab="users" onclick="window.zdzSwitchTab(\'users\')"><i data-lucide="users" style="width:15px;height:15px;margin-right:4px;vertical-align:-2px;"></i> Users</button>' +
                    '<button class="zdz-tab-btn" data-tab="audit" onclick="window.zdzSwitchTab(\'audit\')"><i data-lucide="scroll-text" style="width:15px;height:15px;margin-right:4px;vertical-align:-2px;"></i> Audit Log</button>' +
                    '<button class="zdz-tab-btn" data-tab="usage" onclick="window.zdzSwitchTab(\'usage\')"><i data-lucide="bar-chart-3" style="width:15px;height:15px;margin-right:4px;vertical-align:-2px;"></i> Usage</button>' +
                '</div>' +
                '<div id="zdz-tab-users"></div>' +
                '<div id="zdz-tab-audit" style="display:none;"></div>' +
                '<div id="zdz-tab-usage" style="display:none;"></div>' +
            '</div>' +
            '<!-- Panel Overlay -->' +
            '<div class="zdz-panel-overlay" id="zdz-panel-overlay" onclick="window.zdzClosePanel()"></div>' +
            '<div class="zdz-panel" id="zdz-panel">' +
                '<div id="zdz-panel-content"></div>' +
            '</div>';

        refreshIcons();
    }

    // ── Tab Switching ──────────────────────────────────────────────
    window.zdzSwitchTab = function (tab) {
        state.activeTab = tab;
        var btns = document.querySelectorAll('.zdz-tab-btn');
        btns.forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-tab') === tab);
        });
        document.getElementById('zdz-tab-users').style.display = (tab === 'users') ? '' : 'none';
        document.getElementById('zdz-tab-audit').style.display = (tab === 'audit') ? '' : 'none';
        document.getElementById('zdz-tab-usage').style.display = (tab === 'usage') ? '' : 'none';

        if (tab === 'users') {
            loadUsers();
        } else if (tab === 'usage') {
            loadUsage();
        } else {
            loadAuditFilters();
            loadAuditLog();
        }
    };

    // ── Users Tab ──────────────────────────────────────────────────
    function computeStats() {
        var u = state.users;
        state.stats.total = u.length;
        state.stats.admins = u.filter(function (x) {
            return x.role === 'administrator' || x.role === 'zdz_admin' || x.role === 'zdz_owner';
        }).length;
        state.stats.sales = u.filter(function (x) { return x.role === 'zdz_sales'; }).length;
        state.stats.operators = u.filter(function (x) { return x.role === 'zdz_operator'; }).length;
    }

    function filterUsers() {
        var q = state.searchQuery.toLowerCase().trim();
        var r = state.roleFilter;
        state.filteredUsers = state.users.filter(function (u) {
            if (r && u.role !== r) return false;
            if (q) {
                var hay = ((u.display_name || '') + ' ' + (u.email || '') + ' ' + (u.initials || '')).toLowerCase();
                if (hay.indexOf(q) === -1) return false;
            }
            return true;
        });
    }

    function renderUsersTab() {
        var el = document.getElementById('zdz-tab-users');
        if (!el) return;

        computeStats();
        filterUsers();
        var s = state.stats;

        var html = '' +
            '<!-- Stats Row -->' +
            '<div class="zdz-stats-row">' +
                statCardHtml('users', '#2563eb', s.total, 'Total Users') +
                statCardHtml('shield', '#7c3aed', s.admins, 'Admins') +
                statCardHtml('badge-dollar-sign', '#2563eb', s.sales, 'Salespersons') +
                statCardHtml('monitor', '#15803d', s.operators, 'Operators') +
            '</div>' +
            '<!-- Toolbar -->' +
            '<div class="zdz-toolbar">' +
                '<input type="text" class="zdz-search" id="zdz-user-search" placeholder="Search users..." value="' + esc(state.searchQuery) + '">' +
                '<select id="zdz-role-filter">' +
                    '<option value="">All Roles</option>' +
                    roleOptionsHtml(state.roleFilter) +
                '</select>' +
                '<button class="zdz-btn" onclick="window.zdzRefreshUsers()"><i data-lucide="refresh-cw" style="width:14px;height:14px;"></i> Refresh</button>' +
            '</div>' +
            '<!-- User Table -->' +
            '<div class="zdz-table-wrap">' +
                '<table class="zdz-table">' +
                    '<thead><tr>' +
                        '<th style="width:48px;"></th>' +
                        '<th>User</th>' +
                        '<th>Role</th>' +
                        '<th>Initials</th>' +
                        '<th>Apps</th>' +
                        '<th>Safe Mode</th>' +
                        '<th>Last Login</th>' +
                        '<th style="width:80px;">Actions</th>' +
                    '</tr></thead>' +
                    '<tbody id="zdz-user-tbody">';

        if (state.filteredUsers.length === 0) {
            html += '<tr><td colspan="8" style="text-align:center;padding:40px;color:#94a3b8;">No users found</td></tr>';
        } else {
            state.filteredUsers.forEach(function (u) {
                html += userRowHtml(u);
            });
        }

        html += '</tbody></table></div>';

        el.innerHTML = html;
        bindUserToolbarEvents();
        refreshIcons();
    }

    function statCardHtml(icon, color, value, label) {
        return '<div class="zdz-stat-card">' +
            '<div class="zdz-stat-icon" style="background:' + color + '12;color:' + color + ';"><i data-lucide="' + icon + '" style="width:24px;height:24px;"></i></div>' +
            '<div><div class="zdz-stat-value">' + esc(value) + '</div><div class="zdz-stat-label">' + esc(label) + '</div></div>' +
        '</div>';
    }

    function roleOptionsHtml(selected) {
        var html = '';
        var roles = zdzAdminData.roles || {};
        Object.keys(roles).forEach(function (slug) {
            html += '<option value="' + esc(slug) + '"' + (selected === slug ? ' selected' : '') + '>' + esc(roles[slug]) + '</option>';
        });
        return html;
    }

    function userRowHtml(u) {
        var safeModeHtml = u.safe_mode ? '<span class="zdz-safe-badge">ON</span>' : '<span style="color:#94a3b8;font-size:12px;">Off</span>';
        return '<tr data-user-id="' + esc(u.id) + '" onclick="window.zdzOpenPanel(' + u.id + ')">' +
            '<td>' + avatarHtml(u) + '</td>' +
            '<td><div style="font-weight:600;font-size:13px;">' + esc(u.display_name) + '</div><div style="font-size:12px;color:#64748b;">' + esc(u.email) + '</div></td>' +
            '<td>' + roleBadgeHtml(u.role) + '</td>' +
            '<td>' + (u.initials
                ? '<span style="font-weight:600;font-family:monospace;font-size:14px;">' + esc(u.initials) + '</span>'
                : '<span style="color:#94a3b8;">--</span>') + '</td>' +
            '<td><div style="display:flex;flex-wrap:wrap;gap:4px;max-width:260px;">' + appPillsHtml(u) + '</div></td>' +
            '<td>' + safeModeHtml + '</td>' +
            '<td style="white-space:nowrap;font-size:12px;color:#64748b;">' + esc(timeAgo(u.last_login)) + '</td>' +
            '<td onclick="event.stopPropagation()">' +
                '<div style="display:flex;gap:4px;">' +
                    '<button class="zdz-btn zdz-btn-sm" onclick="window.zdzOpenPanel(' + u.id + ')" title="Edit"><i data-lucide="pencil" style="width:14px;height:14px;"></i></button>' +
                    '<button class="zdz-btn zdz-btn-sm" onclick="window.zdzOpenPanel(' + u.id + ',\'activity\')" title="Activity"><i data-lucide="activity" style="width:14px;height:14px;"></i></button>' +
                '</div>' +
            '</td>' +
        '</tr>';
    }

    function bindUserToolbarEvents() {
        var searchEl = document.getElementById('zdz-user-search');
        if (searchEl) {
            searchEl.addEventListener('input', function () {
                state.searchQuery = this.value;
                filterUsers();
                renderUserTableBody();
            });
        }
        var roleEl = document.getElementById('zdz-role-filter');
        if (roleEl) {
            roleEl.addEventListener('change', function () {
                state.roleFilter = this.value;
                filterUsers();
                renderUserTableBody();
            });
        }
    }

    function renderUserTableBody() {
        var tbody = document.getElementById('zdz-user-tbody');
        if (!tbody) return;
        if (state.filteredUsers.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:40px;color:#94a3b8;">No users found</td></tr>';
        } else {
            var html = '';
            state.filteredUsers.forEach(function (u) {
                html += userRowHtml(u);
            });
            tbody.innerHTML = html;
        }
        refreshIcons();
    }

    function loadUsers() {
        showLoading();
        api('admin/users').then(function (data) {
            if (data && data.success && Array.isArray(data.data)) {
                state.users = data.data;
            } else if (Array.isArray(data)) {
                state.users = data;
            } else if (data && Array.isArray(data.users)) {
                state.users = data.users;
            } else {
                state.users = [];
            }
            renderUsersTab();
            hideLoading();
        }).catch(function (err) {
            console.error('Failed to load users', err);
            toast('Failed to load users', 'error');
            hideLoading();
        });
    }

    window.zdzRefreshUsers = function () {
        loadUsers();
    };

    // ── Slide-In Panel ─────────────────────────────────────────────
    function findUser(id) {
        return state.users.find(function (u) { return u.id === id; }) || null;
    }

    window.zdzOpenPanel = function (userId, tab) {
        var user = findUser(userId);
        if (!user) return;
        state.panelUser = user;
        state.panelTab = tab || 'details';
        state.panelOpen = true;
        state.panelPerms = Object.assign({}, user.app_permissions || {});

        document.getElementById('zdz-panel-overlay').classList.add('open');
        document.getElementById('zdz-panel').classList.add('open');
        document.body.style.overflow = 'hidden';

        renderPanel();

        if (state.panelTab === 'activity') {
            loadUserActivity(userId);
        }
    };

    window.zdzClosePanel = function () {
        state.panelOpen = false;
        state.panelUser = null;
        document.getElementById('zdz-panel-overlay').classList.remove('open');
        document.getElementById('zdz-panel').classList.remove('open');
        document.body.style.overflow = '';
    };

    window.zdzSwitchPanelTab = function (tab) {
        state.panelTab = tab;
        renderPanel();
        if (tab === 'activity' && state.panelUser) {
            loadUserActivity(state.panelUser.id);
        }
    };

    function renderPanel() {
        var container = document.getElementById('zdz-panel-content');
        var user = state.panelUser;
        if (!container || !user) return;

        var html = '' +
            '<div class="zdz-panel-header">' +
                avatarHtml(user, 44) +
                '<div>' +
                    '<div style="font-weight:700;font-size:16px;">' + esc(user.display_name) + '</div>' +
                    '<div style="font-size:13px;color:#64748b;">' + esc(user.email) + '</div>' +
                '</div>' +
                '<button class="zdz-panel-close" onclick="window.zdzClosePanel()"><i data-lucide="x" style="width:18px;height:18px;"></i></button>' +
            '</div>' +
            '<div class="zdz-panel-tabs">' +
                '<button class="zdz-panel-tab' + (state.panelTab === 'details' ? ' active' : '') + '" onclick="window.zdzSwitchPanelTab(\'details\')">Details</button>' +
                '<button class="zdz-panel-tab' + (state.panelTab === 'permissions' ? ' active' : '') + '" onclick="window.zdzSwitchPanelTab(\'permissions\')">Permissions</button>' +
                '<button class="zdz-panel-tab' + (state.panelTab === 'activity' ? ' active' : '') + '" onclick="window.zdzSwitchPanelTab(\'activity\')">Activity</button>' +
            '</div>' +
            '<div class="zdz-panel-body">';

        if (state.panelTab === 'details') {
            html += renderPanelDetails(user);
        } else if (state.panelTab === 'permissions') {
            html += renderPanelPermissions(user);
        } else if (state.panelTab === 'activity') {
            html += '<div id="zdz-panel-activity"><div style="text-align:center;padding:40px;color:#94a3b8;">Loading activity...</div></div>';
        }

        html += '</div>'; // close .zdz-panel-body

        // Save lives in a pinned footer (outside the now-correctly-scrolling
        // body) so it's always visible. Details/Permissions get their Save here;
        // Activity gets none (empty footer auto-hides via :empty).
        html += '<div class="zdz-panel-footer">';
        if (state.panelTab === 'details') {
            html += '<button class="zdz-btn zdz-btn-primary" onclick="window.zdzSaveUserDetails()"><i data-lucide="save" style="width:14px;height:14px;"></i> Save Details</button>';
        } else if (state.panelTab === 'permissions') {
            html += '<button class="zdz-btn zdz-btn-primary" onclick="window.zdzSavePermissions()"><i data-lucide="save" style="width:14px;height:14px;"></i> Save Permissions</button>';
        }
        html += '</div>';

        container.innerHTML = html;
        refreshIcons();
    }

    // ── Panel: Details Sub-Tab ─────────────────────────────────────
    function renderPanelDetails(user) {
        var safeChecked = user.safe_mode ? ' checked' : '';
        return '' +
            '<div class="zdz-field">' +
                '<label>Display Name</label>' +
                '<input type="text" id="zdz-panel-name" value="' + esc(user.display_name || '') + '">' +
            '</div>' +
            '<div class="zdz-field">' +
                '<label>Role</label>' +
                '<select id="zdz-panel-role">' + roleOptionsHtml(user.role) + '</select>' +
            '</div>' +
            '<div class="zdz-field">' +
                '<label>Initials <span style="font-weight:400;color:#94a3b8;">(max 5 characters)</span></label>' +
                '<input type="text" id="zdz-panel-initials" value="' + esc(user.initials || '') + '" maxlength="5">' +
            '</div>' +
            personColorFieldHtml(user) +
            '<div class="zdz-field">' +
                '<label>Notes</label>' +
                '<textarea id="zdz-panel-notes">' + esc(user.notes || '') + '</textarea>' +
            '</div>' +
            '<div class="zdz-field">' +
                '<label>Safe Mode</label>' +
                '<label class="zdz-toggle" id="zdz-safe-mode-toggle">' +
                    '<div class="zdz-toggle-track' + (user.safe_mode ? ' on' : '') + '" onclick="window.zdzToggleSafeMode()">' +
                        '<div class="zdz-toggle-knob"></div>' +
                    '</div>' +
                    '<span>' + (user.safe_mode ? 'Enabled' : 'Disabled') + '</span>' +
                    '<input type="checkbox" id="zdz-panel-safemode" style="display:none;"' + safeChecked + '>' +
                '</label>' +
            '</div>';
    }

    /* One tone per person, shown with their initials everywhere (photos, jobs, charts). "Automatic" hands out the
       next free tone; picking one keeps it. Each tone is shown as this person's own badge shape, so the choice is
       seen as it will look. Names come from the server (ZDZ_Person_Color::names()). */
    function personColorFieldHtml(user) {
        var tones = user.color_tones || [];
        if (!tones.length || shapeOf(user) === 'kiosk') return '';
        var K = kit();
        var cur = user.color_chosen ? String(user.color || '').toUpperCase() : '';
        var ini = user.initials ? String(user.initials).toUpperCase() : '';
        var preview = function (t) {
            return K ? K.badge(shapeOf(user), { color: t.color, ink: t.ink, edge: t.edge, text: ini, label: t.name })
                : '<span class="zdz-swatch" style="background:' + esc(t.color) + '"></span>';
        };
        var opts = '<label class="zdz-swatch-opt"><input type="radio" name="zdz-panel-color" value=""' + (cur === '' ? ' checked' : '') + '> Automatic' +
            (user.color && !user.color_chosen ? ' ' + avatarHtml(user) : '') + '</label>';
        tones.forEach(function (t) {
            var c = String(t.color).toUpperCase(), nm = t.name || c;   // a spoken name, never a hex
            opts += '<label class="zdz-swatch-opt" title="' + esc(nm) + '"><input type="radio" name="zdz-panel-color" aria-label="' + esc(nm) + '" value="' + esc(c) + '"' +
                (cur === c ? ' checked' : '') + '> ' + preview(t) + '</label>';
        });
        return '<div class="zdz-field"><label>Badge tone <span style="font-weight:400;color:#94a3b8;">(the shape comes from the role' +
            (ini ? '; shown with ' + esc(ini) + ' on photos, jobs and charts' : '') + ')</span></label>' +
            '<div class="zdz-swatches">' + opts + '</div></div>';
    }

    window.zdzToggleSafeMode = function () {
        var cb = document.getElementById('zdz-panel-safemode');
        if (!cb) return;
        cb.checked = !cb.checked;
        var track = document.querySelector('#zdz-safe-mode-toggle .zdz-toggle-track');
        var label = document.querySelector('#zdz-safe-mode-toggle span');
        if (track) track.classList.toggle('on', cb.checked);
        if (label) label.textContent = cb.checked ? 'Enabled' : 'Disabled';
    };

    window.zdzSaveUserDetails = function () {
        var user = state.panelUser;
        if (!user) return;

        var nameEl = document.getElementById('zdz-panel-name');
        var roleEl = document.getElementById('zdz-panel-role');
        var initialsEl = document.getElementById('zdz-panel-initials');
        var notesEl = document.getElementById('zdz-panel-notes');
        var safeModeEl = document.getElementById('zdz-panel-safemode');
        var colorEl = document.querySelector('input[name="zdz-panel-color"]:checked');

        var payload = {
            display_name: nameEl ? nameEl.value.trim() : user.display_name,
            role:         roleEl ? roleEl.value : user.role,
            initials:     initialsEl ? initialsEl.value.trim().substring(0, 5) : user.initials,
            notes:        notesEl ? notesEl.value : user.notes,
            safe_mode:    safeModeEl ? safeModeEl.checked : user.safe_mode
        };
        if (colorEl) { payload.color = colorEl.value; }   // '' = automatic

        showLoading();
        api('admin/users/' + user.id, {
            method: 'POST',
            body: payload
        }).then(function (resp) {
            hideLoading();
            if (resp && resp.success && resp.data) {
                Object.assign(user, resp.data);
                toast('User details saved successfully');
                renderUsersTab();
                renderPanel();
            } else if (resp && resp.id) {
                // Update local state
                Object.assign(user, resp);
                toast('User details saved successfully');
                renderUsersTab();
                renderPanel();
            } else if (resp && resp.message) {
                toast(resp.message, 'error');
            } else {
                toast('User details saved');
                loadUsers();
            }
        }).catch(function (err) {
            hideLoading();
            console.error('Save user details failed', err);
            toast('Failed to save user details', 'error');
        });
    };

    // ── Panel: Permissions Sub-Tab ─────────────────────────────────
    function renderPanelPermissions(user) {
        var apps = zdzAdminData.apps || [];
        var isAdmin = (user.role === 'administrator' || user.role === 'zdz_owner' || user.role === 'zdz_admin');
        var html = '';

        if (isAdmin) {
            html += '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;margin-bottom:18px;font-size:13px;color:#1e40af;display:flex;align-items:center;gap:8px;">' +
                '<i data-lucide="info" style="width:16px;height:16px;flex-shrink:0;"></i>' +
                '<span>Admin and Owner roles have access to all apps by default. Overrides below will still be applied.</span>' +
            '</div>';
        }

        html += '<div class="zdz-perm-grid">';
        apps.forEach(function (app) {
            var perm = state.panelPerms[app.id] || 'default';
            var color = app.color || '#2563eb';
            html += '' +
                '<div class="zdz-perm-card">' +
                    '<div class="app-icon" style="background:' + color + '14;color:' + color + ';">' +
                        '<i data-lucide="' + esc(app.icon || 'box') + '" style="width:18px;height:18px;"></i>' +
                    '</div>' +
                    '<div style="flex:1;min-width:0;">' +
                        '<div style="font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(app.name) + '</div>' +
                        '<div style="font-size:11px;color:#94a3b8;">' + esc(app.category || '') + '</div>' +
                    '</div>' +
                    '<div class="zdz-perm-toggle" data-app-id="' + esc(app.id) + '">' +
                        '<button class="' + (perm === 'allow' ? 'allow-active' : '') + '" onclick="window.zdzSetPerm(\'' + esc(app.id) + '\',\'allow\')">Allow</button>' +
                        '<button class="' + (perm === 'default' ? 'default-active' : '') + '" onclick="window.zdzSetPerm(\'' + esc(app.id) + '\',\'default\')">Default</button>' +
                        '<button class="' + (perm === 'deny' ? 'deny-active' : '') + '" onclick="window.zdzSetPerm(\'' + esc(app.id) + '\',\'deny\')">Deny</button>' +
                    '</div>' +
                '</div>';
        });
        html += '</div>';

        return html;
    }

    window.zdzSetPerm = function (appId, value) {
        state.panelPerms[appId] = value;
        var container = document.querySelector('.zdz-perm-toggle[data-app-id="' + appId + '"]');
        if (!container) return;
        var btns = container.querySelectorAll('button');
        btns.forEach(function (btn) {
            btn.className = '';
        });
        var vals = ['allow', 'default', 'deny'];
        btns.forEach(function (btn, i) {
            if (vals[i] === value) {
                btn.classList.add(value + '-active');
            }
        });
    };

    window.zdzSavePermissions = function () {
        var user = state.panelUser;
        if (!user) return;

        showLoading();
        api('admin/users/' + user.id + '/permissions', {
            method: 'POST',
            body: { permissions: state.panelPerms }
        }).then(function (resp) {
            hideLoading();
            if (resp && !resp.code) {
                user.app_permissions = Object.assign({}, state.panelPerms);
                toast('Permissions saved successfully');
                renderUsersTab();
            } else if (resp && resp.message) {
                toast(resp.message, 'error');
            } else {
                toast('Permissions saved');
                loadUsers();
            }
        }).catch(function (err) {
            hideLoading();
            console.error('Save permissions failed', err);
            toast('Failed to save permissions', 'error');
        });
    };

    // ── Panel: Activity Sub-Tab ────────────────────────────────────
    function loadUserActivity(userId) {
        api('admin/audit-log?user_id=' + userId + '&per_page=50').then(function (data) {
            var entries;
            if (data && data.success && data.data) {
                entries = Array.isArray(data.data.items) ? data.data.items : (Array.isArray(data.data) ? data.data : []);
            } else if (Array.isArray(data)) {
                entries = data;
            } else if (data && Array.isArray(data.entries)) {
                entries = data.entries;
            } else {
                entries = [];
            }
            state.panelActivity = entries;
            renderPanelActivity();
        }).catch(function (err) {
            console.error('Failed to load user activity', err);
            var el = document.getElementById('zdz-panel-activity');
            if (el) {
                el.innerHTML = '<div style="text-align:center;padding:40px;color:#dc2626;">Failed to load activity</div>';
            }
        });
    }

    function renderPanelActivity() {
        var el = document.getElementById('zdz-panel-activity');
        if (!el) return;

        var entries = state.panelActivity;
        if (entries.length === 0) {
            el.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;"><i data-lucide="clock" style="width:32px;height:32px;margin-bottom:8px;"></i><br>No activity recorded</div>';
            refreshIcons();
            return;
        }

        var html = '<div class="zdz-timeline">';
        entries.forEach(function (entry) {
            var actionColor = ACTION_COLORS[entry.action] || '#64748b';
            html += '' +
                '<div class="zdz-timeline-item">' +
                    '<div class="zdz-timeline-dot" style="border-color:' + actionColor + ';background:' + actionColor + '20;"></div>' +
                    '<div class="zdz-tl-time">' + esc(timeAgo(entry.timestamp || entry.created_at)) + '</div>' +
                    '<div class="zdz-tl-desc">' + actionBadgeHtml(entry.action) + ' ' + esc(entry.detail || entry.description || '') + '</div>' +
                    '<div class="zdz-tl-meta">';
            if (entry.app) {
                html += '<span><i data-lucide="layout-grid" style="width:12px;height:12px;vertical-align:-2px;"></i> ' + esc(entry.app) + '</span>';
            }
            if (entry.ip || entry.ip_address) {
                html += '<span><i data-lucide="globe" style="width:12px;height:12px;vertical-align:-2px;"></i> ' + esc(entry.ip || entry.ip_address) + '</span>';
            }
            html += '</div></div>';
        });
        html += '</div>';

        el.innerHTML = html;
        refreshIcons();
    }

    // ── Audit Log Tab ──────────────────────────────────────────────
    function loadAuditFilters() {
        if (state.auditActions.length === 0) {
            api('admin/audit-log/actions').then(function (data) {
                if (data && data.success && Array.isArray(data.data)) {
                    state.auditActions = data.data;
                } else {
                    state.auditActions = Array.isArray(data) ? data : [];
                }
                renderAuditActionFilter();
            }).catch(function () {
                state.auditActions = [];
            });
        }
        if (state.auditUsers.length === 0) {
            api('admin/users?fields=id,display_name').then(function (data) {
                var users;
                if (data && data.success && Array.isArray(data.data)) {
                    users = data.data;
                } else {
                    users = Array.isArray(data) ? data : (data && Array.isArray(data.users) ? data.users : []);
                }
                state.auditUsers = users;
                renderAuditUserFilter();
            }).catch(function () {
                state.auditUsers = [];
            });
        }
    }

    function renderAuditActionFilter() {
        var sel = document.getElementById('zdz-audit-action');
        if (!sel) return;
        var html = '<option value="">All Actions</option>';
        state.auditActions.forEach(function (a) {
            var val = typeof a === 'string' ? a : a.slug || a.value || a.name;
            var label = typeof a === 'string' ? a : a.label || a.name || a.slug;
            html += '<option value="' + esc(val) + '">' + esc(label) + '</option>';
        });
        sel.innerHTML = html;
    }

    function renderAuditUserFilter() {
        var sel = document.getElementById('zdz-audit-user');
        if (!sel) return;
        var html = '<option value="">All Users</option>';
        state.auditUsers.forEach(function (u) {
            html += '<option value="' + esc(u.id) + '">' + esc(u.display_name || u.email || 'User #' + u.id) + '</option>';
        });
        sel.innerHTML = html;
    }

    function renderAuditTab() {
        var el = document.getElementById('zdz-tab-audit');
        if (!el) return;

        var html = '' +
            '<div class="zdz-toolbar">' +
                '<input type="text" class="zdz-search" id="zdz-audit-search" placeholder="Search audit log..." value="' + esc(state.auditSearch) + '">' +
                '<select id="zdz-audit-action"><option value="">All Actions</option></select>' +
                '<select id="zdz-audit-user"><option value="">All Users</option></select>' +
                '<input type="date" id="zdz-audit-date-from" value="' + esc(state.auditDateFrom) + '" title="Date From" style="width:150px;">' +
                '<input type="date" id="zdz-audit-date-to" value="' + esc(state.auditDateTo) + '" title="Date To" style="width:150px;">' +
                '<button class="zdz-btn" onclick="window.zdzRefreshAudit()"><i data-lucide="refresh-cw" style="width:14px;height:14px;"></i> Refresh</button>' +
            '</div>' +
            '<div class="zdz-table-wrap">' +
                '<table class="zdz-table">' +
                    '<thead><tr>' +
                        '<th>Timestamp</th>' +
                        '<th>User</th>' +
                        '<th>Action</th>' +
                        '<th>Detail</th>' +
                        '<th>App</th>' +
                        '<th>IP Address</th>' +
                    '</tr></thead>' +
                    '<tbody id="zdz-audit-tbody">' +
                        '<tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;">Loading...</td></tr>' +
                    '</tbody>' +
                '</table>' +
                '<div id="zdz-audit-pagination"></div>' +
            '</div>';

        el.innerHTML = html;
        bindAuditToolbarEvents();
        renderAuditActionFilter();
        renderAuditUserFilter();
        refreshIcons();
    }

    function bindAuditToolbarEvents() {
        var debounceTimer;

        var searchEl = document.getElementById('zdz-audit-search');
        if (searchEl) {
            searchEl.addEventListener('input', function () {
                state.auditSearch = this.value;
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function () {
                    state.auditPage = 1;
                    loadAuditLog();
                }, 350);
            });
        }

        var actionEl = document.getElementById('zdz-audit-action');
        if (actionEl) {
            actionEl.addEventListener('change', function () {
                state.auditAction = this.value;
                state.auditPage = 1;
                loadAuditLog();
            });
        }

        var userEl = document.getElementById('zdz-audit-user');
        if (userEl) {
            userEl.addEventListener('change', function () {
                state.auditUser = this.value;
                state.auditPage = 1;
                loadAuditLog();
            });
        }

        var fromEl = document.getElementById('zdz-audit-date-from');
        if (fromEl) {
            fromEl.addEventListener('change', function () {
                state.auditDateFrom = this.value;
                state.auditPage = 1;
                loadAuditLog();
            });
        }

        var toEl = document.getElementById('zdz-audit-date-to');
        if (toEl) {
            toEl.addEventListener('change', function () {
                state.auditDateTo = this.value;
                state.auditPage = 1;
                loadAuditLog();
            });
        }
    }

    function loadAuditLog() {
        var params = ['per_page=' + PAGE_SIZE, 'page=' + state.auditPage];
        if (state.auditSearch) params.push('search=' + encodeURIComponent(state.auditSearch));
        if (state.auditAction) params.push('action=' + encodeURIComponent(state.auditAction));
        if (state.auditUser)   params.push('user_id=' + encodeURIComponent(state.auditUser));
        if (state.auditDateFrom) params.push('date_from=' + encodeURIComponent(state.auditDateFrom));
        if (state.auditDateTo)   params.push('date_to=' + encodeURIComponent(state.auditDateTo));

        showLoading();
        api('admin/audit-log?' + params.join('&')).then(function (data) {
            hideLoading();
            var entries;
            if (data && data.success && data.data) {
                // Server returns { success, data: { items, total, pages } }
                var d = data.data;
                entries = Array.isArray(d.items) ? d.items : (Array.isArray(d) ? d : []);
                state.auditTotal = d.total || entries.length;
                state.auditTotalPages = d.pages || Math.ceil(state.auditTotal / PAGE_SIZE) || 1;
            } else if (Array.isArray(data)) {
                entries = data;
                state.auditTotal = data._total || data.length;
                state.auditTotalPages = data._totalPages || 1;
            } else if (data && Array.isArray(data.entries)) {
                entries = data.entries;
                state.auditTotal = data.total || entries.length;
                state.auditTotalPages = data.total_pages || Math.ceil(state.auditTotal / PAGE_SIZE) || 1;
            } else {
                entries = [];
                state.auditTotal = 0;
                state.auditTotalPages = 1;
            }
            state.auditEntries = entries;
            renderAuditTableBody();
            renderAuditPagination();
        }).catch(function (err) {
            hideLoading();
            console.error('Failed to load audit log', err);
            toast('Failed to load audit log', 'error');
        });
    }

    function renderAuditTableBody() {
        var tbody = document.getElementById('zdz-audit-tbody');
        if (!tbody) return;

        if (state.auditEntries.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;">No audit log entries found</td></tr>';
            return;
        }

        var html = '';
        state.auditEntries.forEach(function (entry) {
            var ts = entry.timestamp || entry.created_at || '';
            var displayTime = '';
            if (ts) {
                var d = new Date(ts);
                if (!isNaN(d.getTime())) {
                    displayTime = d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) +
                        ' ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                }
            }
            var userName = '';
            if (entry.user && typeof entry.user === 'object') {
                userName = entry.user.display_name || entry.user.email || 'User #' + entry.user.id;
            } else if (entry.user_name || entry.display_name) {
                userName = entry.user_name || entry.display_name;
            } else if (entry.user_id) {
                userName = 'User #' + entry.user_id;
            }

            html += '<tr>' +
                '<td style="white-space:nowrap;font-size:12px;color:#64748b;">' + esc(displayTime) + '</td>' +
                '<td style="font-weight:500;">' + esc(userName) + '</td>' +
                '<td>' + actionBadgeHtml(entry.action) + '</td>' +
                '<td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(entry.detail || entry.description || '') + '</td>' +
                '<td>' + esc(entry.app || '') + '</td>' +
                '<td style="font-family:monospace;font-size:12px;color:#64748b;">' + esc(entry.ip || entry.ip_address || '') + '</td>' +
            '</tr>';
        });

        tbody.innerHTML = html;
    }

    function renderAuditPagination() {
        var el = document.getElementById('zdz-audit-pagination');
        if (!el) return;

        var total = state.auditTotal;
        var totalPages = state.auditTotalPages;
        var page = state.auditPage;

        if (totalPages <= 1 && total <= PAGE_SIZE) {
            el.innerHTML = '<div class="zdz-pagination"><div class="zdz-pagination-info">' + total + ' entries</div><div></div></div>';
            return;
        }

        var html = '<div class="zdz-pagination">';
        html += '<div class="zdz-pagination-info">Page ' + page + ' of ' + totalPages + ' (' + total + ' entries)</div>';
        html += '<div class="zdz-pagination-btns">';

        // Prev button
        html += '<button ' + (page <= 1 ? 'disabled' : '') + ' onclick="window.zdzAuditPage(' + (page - 1) + ')">&laquo; Prev</button>';

        // Page buttons - show up to 7 pages with ellipsis
        var startPage = Math.max(1, page - 2);
        var endPage = Math.min(totalPages, page + 2);

        if (startPage > 1) {
            html += '<button onclick="window.zdzAuditPage(1)">1</button>';
            if (startPage > 2) {
                html += '<button disabled>&hellip;</button>';
            }
        }

        for (var i = startPage; i <= endPage; i++) {
            html += '<button class="' + (i === page ? 'active' : '') + '" onclick="window.zdzAuditPage(' + i + ')">' + i + '</button>';
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += '<button disabled>&hellip;</button>';
            }
            html += '<button onclick="window.zdzAuditPage(' + totalPages + ')">' + totalPages + '</button>';
        }

        // Next button
        html += '<button ' + (page >= totalPages ? 'disabled' : '') + ' onclick="window.zdzAuditPage(' + (page + 1) + ')">Next &raquo;</button>';

        html += '</div></div>';
        el.innerHTML = html;
    }

    window.zdzAuditPage = function (page) {
        if (page < 1 || page > state.auditTotalPages) return;
        state.auditPage = page;
        loadAuditLog();
    };

    window.zdzRefreshAudit = function () {
        state.auditPage = 1;
        state.auditActions = [];
        state.auditUsers = [];
        loadAuditFilters();
        loadAuditLog();
    };

    // ── Usage Tab ──────────────────────────────────────────────────
    // Per app and per person: opens, people, time open, the device class, last use; opens by hour of day x weekday (the
    // site's zone); apps nobody opened in 30 days; each app's registered actions; searches and palette results (the
    // kind only, never the words); every usage kind's count; pruning's last run; the usage-records card's state. The
    // shared account is "Shared device", never a person. GET zorderz/v1/admin/usage.
    var USAGE_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    var USAGE_DEVICES = { phone: 'Phone or tablet', desktop_app: 'Desktop app', browser: 'Browser', unknown: 'Not known' };
    var USAGE_KIND_WORDS = {
        app_open: 'Opened an app', app_close: 'Closed an app (time open)', app_action: "An app's own action",
        view_switch: 'Changed screen', dashboard_load: 'Started the app', heartbeat: 'Still here (every 4 min)',
        widget_interact: 'Used a card', search: 'Searched', command_palette: 'Opened a search result',
        page_view: 'Loaded the page', login: 'Signed in', logout: 'Signed out'
    };
    var USAGE_PALETTE = { app: 'An app', ask: 'Ask the chat', action: 'A shortcut', answer: 'An inline answer', quick_answer: 'A quick answer' };
    state.usage = null;
    state.usageDays = 30;
    state.usageUser = '';
    state.usageApp = '';

    function usageDur(secs) {
        var s = Math.max(0, parseInt(secs, 10) || 0);
        if (!s) return '-';
        if (s < 60) return '<1 min';
        var m = Math.round(s / 60);
        if (m < 60) return m + ' min';
        var h = Math.floor(m / 60), r = m % 60;
        return h + ' h' + (r ? ' ' + r + ' min' : '');
    }
    // In the site's zone, like the hour grid (the admin's own computer may be elsewhere).
    function usageWhen(iso) {
        if (!iso) return '-';
        var d = new Date(iso);
        if (isNaN(d.getTime())) return '-';
        var tz = (state.usage && state.usage.tz) || undefined;
        try {
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', timeZone: tz }) + ' ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', timeZone: tz });
        } catch (e) {
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        }
    }
    function usageDay(ymd) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(ymd || ''));
        if (!m) return '-';
        return ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][parseInt(m[2], 10) - 1] + ' ' + parseInt(m[3], 10) + ', ' + m[1];
    }
    function usageNum(n) { return (parseInt(n, 10) || 0).toLocaleString('en-US'); }
    // esc() leaves quotes as they are, so nothing from the server goes into an attribute through it here. Attributes use usageAttr(), and the tab draws no inline handlers (listeners are bound after drawing).
    function usageAttr(v) {
        return String(v == null ? '' : v).replace(/[&<>"'`]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;' }[c]; });
    }

    function loadUsage(fresh) {
        var p = ['days=' + state.usageDays];
        if (fresh) p.push('fresh=1');
        if (state.usageUser) p.push('user=' + encodeURIComponent(state.usageUser));
        if (state.usageApp) p.push('app=' + encodeURIComponent(state.usageApp));
        showLoading();
        return api('admin/usage?' + p.join('&')).then(function (r) {
            hideLoading();
            state.usage = r && r.success ? r.data : null;
            renderUsageTab();
        }).catch(function (err) {
            hideLoading();
            console.error('Failed to load usage', err);
            var el = document.getElementById('zdz-tab-usage');
            if (el) el.innerHTML = '<div class="zdz-usage-note is-warn" role="alert">The usage records could not be loaded. Try Refresh.</div>';
        });
    }
    window.zdzRefreshUsage = function () { loadUsage(true); };
    window.zdzUsageFilter = function (k, v) {
        if (k === 'days') state.usageDays = parseInt(v, 10) || 30;
        if (k === 'user') state.usageUser = v || '';
        if (k === 'app') state.usageApp = v || '';
        loadUsage();
    };

    function usageGridHtml(grid) {
        var max = 0;
        (grid || []).forEach(function (row) { (row || []).forEach(function (n) { if (n > max) max = n; }); });
        var html = '<div class="zdz-usage-grid-wrap"><table class="zdz-usage-grid"><caption>Apps opened by hour of day and weekday (site time)</caption><thead><tr><th scope="col"><span class="screen-reader-text">Day</span></th>';
        for (var h = 0; h < 24; h++) html += '<th scope="col">' + (h % 3 === 0 ? (h === 0 ? '12a' : (h < 12 ? h + 'a' : (h === 12 ? '12p' : (h - 12) + 'p'))) : '<span class="screen-reader-text">' + h + ':00</span>') + '</th>';
        html += '</tr></thead><tbody>';
        (grid || []).forEach(function (row, d) {
            html += '<tr><th scope="row">' + USAGE_DAYS[d] + '</th>';
            (row || []).forEach(function (n, h) {
                var lvl = !n ? 0 : Math.min(4, 1 + Math.floor(3.999 * n / Math.max(1, max)));
                html += '<td class="lv' + lvl + '" title="' + USAGE_DAYS[d] + ' ' + h + ':00-' + (h + 1) + ':00: ' + n + ' opens">' + (n ? '<span class="screen-reader-text">' + n + '</span>' : '') + '</td>';
            });
            html += '</tr>';
        });
        html += '</tbody></table><div class="zdz-usage-legend" aria-hidden="true">Fewer <i class="lv1"></i><i class="lv2"></i><i class="lv3"></i><i class="lv4"></i> More</div></div>';
        return html;
    }

    function usageDevicesHtml(dev) {
        var tot = 0, parts = [];
        Object.keys(dev || {}).forEach(function (k) { tot += dev[k] || 0; });
        if (!tot) return '-';
        ['phone', 'desktop_app', 'browser', 'unknown'].forEach(function (k) {
            if (dev[k]) parts.push(esc((USAGE_DEVICES[k] || k)) + ' ' + Math.round(100 * dev[k] / tot) + '%');
        });
        return parts.join(', ');
    }

    function usagePhonePct(dev) {
        var tot = 0;
        Object.keys(dev || {}).forEach(function (k) { if (k !== 'unknown') tot += dev[k] || 0; });
        return tot ? Math.round(100 * (dev.phone || 0) / tot) + '%' : '-';
    }

    function renderUsageTab() {
        var el = document.getElementById('zdz-tab-usage');
        if (!el) return;
        var u = state.usage;
        if (!u) { el.innerHTML = '<div class="zdz-usage-note">Loading...</div>'; return; }
        var apps = zdzAdminData.apps || [];
        var appName = {};
        apps.forEach(function (a) { appName[a.id] = a.name; });
        (u.apps || []).forEach(function (a) { if (!appName[a.id]) appName[a.id] = a.name; });

        var people = '<option value="">Everyone</option><option value="shared"' + (state.usageUser === 'shared' ? ' selected' : '') + '>Shared device</option>';
        (state.users || []).filter(function (x) { return x.role !== 'zdz_general'; }).forEach(function (x) {
            people += '<option value="' + usageAttr(String(x.id)) + '"' + (String(state.usageUser) === String(x.id) ? ' selected' : '') + '>' + esc(x.display_name || ('User #' + x.id)) + '</option>';
        });
        var appOpts = '<option value="">All apps</option>';
        Object.keys(appName).sort(function (a, b) { return String(appName[a]).localeCompare(String(appName[b])); }).forEach(function (id) {
            appOpts += '<option value="' + usageAttr(id) + '"' + (state.usageApp === id ? ' selected' : '') + '>' + esc(appName[id]) + '</option>';
        });
        var dayOpts = '';
        [7, 30, 90, 180].forEach(function (d) { dayOpts += '<option value="' + d + '"' + (u.days === d ? ' selected' : '') + '>Last ' + d + ' days</option>'; });

        var pr = u.prune || {};
        var pruneMsg = pr.error
            ? 'Clearing old records stopped on its last run (' + esc(usageWhen(pr.at)) + '): ' + esc(pr.error) + '. Nothing was lost; it tries again daily.'
            : (pr.late
                ? (pr.at ? 'Old records were last cleared ' + esc(usageWhen(pr.at)) + ', more than two days ago: WP-Cron may not be running.' : 'Old records have not been cleared yet (the first run is about an hour after the theme is updated).')
                : 'Old records were last cleared ' + esc(usageWhen(pr.at)) + ': ' + usageNum(pr.deleted) + ' rows older than ' + esc(String(u.raw_days)) + ' days counted into daily totals.');
        var t = u.totals || {};

        var html = '' +
            '<div class="zdz-toolbar">' +
                '<label class="screen-reader-text" for="zdz-usage-days">Period</label><select id="zdz-usage-days" data-usage-filter="days">' + dayOpts + '</select>' +
                '<label class="screen-reader-text" for="zdz-usage-user">Person</label><select id="zdz-usage-user" data-usage-filter="user">' + people + '</select>' +
                '<label class="screen-reader-text" for="zdz-usage-app">App</label><select id="zdz-usage-app" data-usage-filter="app">' + appOpts + '</select>' +
                '<button class="zdz-btn" id="zdz-usage-refresh" type="button"><i data-lucide="refresh-cw" style="width:14px;height:14px;"></i> Refresh</button>' +
            '</div>' +
            '<p class="zdz-usage-note">What the app records, to see what is used and what needs improving. It is never for grading people. ' + esc((u.consent || {}).rule || '') + '</p>' +
            '<p class="zdz-usage-note' + (pr.error || pr.late ? ' is-warn' : '') + '"' + (pr.error ? ' role="alert"' : '') + '>' + pruneMsg + '</p>' +
            '<div class="zdz-stats-row">' +
                statCardHtml('mouse-pointer-click', '#2563eb', usageNum(t.opens), 'Apps opened') +
                statCardHtml('users', '#7c3aed', usageNum(t.people), 'People') +
                statCardHtml('timer', '#0e7490', usageDur(t.secs), 'Time open') +
                statCardHtml('smartphone', '#15803d', usagePhonePct(t.devices), 'Opened on a phone or tablet') +
            '</div>' +
            '<div class="zdz-table-wrap zdz-usage-block">' + usageGridHtml(u.grid) + '</div>';

        // Apps
        html += '<h2 class="zdz-usage-h">Apps, last ' + esc(String(u.days)) + ' days</h2><div class="zdz-table-wrap zdz-usage-block"><table class="zdz-table zdz-usage-table"><thead><tr>' +
            '<th scope="col">App</th><th scope="col">Opens</th><th scope="col">People</th><th scope="col">Time open</th><th scope="col">Per open</th><th scope="col">Devices</th><th scope="col">Last opened</th><th scope="col">Before ' + esc(String(u.raw_days)) + ' days</th></tr></thead><tbody>';
        if (!(u.apps || []).length) html += '<tr><td colspan="8" class="zdz-usage-empty">No app was opened in this period.</td></tr>';
        (u.apps || []).forEach(function (a) {
            var per = a.per_open ? usageDur(a.per_open) : '-';
            html += '<tr data-usage-app="' + usageAttr(a.id) + '"><th scope="row">' + esc(a.name) + '</th><td>' + usageNum(a.opens) + '</td><td>' + usageNum(a.people) + '</td><td>' + usageDur(a.secs) + '</td><td>' + per + '</td><td>' + usageDevicesHtml(a.devices) + '</td><td>' + esc(usageWhen(a.last)) + '</td><td>' + (a.older && a.older.opens ? usageNum(a.older.opens) + ' opens' : '-') + '</td></tr>';
        });
        html += '</tbody></table></div>';

        // Unused
        html += '<h2 class="zdz-usage-h">Not opened by anyone in 30 days</h2>';
        if (!(u.unused || []).length) {
            html += '<p class="zdz-usage-note">Every app was opened at least once in the last 30 days.</p>';
        } else {
            html += '<ul class="zdz-usage-unused">' + (u.unused || []).map(function (a) {
                return '<li><strong>' + esc(a.name) + '</strong> <span>' + (a.last ? 'last opened ' + esc(usageWhen(a.last)) : (a.last_day ? 'last opened ' + esc(usageDay(a.last_day)) : 'never opened in the records kept')) + '</span></li>';
            }).join('') + '</ul>';
        }

        // People
        html += '<h2 class="zdz-usage-h">People</h2><div class="zdz-table-wrap zdz-usage-block"><table class="zdz-table zdz-usage-table"><thead><tr>' +
            '<th scope="col">Person</th><th scope="col">Opens</th><th scope="col">Days used</th><th scope="col">Time open</th><th scope="col">Uses most</th><th scope="col">Last seen</th></tr></thead><tbody>';
        if (!(u.people || []).length) html += '<tr><td colspan="6" class="zdz-usage-empty">Nobody used the app in this period.</td></tr>';
        (u.people || []).forEach(function (p) {
            html += '<tr data-usage-user="' + usageAttr(p.id) + '"><th scope="row">' + esc(p.name) + '</th><td>' + usageNum(p.opens) + '</td><td>' + usageNum(p.days) + '</td><td>' + usageDur(p.secs) + '</td><td>' + esc((p.top || []).map(function (id) { return appName[id] || id; }).join(', ') || '-') + '</td><td>' + esc(usageWhen(p.last)) + '</td></tr>';
        });
        html += '</tbody></table></div>';

        // Actions, searches
        html += '<h2 class="zdz-usage-h">What people do inside the apps</h2><div class="zdz-table-wrap zdz-usage-block"><table class="zdz-table zdz-usage-table"><thead><tr>' +
            '<th scope="col">App</th><th scope="col">Action</th><th scope="col">Times</th><th scope="col">People</th></tr></thead><tbody>';
        if (!(u.actions || []).length) html += '<tr><td colspan="4" class="zdz-usage-empty">No app has registered its actions yet. Each app adds its main ones in its next release.</td></tr>';
        (u.actions || []).forEach(function (a) {
            html += '<tr><th scope="row">' + esc(a.app_name) + '</th><td>' + esc(a.label) + '</td><td>' + usageNum(a.n) + '</td><td>' + usageNum(a.people) + '</td></tr>';
        });
        html += '</tbody></table></div>';
        var s = u.search || {}, pl = u.palette || {};
        html += '<p class="zdz-usage-note">Searches: ' + usageNum(s.ask) + ' in the ask bar, ' + usageNum(s.apps) + ' in the app search. Results opened: ' +
            Object.keys(USAGE_PALETTE).map(function (k) { return esc(USAGE_PALETTE[k]) + ' ' + usageNum(pl[k]); }).join(', ') + '. The words people type are never recorded.</p>';

        // The stream
        html += '<details class="zdz-usage-stream"><summary>Everything recorded in this period, by kind</summary><div class="zdz-table-wrap zdz-usage-block"><table class="zdz-table zdz-usage-table"><thead><tr><th scope="col">Kind</th><th scope="col">Recorded as</th><th scope="col">Rows</th></tr></thead><tbody>';
        Object.keys(u.stream || {}).forEach(function (k) {
            html += '<tr><th scope="row">' + esc(USAGE_KIND_WORDS[k] || k) + '</th><td><code>' + esc(k) + '</code></td><td>' + usageNum(u.stream[k]) + '</td></tr>';
        });
        html += '</tbody></table></div></details>';

        el.innerHTML = html;
        // Listeners, not inline handlers: an id read from the log never becomes code.
        el.querySelectorAll('[data-usage-filter]').forEach(function (sel) {
            sel.addEventListener('change', function () { window.zdzUsageFilter(sel.getAttribute('data-usage-filter'), sel.value); });
        });
        var rf = el.querySelector('#zdz-usage-refresh');
        if (rf) rf.addEventListener('click', window.zdzRefreshUsage);
        el.querySelectorAll('tr[data-usage-app], tr[data-usage-user]').forEach(function (tr) {
            tr.addEventListener('click', function () {
                if (tr.hasAttribute('data-usage-app')) window.zdzUsageFilter('app', tr.getAttribute('data-usage-app'));
                else window.zdzUsageFilter('user', tr.getAttribute('data-usage-user'));
            });
        });
        refreshIcons();
    }

    // ── Initialization ─────────────────────────────────────────────
    function init() {
        var root = document.getElementById('zdz-admin-dashboard');
        if (!root) return;

        renderShell();
        renderAuditTab();
        loadUsers();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
