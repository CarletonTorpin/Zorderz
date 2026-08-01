<?php
/**
 * Module: Zorderz - Scheduler
 * Description: Calendar scheduler for the Zorderz dashboard. Personal appointments,
 *   team availability, and a shared team calendar. Each user connects their OWN
 *   Google or Microsoft (Exchange) calendar (Connected Calendars) so the team
 *   scheduler knows when they are busy; an optional org-wide Microsoft 365 app
 *   ("Mode A") can additionally two-way sync every mailbox. Dashboard tile +
 *   dictation-friendly availability ("mark me open these dates").
 * Version:     1.7.1
 * Author:      Zorderz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 8.0
 *
 * This is a bundled app module (loaded by zorderz-apps.php), not a standalone
 * plugin. It registers with the theme through the `zdz_register_apps` filter on
 * after_setup_theme and declines cleanly when the theme is absent.
 *
 * v1.7.1 (CONNECTED CALENDARS — SC1.1 SETTINGS ENTRY POINT, 2026-07-31).
 *   Surfaces the per-user connect flow in profile → Settings → App
 *   Authorizations (alongside FreshBooks / Nutshell), per the field request.
 *   Two additive pieces: (1) a `zdz_app_authorizations` filter hook (registered
 *   in zsch_load_includes) reports a `calendars` entry {connected,count} for
 *   the current user — gated by ZSCH_OAuth::feature_enabled() and NON-kiosk
 *   (mirrors the widget card's gate); safe no-op on a theme without the filter.
 *   (2) connections.js honours the theme's `?zdz_connect_calendar=open`
 *   deep-link and auto-opens the Connected Calendars modal, so the Settings
 *   card's "Connect a Calendar" button routes straight into the existing OAuth
 *   flow (no new connect surface, no new scopes). This is the cross-component
 *   contract with the theme's App Authorizations card. Two files: app.php
 *   (filter + version), assets/js/connections.js (deep-link).
 *
 * v1.7.0 (CONNECTED CALENDARS — PHASE 1: THE BUSY OVERLAY, 2026-07-30). The
 *   "last mile" that makes connected calendars actually READ. A sync engine
 *   pulls each enabled conflict feed's busy times into wp_zsch_external_events
 *   on the existing 5-minute zsch_cron_sync, and ONE shared read helper
 *   surfaces them everywhere the design specifies. NO schema migration
 *   (migrate-1.6.0 already shipped every Phase-1 column) and NO console/OAuth
 *   change — the already-granted delegated Calendars.Read /
 *   calendar.events.readonly scopes are used as-is.
 *   - NEW includes/class-zsch-sync.php (ZSCH_Sync): cron_all() [hooked to
 *     zsch_cron_sync ALONGSIDE the app-level Graph sync; self-gated by
 *     ZSCH_OAuth::feature_enabled(), transient-locked so a manual sync can't
 *     overlap], sync_user() [manual, owner-scoped], the per-feed pull with an
 *     idempotent upsert (ON DUP KEY on UNIQUE feed_id+external_event_id), a
 *     generational prune (in-window rows this run did NOT re-touch = vanished
 *     upstream), a rolling-window purge (today−1d…+60d), plus read_busy()
 *     [busy-only, never on kiosk] and conflicts_for() [booking check].
 *   - PROVIDER FETCHERS on the existing request cores: fetch_events() on
 *     ZSCH_Graph_Delegated (Graph calendarView, Prefer:UTC, skips
 *     free/cancelled, private→no title, pages @odata.nextLink) and ZSCH_Google
 *     (Calendar events.list singleEvents=true, skips transparent/cancelled,
 *     private→no title, pages nextPageToken).
 *   - READ INTEGRATION: ZSCH_Availability::team_grid() folds external busy into
 *     each member's blocks (busy-only, source:'external'; excluded for the
 *     read-only kiosk); ZSCH_TSA_Bridge::availability_lookup() adds external
 *     busy to busy_events (never on kiosk); ZSCH_Appointments::create() gains
 *     the FIRST booking-conflict check — policy 'warn' (default) books + returns
 *     warnings[], policy 'block' refuses; total no-op when the feature is off or
 *     the owner has no feeds.
 *   - NEW REST: POST zorderz/v1/scheduler/connections/sync (owner-scoped, rate-gated);
 *     connections.js fires it after a connect / new-feed enable so busy shows in
 *     seconds, not up to 5 min. The team grid, chat and conflict paths fill
 *     SERVER-SIDE (no core widget.js change). Poll-only this release; webhook
 *     subscriptions (the channel_* columns) remain a future enhancement.
 *   FILES: NEW includes/class-zsch-sync.php; includes/class-zsch-graph-
 *   delegated.php + includes/class-zsch-google.php (fetch_events + dt helpers);
 *   includes/class-zsch-availability.php, includes/class-zsch-tsa-bridge.php,
 *   includes/class-zsch-appointments.php, includes/class-zsch-rest.php,
 *   app.php (cron wire + version), assets/js/connections.js.
 *
 * v1.6.3 (changelog backfill). The version constant + plugin header stood at
 *   1.6.3 with no in-file changelog entry; this line restores continuity. No
 *   functional change is recorded between 1.6.2 and 1.6.3.
 *
 * v1.6.2 (CONNECTED CALENDARS — diagnostic, 2026-07-17). Adds Microsoft's/
 *   Google's full `error_description` (which carries the AADSTS code naming the
 *   exact cause) to the token-error log line, so an `invalid_client` /
 *   `invalid_grant` at the code exchange can be pinned precisely instead of
 *   guessed. The connect flow still fails on some setups with invalid_client
 *   even though the Test-config probe (a refresh_token grant) passes and the
 *   Azure app is a confirmed confidential Web client with an exact redirect
 *   URI and public-client-flows disabled — this build surfaces WHY. No secret
 *   is logged (error_description never contains it). Two files touched vs 1.6.1:
 *   includes/class-zsch-graph-delegated.php, includes/class-zsch-google.php.
 *   Diagnostic is harmless to leave in; can stay or be trimmed once resolved.
 *
 * v1.6.1 (CONNECTED CALENDARS — connect-flow fix, 2026-07-16). Fixes the live
 *   OAuth connect failing with `invalid_grant` for BOTH providers. Root cause:
 *   ZSCH_OAuth::start_url() returned a wp_nonce_url() string with HTML-encoded
 *   ampersands ('&amp;'); connections.js assigned it to window.location.href,
 *   and a JS location assignment navigates to the string verbatim (no HTML
 *   entity decode), so the browser hit `?zsch_oauth=start&amp;provider=…` —
 *   the server saw params keyed `amp;provider` / `amp;_wpnonce`, the real
 *   provider + nonce went missing, and the authorize→callback→exchange chain
 *   died at the token exchange (invalid_grant). Fix: start_url() now builds the
 *   URL with add_query_arg (literal '&'); connections.js gained a goStart()
 *   guard that also strips any '&amp;' before navigating (Connect + Reconnect).
 *   TWO FILES CHANGED vs 1.6.0: includes/class-zsch-oauth.php,
 *   assets/js/connections.js. No DB change, no config change — all Phase-0
 *   console setup and both saved credentials remain valid.
 *
 * v1.6.0 (CONNECTED CALENDARS — PHASE 0 FOUNDATIONS, 2026-07-15; plan
 *   `Scheduler-Connected-Calendars-Plan-2026-07-14`). Nutshell-style per-user
 *   external calendars, phase 0 of 2: each staff member can now CONNECT their
 *   own Google Calendar (any-login Google accounts incl. non-Gmail) and/or a
 *   Microsoft 365 account (multi-tenant delegated app — separate registration
 *   from the app-level sync app, which is UNTOUCHED) and pick which calendars
 *   count as CONFLICT CALENDARS. This release ships the foundations only —
 *   connect / list / pick / disconnect. No sync engine yet: external events
 *   do not appear anywhere until Phase 1 (busy overlay) ships.
 *   - THREE NEW TABLES (db/migrate-1.6.0.php, additive):
 *     wp_zsch_calendar_accounts (per-user grants; encrypted token vault),
 *     wp_zsch_calendar_feeds (chosen conflict calendars + Phase-1 sync
 *     bookkeeping), wp_zsch_external_events (the future busy mirror).
 *   - TOKEN VAULT (class-zsch-vault.php): sodium secretbox (OpenSSL
 *     AES-256-CTR+HMAC fallback) at rest, key derived from wp_salt('auth');
 *     per-account GET_LOCK single-flight refresh with version-adopt (the
 *     FreshBooks token-service lesson, INV-4); Microsoft's rotated
 *     refresh-token pair persisted atomically; invalid_grant → quiet
 *     'reauth_needed' status, never blanked tokens.
 *   - FIRST PER-USER OAUTH SURFACE (class-zsch-oauth.php): front-end routes
 *     /?zsch_oauth=start|callback&provider=google|microsoft. Session-derived
 *     identity (INV-Session), kiosk denied at the gate (INV-Kiosk), CSRF
 *     nonce on start, 5/hr rate gate, HMAC single-use state via
 *     ZDZ_Share_Link 'zsch-oauth' namespace (its first runtime caller),
 *     constant-time verify, bare 404 on ANY state mismatch (INV-Token),
 *     provider payloads never echoed. Requires theme 2.36.0+.
 *   - PROVIDER CLIENTS: hand-rolled wp_remote_* like everything else —
 *     class-zsch-google.php (read-only scopes; no CASA) and
 *     class-zsch-graph-delegated.php (organizations authority; Phase-1
 *     scopes Calendars.Read). Identity keys are immutable (Google `sub`,
 *     Entra `tid:oid`) — email is a display label only.
 *   - CONNECTED CALENDARS CARD: ⚙ header button + modal in the Schedule
 *     widget (own assets connections.js/.css — core widget.js untouched);
 *     REST zorderz/v1/scheduler/connections* (owner-scoped rows, admin sees status-only
 *     roster, Cache-Control: no-store).
 *   - ADMIN: Settings → TS Scheduler gains a Connected Calendars section
 *     (Google/Microsoft delegated credentials in isolated options exactly
 *     like zsch_graph_secret; per-provider config test; conflict policy;
 *     roster).
 *   - FLAG: option zsch_connected_cals, DEFAULT 'no' — everything above is
 *     invisible and no-ops until an admin configures a provider and flips it
 *     (the Graph-sync "safe no-op when unconfigured" posture). Rollback =
 *     flag off; tables/tokens persist harmlessly.
 * v1.5.3 (CROSS-APP AUTO-REFRESH, 2026-07-03). A chat booking ([ZSCH_BOOK])
 *   previously stayed invisible in the widget until a manual Today-tap.
 *   The widget now reloads the active view's data when stale (>60s) on:
 *   widget re-entering the viewport, PWA foregrounding, and a 5-minute
 *   on-screen tick — always suppressed while a modal, the day-hours
 *   editor, or uncommitted availability paint is active. Client-only;
 *   no new endpoints.
 * v1.5.1 (PHASE 4 G2/G3, 2026-07-03). REST hardening: per-user write-rate
 *   gates on event-create (60/h) and dictation (30/h) returning 429 +
 *   Retry-After (fail-open transient counters); widget gains a 6h REST-nonce
 *   keepalive plus a one-shot fresh-nonce retry on rest_cookie_invalid_nonce
 *   403s (kills the installed-PWA \"calendar quietly stopped saving\" class).
 *   Requires theme >= 2.30.0 for the ts_fresh_nonce endpoint (degrades
 *   gracefully without it: behavior identical to v1.5.0).
 * v1.5.0 (D2 — CASCADE CALENDAR, 2026-07-03; plan v2 §D2, owner design).
 *   The Calendar view becomes a stacked drill-down in one footprint:
 *   LINE 1 a one-line month strip (6 tappable week spans, day ticks with
 *   event density, today marked, selected week highlighted; ‹ › months) —
 *   LINE 2 the selected week as a 7-day row (44px+ cells, owner-colored
 *   event dots; ‹ › weeks) — LINE 3 tap a day and its agenda expands
 *   beneath (7am–8pm hour rows, auto-extends for outliers; all-day and
 *   continuing events pinned; tap an event to edit, tap an empty hour to
 *   create prefilled; ‹ › days, ✕ or re-tap collapses). Selected week/day
 *   persisted per device. Tap-driven throughout (chevrons everywhere,
 *   WCAG 2.5.1); swipe on strip/week is enhancement-only with iOS
 *   edge-back-swipe guard and scroll-wins rules. One padded month window
 *   from /events re-projected three ways — NO new endpoints. Flag:
 *   zsch_views_v2 ('yes' default); 'no' restores the classic grid.
 *   Availability/Team panes and all D3 gestures untouched.
 * v1.4.0 (D3 — AVAILABILITY TAP-SAFETY + DAY EDITOR, 2026-07-03; plan v2 §D3).
 *   Owner directive from the Jul 2 mobile review: accidental cross-day drags
 *   were overwriting availability. (1) ONE DAY PER GESTURE — the v1.2.2
 *   cross-day rectangle is retired behind MULTIDAY_PAINT (default OFF);
 *   vertical drag still paints hours within the pressed day; pointercancel
 *   aborts cleanly with nothing saved. (2) LONG-PRESS (500ms/10px) any day →
 *   full-width DAY EDITOR: hour rows 8am–8pm, Open/Busy/Clear, drag to paint,
 *   explicit Save/Cancel (nothing writes until Save; same delete+create wire
 *   format via the new saveDayModel()). (3) OVERWRITE GUARD — replacing
 *   previously-set hours asks with an in-cell ✓/✕ chip; every save shows a 5s
 *   toast with UNDO (restores the pre-gesture model) and ✎ Edit hours (the
 *   visible route to the editor, so long-press is never the only path; a
 *   one-time hint teaches the gesture, shown max twice). iOS playbook:
 *   implicit pointer capture on the pressed cell only, -webkit-touch-callout
 *   + user-select suppressed, visual press-progress ring instead of haptics,
 *   44px targets, VoiceOver labels on day cells. Front-end only (widget.js +
 *   widget.css); no server or schema changes.
 *
 * v1.3.0 (Scheduler gets its own app ICON). The Scheduler had a dashboard widget
 *   but NO tappable app icon (it was registered springboard:false = widget-only),
 *   so unlike every other app you couldn't open/jump to it from the top quick-link
 *   dock, the Apps grid, recents, or search. Changed 'springboard' => true so the
 *   calendar icon now appears in all those surfaces. Because the app is
 *   bridge_type:'inline_widget', TAPPING the icon JUMPS to the Schedule widget on
 *   the dashboard (no separate full-screen page) — identical to how the other
 *   inline apps behave. No change to the calendar, availability, sync, or any
 *   data path; this only makes the app discoverable/launchable.
 *
 * v1.2.2 (FIX — drag-to-paint didn't SAVE). In 1.2.0/1.2.1 the bar painted
 *   visually while dragging but the edit was never sent to the server, so it
 *   vanished on reload. Root cause: the drag used ONE grid-level pointer listener
 *   that resolved which day you were on via document.elementFromPoint(). Inside
 *   the theme's nested overflow:clip scrollers (.view / .dash-widget-body) that
 *   call returns the clipping container, not the day cell — so pointerup never
 *   matched a cell and the save (commitDirtyDays) never ran. Fix: bind the drag
 *   DIRECTLY to each day cell and use setPointerCapture, so once a drag begins on
 *   a cell every move/up routes to it regardless of overlays — no elementFromPoint
 *   dependency for the single-day path. Cross-day painting resolves the hovered
 *   cell via elementsFromPoint (plural, stack-aware) with a rect fallback.
 *   Verified live end-to-end: a painted range now POSTs a partial-day block and
 *   reads back correctly (e.g. drag → "open 11:00–18:00"). Front-end only.
 *
 * v1.2.1 (FIX — availability hour bars overflowed their cells). In 1.2.0 the new
 *   8am–8pm track was position:absolute but the day cell was position:static, so
 *   the track positioned against the whole widget body instead of its own cell
 *   (one giant ~775px bar covering the grid instead of a small bar per day). Fix:
 *   .zsch-w-grid--avail .zsch-w-cell--avail is now position:relative, so each
 *   track/segment is contained in its day. Verified live: a day now shows a small
 *   bar (e.g. red top = busy morning, green bottom = free afternoon). CSS-only.
 *
 * v1.2.0 (AVAILABILITY REDESIGN — 8am–8pm hour bars + drag-to-paint; no more
 *   "Mixed"). Field feedback: the old availability painting was confusing — the
 *   "Mark open" / "Mark busy" wording was unclear, and tapping a day that was
 *   already busy (or vice-versa) produced a "Mixed" state nobody understood. The
 *   whole-day model is replaced with an at-a-glance WORKDAY BAR:
 *     • Each day cell is now a vertical 8am–8pm bar (top = 8am, bottom = 8pm)
 *       with faint 3-hour tick marks. Painted hours show as colored segments —
 *       GREEN = available, RED = busy, bare track = not set. You can read a
 *       person's day shape at a glance (e.g. red top + green bottom = booked
 *       this morning, free this afternoon).
 *     • DRAG TO PAINT: pick Free / Busy / Clear, then drag down a day to paint
 *       an hour range; drag across days to paint several at once. Snaps to the
 *       hour. A live "Painting: Available/Busy/Clearing" label + a color legend
 *       make the current mode obvious. There is NO "Mixed" state — every hour is
 *       explicitly Available, Busy, or Not set.
 *     • Clearer wording: "Mark open" → "Free", "Mark busy" → "Busy", plus a new
 *       "Clear" mode to un-set hours.
 *   Implementation is front-end only and uses the EXISTING time-range storage:
 *   each contiguous run of an hour-kind is saved as a partial-day block
 *   (is_all_day:false, "YYYY-MM-DD HH:MM"), which the REST create + the data
 *   model + the DB (datetime columns) already support. On each edit a touched
 *   day's blocks are deleted and re-created as clean contiguous runs, so the
 *   saved representation is always tidy and round-trips correctly. No DB
 *   migration, no REST change, no change to the team grid, dictation, or sync.
 *   (Dictation still paints whole days for now; hour-level dictation is a future
 *   add.)
 *
 * v1.1.2 (interop — L4 capability registration). Publishes the bridge's already-
 *   shaped verbs (availability.lookup, schedule.lookup, appointment.create) via the
 *   `zdz_register_capabilities` filter, pulled straight from
 *   ZSCH_TSA_Bridge::get_capability_descriptor() so the registration can't drift
 *   from the bridge's declared kiosk/side_effect posture. Safe to ship now: until
 *   the central resolver exists the filter just adds rows nobody reads; when it
 *   lands the Scheduler is L4-native with no further code. No behavior change —
 *   the bridge verbs, kiosk-bounded reads, and preview-and-confirm create are
 *   unchanged. (The [ZSCH_*] engine marker handlers remain a TSA-side hand-off.)
 *
 * v1.1.1 (availability render + paint-toggle fix):
 *   - FIX: marking a day "open"/"busy" appeared to save nothing and the day
 *     never showed its color; re-tapping stacked duplicate blocks instead of
 *     toggling. Root cause was a type mismatch in assets/js/widget.js — the
 *     REST API returns owner_user_id as an integer, but the page localizes
 *     zschData.userId as a STRING, so the strict comparison (2 !== "2") in
 *     availOnDay() filtered out every one of the current user's own blocks.
 *     With nothing matched, the grid rendered no open/busy state AND paintDay()
 *     never saw an existing block to remove, so each tap created a new row.
 *   - state.me is now coerced with parseInt(); availOnDay() and colorForOwner()
 *     also compare owner ids numerically (defensive). No DB or REST change;
 *     front-end only. After deploy, re-tapping a painted day correctly clears it.
 *
 * v1.1.0 (deploy-confidence + scheduler↔bot wiring):
 *   - VERSION BEACON: on load the plugin logs "TS Scheduler ACTIVE — version X"
 *     to debug.log once per hour. This makes it trivial to confirm WHICH build is
 *     actually running — the fix for a stale cache/install that left an old
 *     version live (the "Column 'source' cannot be null" availability bug was
 *     fixed in 1.0.7 but a site stuck on 1.0.5 kept failing). Grep the log for
 *     "TS Scheduler ACTIVE" after installing to verify it says 1.1.0.
 *   - Carries the v1.0.7 availability fix (source coalesces to 'manual' before
 *     the insert — see includes/class-zsch-availability.php) so painting a day
 *     "open" logs reliably once this build is genuinely live.
 *   - Pairs with TSA engine v1.19.1 + Brain Bot v1.23.0, which wire the bridge's
 *     [ZSCH_AVAIL]/[ZSCH_SCHED]/[ZSCH_BOOK] markers into chat. No DB change.
 *
 * v1.0.9 (shared month view polish — overlap + per-person clarity):
 *   - Month cells now cap at 3 event chips with a tappable "+N more"; tapping it
 *     (or a populated day) opens a DAY-DETAIL popover listing every event for
 *     that day — per-person color dot + name + time range + location + a
 *     Team/Mine tag — each row taps through to edit. This is the best-practice
 *     overlap pattern (show a few, reveal the rest on demand) so busy shared
 *     days never truncate silently.
 *   - Shared (team) chips lead with the owner's first name and a colored left
 *     accent, so "who" is readable at a glance (color is never the only signal).
 *   - Events sort all-day-first, then by start time, then by name.
 *
 * v1.0.8 (dictation + availability + calendar UX):
 *   1. NATIVE DICTATION: removed the in-app Web Speech recorder entirely. The
 *      "Say your availability" box now auto-focuses so the DEVICE's own
 *      dictation is used — the keyboard microphone on iPad/iPhone, the Dictation
 *      shortcut on macOS. More reliable, more private, least-astonishment.
 *   2. DICTATE-SHOWS BUG FIXED: dictating "open June 16 to June 18" said it saved
 *      but never appeared. Root cause: the date parser read a bare month/day
 *      (e.g. "june 16") as the year 2001, so blocks were stored in the wrong
 *      year. The JS and PHP date parsers now resolve the current year first and
 *      reject implausible years (verified across weekday/month-name/today/
 *      tomorrow phrasings). Multi-day ranges are inclusive of the end day.
 *   3. SHARED-CALENDAR UX (best practices, researched): the team grid no longer
 *      relies on COLOR ALONE — each cell carries a glyph (✓ open, ✕ busy, ◐ both)
 *      with a matching legend, deeper fills for WCAG contrast, aria-labels, and
 *      clearer empty-state copy. (Color + symbol + label = colorblind-safe.)
 *
 * v1.0.7 (fix "Could not save availability"): Marking a day open/busy by
 *   CLICKING failed with a DB error — "Column 'source' cannot be null". The
 *   create-availability code resolved the 'source' default INSIDE the
 *   in_array() guard only, so a request with no 'source' (the click-to-paint
 *   path sends none) passed a raw NULL to the NOT NULL `source` column. (The
 *   Dictate path set 'voice' explicitly, so it was unaffected.) Fixed by
 *   coalescing 'source' to 'manual' up front, then validating. Verified for
 *   missing / null / valid / invalid inputs — never NULL now.
 *
 * v1.0.6 (fix "Database insert failed"): The wp_zsch_* tables weren't being
 *   created when the plugin was updated by OVERWRITING the folder (which does
 *   not fire the activation hook), so marking availability / adding an
 *   appointment failed. Now the schema SELF-HEALS: zsch_maybe_upgrade() runs
 *   the (idempotent dbDelta) migration whenever the tables are missing — not
 *   just on a version bump — and the insert paths detect a missing table,
 *   create it, and retry once. Error messages now surface the real DB error
 *   (and log it under WP_DEBUG) instead of a generic "insert failed".
 *
 * v1.0.5 (cache-proof — fixes "widget shows but calendar is blank / nothing
 *   loads" caused by NitroPack / service-worker serving stale assets):
 *   1. FILEMTIME CACHE-BUST: widget.css/js are enqueued with a version that
 *      includes the file's modification time, so the ?ver= changes the instant
 *      the file content changes (the theme's own technique, changelog v2.21.7).
 *   2. OPTIMIZER OPT-OUT: the widget <script> is tagged data-no-optimize /
 *      data-no-defer / data-cfasync="false" / data-nitro-exclude via a
 *      script_loader_tag filter, so NitroPack/Autoptimize/Rocket Loader don't
 *      defer or combine it (a common reason it silently didn't run).
 *   3. INLINE BOOT GUARANTEE: a tiny script ships INSIDE the widget HTML (which
 *      the theme re-executes even on a cached shell). If the calendar grid is
 *      still empty shortly after render, it dynamically re-injects widget.js with
 *      a fresh cache-busting query — so the calendar always comes up WITHOUT the
 *      user clearing any cache. widget.js is now idempotent (data-zsch-mounted
 *      guard) so a second copy can't double-initialize. Verified in a headless
 *      DOM: even with the enqueued widget.js fully stripped, the fallback brings
 *      up all 42 day cells, and a double-load stays at 42 (no duplication).
 *
 * v1.0.4 (self-healing render + late-injection safety):
 *   Hardens the calendar against the empty-grid symptom seen when a stale
 *   cached widget.js loaded. Two guards in widget.js:
 *   (a) DEFERRED BOOT: if the widget HTML isn't in the dashboard zone yet when
 *       the script evaluates, it no longer bails permanently — it waits for the
 *       theme's `zdz_widgets_rendered` event / DOMContentLoaded / a short poll,
 *       then boots once the element exists.
 *   (b) SELF-HEALING: shortly after boot (and again on zdz_widgets_rendered), if
 *       #zsch-grid is still empty it forces renderMonth(). The calendar can no
 *       longer sit blank. Verified in a headless DOM for both the normal and
 *       late-injection cases (42 day cells each).
 *   NOTE on placement: a newly added widget lands at the BOTTOM of the dashboard
 *   widget zone (the theme orders by the user's saved widgetOrder; widgets not
 *   in it sort last). Reorder it to the top with the up/down arrows on the
 *   widget header — the order is saved per user.
 *
 * v1.0.3 (widget-only + resilient render):
 *   1. WIDGET-ONLY: the Scheduler no longer appears as a dock tile, left-sidebar
 *      entry, recent chip, search result, or bottom-nav tab — it is purely the
 *      inline dashboard widget. Done with `springboard => false` in the tile
 *      config (the theme's isSecondarySurface opt-out; renderWidgets still shows
 *      the inline widget) and by un-hooking the bottom-nav injector.
 *   2. CALENDAR NO LONGER RENDERS BLANK: the boot used to gate the first paint on
 *      the /team fetch (`loadRoster().then(setView)`) with no error handling — so
 *      any failure of that REST call left the grid empty. Now the grid paints
 *      SYNCHRONOUSLY on boot (setView('month') first), the roster loads in the
 *      background (non-blocking, re-renders colors when it lands), the api()
 *      helper never rejects, and every view draws its structure before data
 *      arrives. Verified in a headless DOM: 42 day cells render even when /team
 *      fails. Adding an appointment works with no Graph sync configured.
 *
 * v1.0.2 (modal auto-open fix): The "New appointment" and "Dictate
 *   availability" modals popped open by themselves the moment the widget
 *   loaded. Cause was CSS, not JS: `.zsch-w-modal { display:flex }` (used to
 *   center the modal card) overrode the HTML `hidden` attribute, because a
 *   bare class `display:` rule beats the browser's default
 *   `[hidden]{display:none}` (equal specificity, later in the cascade). So the
 *   modals — which ship `hidden` and are only un-hidden by JS — painted on
 *   boot, stacked. Fix: a `.zsch-w [hidden]{display:none !important}` guard at
 *   the top of widget.css makes the `hidden` attribute authoritative. No JS or
 *   markup change; the modals now stay closed until opened.
 *
 * v1.0.1 — Interop with theme v2.24.0: when the theme ships the shared
 *   ZDZ_Core_Graph client (configured), the plugin now reads/writes Microsoft
 *   365 *through* it (one token cache, one client on the org's single Azure AD
 *   app registration) per contract §1.4 — falling back to the plugin's own
 *   client when run on an older theme. The default time zone also prefers the
 *   theme's central setting (ZDZ_Core_Settings::get_graph_default_tz) so both
 *   agree. No DB or signature changes.
 *
 * ============================================================================
 * PROGRAMMER NOTES
 * ============================================================================
 * ROLE: Bootstrap. Constants, schema activation, class loading, theme hook,
 *       dock-tile registration, full-page route.
 *
 * WHAT IT DOES:
 * A calendar surface on the Zorderz dashboard. It works entirely on its own WP
 * tables (LOCAL-FIRST) and lets people:
 *   1. PERSONAL  — add / edit their own appointments.
 *   2. AVAILABILITY — paint the dates/times they're open or booked, so the
 *      team can see who's free for a job ("mark me open Mon–Wed").
 *   3. SHARED — a single team calendar everyone can see, colour-coded by
 *      person, for jobs and shared resources.
 *
 * TWO CALENDAR-SYNC MODES (both OPTIONAL; nothing ships configured):
 *
 *   • CONNECTED CALENDARS (the universal, primary mode). Each user connects
 *     their OWN Google and/or Microsoft (Exchange) account via per-user
 *     delegated OAuth and picks which of their calendars count as "busy". This
 *     is provider-agnostic and needs no org-wide admin — it is the mode a fresh
 *     tenant should use. Delegated (user) consent, encrypted per-account token
 *     vault; feature-flagged via option `zsch_connected_cals` (default 'no'),
 *     enabled once an admin pastes a Google and/or Microsoft delegated client
 *     id/secret. See includes/class-zsch-{vault,connections,oauth,google,
 *     graph-delegated}.php and README.md → "Connect your calendar".
 *
 *   • MODE A — org-wide Microsoft 365 app (optional, single-tenant only). Where
 *     an entire team is in ONE Microsoft 365 tenant, an admin may register ONE
 *     Azure AD app with the *application* permission `Calendars.ReadWrite`
 *     (admin-consented once) so the platform can two-way sync every mailbox by
 *     its address WITHOUT each user connecting. This is a convenience for that
 *     specific topology, NOT an assumption the platform makes. The tenant id,
 *     client id, client secret and per-user mailbox all ship EMPTY and are read
 *     from settings / a Connections binding — never hardcoded. When a second app
 *     needs Graph, promote class-zsch-graph.php to a theme ZDZ_Core_Graph behind
 *     the same signatures. See README.md → "Mode A (org-wide Azure setup)".
 *
 * Until either mode is configured the Graph/OAuth surfaces are safe no-ops and
 * the calendar runs local-first — it never errors because sync is absent.
 *
 * INTEROP (orchestrator):
 * Conforms to the orchestrator interop contract. Ships ZSCH_TSA_Bridge with
 * read verbs (availability.lookup, schedule.lookup) + an action verb
 * (appointment.create, preview-and-confirm) so the operator bot can answer
 * "is a teammate free Thursday?" and "book me 2pm Tuesday". Tier/kiosk enforced
 * in the bridge, never in the model. Reads contacts via the theme's shared
 * clients where needed; owns its own WP tables for schedule data.
 *
 * APPOINTMENT STATES: the appointment/availability state values
 * ('confirmed'/'tentative'/'cancelled', 'open'/'busy') are the app's OWN for
 * now. When the Core Flow service lands, these bind to a tenant-declared state
 * machine via a documented Flow hook; until then they stay in-app (no competing
 * taxonomy is invented).
 *
 * DB TABLES (see db/migrate-1.0.0.php and db/migrate-1.6.0.php):
 *   wp_zsch_appointments        — events (personal + shared), local source of truth
 *   wp_zsch_availability         — free/busy blocks a user paints
 *   wp_zsch_graph_map            — local appointment id ↔ Graph event id + etag
 *   wp_zsch_settings             — per-install Graph config + sync cursors (option-backed helper)
 *   wp_zsch_calendar_accounts    — per-user connected accounts (encrypted token vault)
 *   wp_zsch_calendar_feeds       — chosen conflict calendars per account
 *   wp_zsch_external_events      — normalized external busy mirror (Phase 1 fills it)
 *
 * SELF-CONTAINED:
 * The Microsoft Graph client is the plugin's own (there is no shared
 * ZDZ_Core_Graph yet). It is the FIRST Graph consumer; when a second app needs
 * Graph, promote class-zsch-graph.php to a theme ZDZ_Core_Graph and migrate
 * behind these method signatures (contract §1.4). Likewise the v1.6.0 token
 * vault is deliberately scheduler-scoped; factor it up into a theme service
 * only when a second per-user-OAuth consumer appears (audit "consistency
 * watch" advice).
 *
 * CUSTOMER-FACING MODE:
 * If the analytics app's customer-facing mode is active for the current user,
 * the tile and widget hide entirely (never a visible refusal) — same contract
 * as the other apps in the bundle.
 * ============================================================================
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ──────────────────────────────────────────────────────
define( 'ZSCH_VERSION', '1.7.1' );
define( 'ZSCH_PLUGIN_FILE', __FILE__ );
define( 'ZSCH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZSCH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ZSCH_NONCE', 'zsch_nonce' );

// The dock-tile / app id used by the theme's springboard + role grants.
define( 'ZSCH_APP_ID', 'scheduler' );

/**
 * Roles that may use the scheduler.
 *
 * Everyone with a real seat gets it (they all have an Outlook calendar). The
 * shared kiosk `zdz_general` is given READ-ONLY access (it can see the shared
 * team calendar and who's available, but cannot create/edit/delete or sync —
 * every write path refuses it, server-side, via zsch_user_can_write()).
 *
 * Filterable so adding a role later needs no code edit.
 *
 * @return string[] lowercase role slugs
 */
function zsch_roles() {
	return (array) apply_filters( 'zsch_roles', array(
		'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_mfg', 'zdz_tech', 'zdz_general',
	) );
}

/**
 * Roles that get *read-only* access (the shared kiosk).
 *
 * @return string[]
 */
function zsch_read_only_roles() {
	return (array) apply_filters( 'zsch_read_only_roles', array( 'zdz_general' ) );
}

/**
 * Is this user one of the read-only roles (e.g. the shared kiosk)?
 *
 * @param int|null $user_id Defaults to current user.
 * @return bool
 */
function zsch_user_is_read_only( $user_id = null ) {
	$user_id = ( $user_id && (int) $user_id > 0 ) ? (int) $user_id : get_current_user_id();
	if ( $user_id <= 0 ) {
		return false;
	}
	$u = get_userdata( $user_id );
	if ( ! $u ) {
		return false;
	}
	$ro = array_map( 'strtolower', zsch_read_only_roles() );
	foreach ( (array) $u->roles as $role ) {
		if ( in_array( strtolower( (string) $role ), $ro, true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * May this user perform write actions (create/edit/delete/sync)?
 * Inverse of zsch_user_is_read_only().
 *
 * @param int|null $user_id
 * @return bool
 */
function zsch_user_can_write( $user_id = null ) {
	return ! zsch_user_is_read_only( $user_id );
}

/**
 * Does the user have access to the scheduler?
 *
 * Dual capability check (matches TSIM/TSA): WordPress admin (manage_options)
 * OR Zorderz custom (zdz_access_app). Read-only roles are admitted here
 * too (write paths are blocked separately).
 *
 * @param int|null $user_id
 * @return bool
 */
function zsch_user_has_access( $user_id = null ) {
	if ( zsch_user_is_read_only( $user_id ) ) {
		return true;
	}
	if ( $user_id && (int) $user_id > 0 ) {
		return user_can( (int) $user_id, 'manage_options' )
		    || user_can( (int) $user_id, 'zdz_access_app' );
	}
	return current_user_can( 'manage_options' )
	    || current_user_can( 'zdz_access_app' );
}

// ── Activation ─────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'zsch_activate' );

function zsch_activate() {
	require_once ZSCH_PLUGIN_DIR . 'db/migrate-1.0.0.php';
	ZSCH_Migrate_1_0_0::run();
	// v1.6.0 — Connected Calendars tables (additive, idempotent).
	require_once ZSCH_PLUGIN_DIR . 'db/migrate-1.6.0.php';
	ZSCH_Migrate_1_6_0::run();

	// Make the tile visible to every eligible user on first install. The theme
	// gates tile visibility behind per-user `zdz_allowed_apps` meta for
	// non-admin roles; without this grant, sales/operator/mfg/tech/general
	// users could activate the plugin and never see the "Schedule" tile.
	zsch_grant_tile_to_all_eligible_users();

	// Sync cron — pulls Graph changes for connected mailboxes every 5 minutes.
	if ( ! wp_next_scheduled( 'zsch_cron_sync' ) ) {
		wp_schedule_event( time() + 120, 'zsch_every_five_minutes', 'zsch_cron_sync' );
	}

	update_option( 'zsch_db_version', ZSCH_VERSION );
}

/**
 * Grant the scheduler tile to every user with `zdz_access_app` (unless
 * explicitly denied). Idempotent. Admin-class roles bypass the allowed-apps
 * meta in the theme, so we skip them.
 */
function zsch_grant_tile_to_all_eligible_users() {
	$users = get_users( array(
		'fields'     => array( 'ID' ),
		'capability' => 'zdz_access_app',
		'number'     => -1,
	) );
	foreach ( $users as $u ) {
		zsch_grant_tile_to_user( (int) $u->ID );
	}
}

/**
 * Grant to one user. Hooked on wp_login so users created/promoted after the
 * scheduler was activated also get the tile.
 */
function zsch_grant_tile_to_user( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return;
	}
	if ( ! zsch_user_has_access( $user_id ) ) {
		return;
	}

	$denied = get_user_meta( $user_id, 'zdz_denied_apps', true );
	if ( is_array( $denied ) && in_array( ZSCH_APP_ID, $denied, true ) ) {
		return; // Admin explicitly denied — respect it.
	}
	$allowed = get_user_meta( $user_id, 'zdz_allowed_apps', true );
	if ( ! is_array( $allowed ) ) {
		$allowed = array();
	}
	if ( ! in_array( ZSCH_APP_ID, $allowed, true ) ) {
		$allowed[] = ZSCH_APP_ID;
		update_user_meta( $user_id, 'zdz_allowed_apps', $allowed );
	}
}

// On login, top up the grant for any user who doesn't yet have it.
add_action( 'wp_login', function ( $user_login, $user ) {
	if ( $user instanceof WP_User ) {
		zsch_grant_tile_to_user( $user->ID );
	}
}, 10, 2 );

// Custom 5-minute cadence for the Graph sync puller.
add_filter( 'cron_schedules', function ( $schedules ) {
	if ( empty( $schedules['zsch_every_five_minutes'] ) ) {
		$schedules['zsch_every_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			// Plain string, NOT __(): the cron_schedules filter fires before
			// `init`, so a translation call here trips WP 6.7+'s
			// "_load_textdomain_just_in_time" notice (which can corrupt the
			// TSA chat's AJAX JSON). Admin-only label; no early i18n needed.
			'display'  => 'Every 5 minutes (TS Scheduler)',
		);
	}
	return $schedules;
} );

// ── Deactivation ───────────────────────────────────────────────────
register_deactivation_hook( __FILE__, 'zsch_deactivate' );

function zsch_deactivate() {
	// Deactivating preserves data — do NOT drop tables.
	wp_clear_scheduled_hook( 'zsch_cron_sync' );
}

// ── DB upgrade on version bump + SELF-HEALING table check ──────────
add_action( 'plugins_loaded', 'zsch_maybe_upgrade', 5 );

/**
 * Ensure the plugin's tables exist and are current.
 *
 * Runs the migration when EITHER the stored db version is behind, OR the tables
 * are physically missing. The second condition is the important one: when the
 * plugin is updated by OVERWRITING the folder (drag-and-drop, SFTP, or some
 * "upload zip / replace" flows), WordPress does NOT fire register_activation_hook
 * — so a first-time install done that way would never create the tables, and
 * every insert would fail ("Database insert failed."). Checking for the tables
 * on load makes the schema self-heal no matter how the files arrived.
 *
 * dbDelta (inside the migrations) is idempotent, so re-running is safe.
 */
function zsch_maybe_upgrade() {
	$db_ver       = get_option( 'zsch_db_version', '0' );
	$needs_by_ver = version_compare( $db_ver, ZSCH_VERSION, '<' );
	$needs_tables = ! zsch_tables_exist();

	if ( $needs_by_ver || $needs_tables ) {
		require_once ZSCH_PLUGIN_DIR . 'db/migrate-1.0.0.php';
		ZSCH_Migrate_1_0_0::run();
		// v1.6.0 — Connected Calendars tables ride the same self-heal.
		require_once ZSCH_PLUGIN_DIR . 'db/migrate-1.6.0.php';
		ZSCH_Migrate_1_6_0::run();
		update_option( 'zsch_db_version', ZSCH_VERSION );

		if ( $needs_tables && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'TS Scheduler: tables were missing — ran schema migration on load (likely a file-overwrite update).' );
		}
	}
}

/**
 * Do all scheduler tables exist?
 *
 * @return bool
 */
function zsch_tables_exist() {
	global $wpdb;
	$need = array(
		$wpdb->prefix . 'zsch_appointments',
		$wpdb->prefix . 'zsch_availability',
		$wpdb->prefix . 'zsch_graph_map',
		// v1.6.0 — Connected Calendars.
		$wpdb->prefix . 'zsch_calendar_accounts',
		$wpdb->prefix . 'zsch_calendar_feeds',
		$wpdb->prefix . 'zsch_external_events',
	);
	foreach ( $need as $t ) {
		// $wpdb->get_var with SHOW TABLES LIKE returns the table name if present.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
		if ( $found !== $t ) {
			return false;
		}
	}
	return true;
}

// ── Load all class files ───────────────────────────────────────────
add_action( 'plugins_loaded', 'zsch_load_includes' );

function zsch_load_includes() {
	$dir = ZSCH_PLUGIN_DIR . 'includes/';
	foreach ( glob( $dir . '*.php' ) as $file ) {
		require_once $file;
	}

	// v1.1.0 — VERSION BEACON. Log the running version ONCE per hour (throttled
	// so it doesn't spam). This makes it trivial to confirm from debug.log exactly
	// which build is live — critical when a stale cache/install leaves an old
	// version running after an update. Grep the log for "TS Scheduler ACTIVE".
	if ( false === get_transient( 'zsch_version_beacon' ) ) {
		set_transient( 'zsch_version_beacon', 1, HOUR_IN_SECONDS );
		error_log( 'TS Scheduler ACTIVE — version ' . ZSCH_VERSION . ' (file: ' . __FILE__ . ')' );
	}

	// ── v1.1.2: Register capabilities with the (future) orchestrator registry. ──
	// CONTRACT §2.3 / §6 L4. The bridge already shapes its verbs in
	// ZSCH_TSA_Bridge::get_capability_descriptor() (availability.lookup,
	// schedule.lookup, appointment.create) with honest kiosk/side_effect flags;
	// this just publishes them via the `zdz_register_capabilities` filter. SAFE TO
	// SHIP NOW: until the central resolver (ZDZ_Capabilities::invoke) exists the
	// filter merely adds rows nobody reads; when it lands, the Scheduler is L4-
	// native with zero further code. We pull straight from the descriptor so the
	// registration can never drift from the bridge's own declared posture.
	// (Mirrors TS Sales Leads' registration pattern.)
	//
	// HOST hand-off (not owned here): the [ZSCH_AVAIL]/[ZSCH_SCHED]/[ZSCH_BOOK]
	// marker handlers in TSA's engine + the bot's RULE ZZ kiosk line live with the
	// TSA maintainer per CONTRACT §2.2; this plugin ships everything that is ZSCH's.
	add_filter( 'zdz_register_capabilities', function ( $caps ) {
		if ( class_exists( 'ZSCH_TSA_Bridge' )
			&& method_exists( 'ZSCH_TSA_Bridge', 'get_capability_descriptor' ) ) {
			foreach ( ZSCH_TSA_Bridge::get_capability_descriptor() as $verb => $descriptor ) {
				$caps[ $verb ] = $descriptor;
			}
		}
		return $caps;
	} );

	// Wire the sync cron callback once classes are loaded.
	add_action( 'zsch_cron_sync', array( 'ZSCH_Graph', 'cron_sync_all' ) );

	// v1.7.0 — Connected Calendars Phase 1: pull each user's external conflict
	// feeds into the busy mirror on the SAME 5-minute tick. Self-gated by
	// ZSCH_OAuth::feature_enabled() (no-op with the flag down) and self-locking,
	// so it can share the hook with the app-level Graph sync above safely.
	if ( class_exists( 'ZSCH_Sync' ) ) {
		add_action( 'zsch_cron_sync', array( 'ZSCH_Sync', 'cron_all' ) );
	}

	// v1.7.1 — SC1.1: register a per-user "Connected Calendars" card in the
	// theme's Settings → App Authorizations section. Theme ts-theme-2 v2.38.1+
	// runs its app-authorizations payload through the `zdz_app_authorizations`
	// filter; on older themes the filter never fires (clean no-op). Gated
	// exactly like the widget card — feature ON and a write-capable (non-kiosk)
	// user — so the read-only kiosk never gets an entry (INV-Kiosk).
	add_filter( 'zdz_app_authorizations', function ( $data, $user_id ) {
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		if ( ! class_exists( 'ZSCH_OAuth' ) || ! ZSCH_OAuth::feature_enabled() ) {
			return $data;
		}
		if ( function_exists( 'zsch_user_is_read_only' ) && zsch_user_is_read_only( (int) $user_id ) ) {
			return $data;
		}
		$accounts = class_exists( 'ZSCH_Connections' )
			? ZSCH_Connections::list_for_user( (int) $user_id )
			: array();
		$data['calendars'] = array(
			'connected' => ! empty( $accounts ),
			'count'     => count( (array) $accounts ),
		);
		return $data;
	}, 10, 2 );

	// Boot the AJAX router + admin page.
	if ( class_exists( 'ZSCH_Dashboard' ) ) {
		new ZSCH_Dashboard();
	}
	if ( is_admin() && class_exists( 'ZSCH_Admin' ) ) {
		new ZSCH_Admin();
	}

	// v1.6.0 — Connected Calendars OAuth routes (start/callback). The class
	// no-ops on every request unless the feature flag + provider config are
	// live; registering the hook is free. Runtime-guarded (no theme symbols
	// referenced at load time — the ZDZ_Share_Link dependency is checked inside
	// the handlers).
	if ( class_exists( 'ZSCH_OAuth' ) ) {
		ZSCH_OAuth::register();
	}

	// Full-page route — ?zsch_page=1 renders the calendar full-viewport. Kept
	// so the inline widget's "open full screen" affordance and any deep-link
	// still work, but it is NOT advertised anywhere in the nav.
	add_action( 'template_redirect', 'zsch_maybe_render_full_page' );

	// NOTE (v1.0.3): The bottom-nav tab injector is intentionally DISABLED.
	// The Scheduler is a dashboard-WIDGET-only app — it must not add a tab to the
	// bottom nav (nor a dock/sidebar tile; see springboard:false in the tile
	// config). The injector function remains below but is no longer hooked.
	// add_action( 'wp_enqueue_scripts', 'zsch_enqueue_nav_inject' );

	// v1.0.5: keep page optimizers from deferring/combining our widget script.
	add_filter( 'script_loader_tag', 'zsch_mark_no_optimize', 10, 2 );
}

/**
 * Add no-defer / no-optimize attributes to the scheduler widget <script> tags.
 *
 * NitroPack, Autoptimize, WP Rocket, SG Optimizer and Cloudflare Rocket Loader
 * all transform or defer third-party scripts by default. When the dashboard
 * shell is served from a stale cache, a deferred/combined widget.js is a common
 * reason the calendar JS doesn't run (the grid then sits empty). These flags ask
 * every common optimizer to leave these scripts untouched.
 *
 * @param string $tag    The full <script> HTML.
 * @param string $handle The script handle.
 * @return string
 */
function zsch_mark_no_optimize( $tag, $handle ) {
	// v1.6.0: the Connected Calendars bundle gets the same protection.
	if ( ! in_array( $handle, array( 'zsch-widget-js', 'zsch-connections-js' ), true ) ) {
		return $tag;
	}
	// Insert the opt-out attributes right after "<script ".
	$attrs = 'data-no-optimize="1" data-no-defer="1" data-cfasync="false" data-nitro-exclude ';
	if ( false !== strpos( $tag, '<script ' ) && false === strpos( $tag, 'data-no-optimize' ) ) {
		$tag = preg_replace( '/<script\s+/', '<script ' . $attrs, $tag, 1 );
	}
	return $tag;
}

function zsch_enqueue_nav_inject() {
	if ( is_admin() || ! is_user_logged_in() ) {
		return;
	}
	if ( ! empty( $_GET['zsch_page'] ) ) {
		return; // don't inject into ourselves
	}
	if ( ! function_exists( 'zsch_user_has_access' ) || ! zsch_user_has_access() ) {
		return;
	}

	wp_enqueue_style(
		'zsch-nav-inject',
		ZSCH_PLUGIN_URL . 'assets/css/nav-inject.css',
		array(),
		ZSCH_VERSION
	);
	wp_enqueue_script(
		'zsch-nav-inject',
		ZSCH_PLUGIN_URL . 'assets/js/nav-inject.js',
		array(),
		ZSCH_VERSION,
		true
	);
	wp_localize_script( 'zsch-nav-inject', 'zschNavData', array(
		'pageUrl' => home_url( '/?zsch_page=1' ),
	) );
}

/**
 * Register REST routes (cross-plugin coordination + the widget's own data API).
 * Runs only on REST requests — zero cost on other page loads.
 */
add_action( 'rest_api_init', function () {
	if ( class_exists( 'ZSCH_REST' ) ) {
		ZSCH_REST::register_routes();
	}
} );

/**
 * Full-page view handler — /?zsch_page=1 opens the calendar full-viewport
 * (theme bridge iframes this). Applies the same customer-facing hide as the
 * inline widget.
 */
function zsch_maybe_render_full_page() {
	if ( empty( $_GET['zsch_page'] ) ) {
		return;
	}
	if ( ! is_user_logged_in() || ! zsch_user_has_access() ) {
		zsch_render_unavailable();
		exit;
	}
	if ( class_exists( 'ZSCH_Widget' ) && ! ZSCH_Widget::should_render() ) {
		zsch_render_unavailable();
		exit;
	}

	$widget = new ZSCH_Widget();
	status_header( 200 );
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );

	$html = $widget->render_dashboard_widget( get_current_user_id(), 'fullpage' );
	if ( null === $html ) {
		zsch_render_unavailable();
		exit;
	}

	$is_embed = ! empty( $_GET['zdz_embed'] );

	echo '<!doctype html><html data-theme="system"><head><meta charset="utf-8">';
	echo '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">';
	echo '<title>Schedule</title>';
	// Theme-sync before any CSS paint, no FOUC.
	echo '<script>(function(){try{var t=localStorage.getItem("zdz_theme");if(t){document.documentElement.setAttribute("data-theme",t);}}catch(e){}})();</script>';
	if ( $is_embed ) {
		echo '<style>';
		echo "html,body{margin:0;padding:0;height:100%;overflow:hidden;background:var(--sys-bg,transparent);";
		echo "font-family:'Inter',system-ui,-apple-system,sans-serif;-webkit-font-smoothing:antialiased;color:var(--sys-text,#e2e8f0);}";
		echo '.zsch-fullpage .zsch-w{height:100vh;max-height:100vh;border:0;border-radius:0;}';
		echo '</style>';
		$theme_css_url = get_stylesheet_uri();
		if ( $theme_css_url ) {
			echo '<link rel="stylesheet" href="' . esc_url( $theme_css_url ) . '">';
		}
		wp_print_styles( array( 'zsch-widget-css' ) );
		wp_print_scripts( array( 'zsch-widget-js' ) );
	} else {
		wp_head();
	}
	echo '</head><body class="zsch-fullpage' . ( $is_embed ? ' zsch-embed' : '' ) . '">';
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	if ( ! $is_embed ) {
		wp_footer();
	}
	echo '</body></html>';
	exit;
}

/**
 * Render the theme's "not available" fallback. 200, not 403 — never leak
 * existence (matches TSIM).
 */
function zsch_render_unavailable() {
	status_header( 200 );
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><html><head><meta charset="utf-8"><title>Not available</title>';
	echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
	echo '<style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;color:#64748b;background:#f8fafc}p{max-width:28ch;text-align:center}</style>';
	echo '</head><body><p>This feature isn\'t available right now.</p></body></html>';
}

/**
 * ────────────────────────────────────────────────────────
 * ZORDERZ THEME INTEGRATION — Tier 2 inline widget + Tier 1 iframe.
 * MUST run inside after_setup_theme (theme interfaces aren't defined earlier).
 * ────────────────────────────────────────────────────────
 */
add_action( 'after_setup_theme', function () {

	if ( ! class_exists( 'ZSCH_Widget' ) ) {
		return; // classes load on plugins_loaded(5), before this — defensive.
	}

	// Shared tile config used by both interface tiers.
	//
	// springboard => false : the Scheduler is a DASHBOARD-WIDGET-ONLY app. It must
	// NOT appear as its own dock tile, left-sidebar entry, recent chip, or search
	// result — only the inline widget on the dashboard. The theme's app-list
	// builders (getVisibleApps / dock / sticky bar / recents) all skip apps with
	// springboard:false via isSecondarySurface(), while renderWidgets() still
	// renders the inline widget (it keys only on bridge_type:'inline_widget' +
	// widget_html). Same pattern the Media library uses for its full-screen
	// surface. (Requires theme v2.21.0+; on older themes the key is simply
	// ignored and the tile shows as before — harmless.)
	$config = array(
		'id'          => ZSCH_APP_ID,
		'nm'          => 'Schedule',
		'icon'        => 'calendar',
		'cat'         => 'Team',
		'cc'          => '#0EA5E9',
		'desc'        => 'Appointments, team availability & shared calendar — synced with Outlook.',
		'roles'       => zsch_roles(),
		// v1.3.0: Scheduler now gets a real app ICON like every other app —
		// 'springboard' => true means it appears in the top quick-link dock, the
		// Apps grid, recents, and search. Because bridge_type is 'inline_widget'
		// (set per tier below), TAPPING the icon JUMPS to the Schedule widget on
		// the dashboard (it does NOT open a separate full-screen page) — exactly
		// how the other inline apps behave. Previously this was springboard:false
		// (widget-only, no icon), which the field found un-discoverable.
		'springboard' => true,
		'admin_url'   => home_url( '/?zsch_page=1' ),
	);

	// ── TIER 2 — inline dashboard widget (theme v2.0+) ──
	if ( interface_exists( '\Zorderz\Widget_App_Interface' ) ) {

		class ZSCH_App implements \Zorderz\Widget_App_Interface {

			private $cfg;

			public function __construct( array $cfg ) {
				$this->cfg = $cfg;
				$this->cfg['bridge_type'] = 'inline_widget';
			}

			public function get_config(): array {
				return $this->cfg;
			}

			public function render_mobile_view( int $user_id ): void {
				echo '<iframe src="' . esc_url( home_url( '/?zsch_page=1&zdz_mobile=1' ) ) . '" style="width:100%;height:100%;border:none;" title="Schedule"></iframe>';
			}

			public function render_dashboard_widget( int $user_id ): ?string {
				if ( ! ZSCH_Widget::should_render() ) {
					return null;
				}
				$widget = new ZSCH_Widget();
				return $widget->render_dashboard_widget( $user_id, 'inline' );
			}
		}

		add_filter( 'zdz_register_apps', function ( $apps ) use ( $config ) {
			$apps[ ZSCH_APP_ID ] = new ZSCH_App( $config );
			return $apps;
		} );

	// ── TIER 1 — standard iframe tile (theme v1.x) ──
	} elseif ( interface_exists( '\Zorderz\App_Interface' ) ) {

		class ZSCH_App implements \Zorderz\App_Interface {

			private $cfg;

			public function __construct( array $cfg ) {
				$this->cfg = $cfg;
				$this->cfg['bridge_type'] = 'iframe';
			}

			public function get_config(): array {
				return $this->cfg;
			}

			public function render_mobile_view( int $user_id ): void {
				echo '<iframe src="' . esc_url( home_url( '/?zsch_page=1&zdz_mobile=1' ) ) . '" style="width:100%;height:100%;border:none;" title="Schedule"></iframe>';
			}
		}

		add_filter( 'zdz_register_apps', function ( $apps ) use ( $config ) {
			$apps[ ZSCH_APP_ID ] = new ZSCH_App( $config );
			return $apps;
		} );
	}
} );

/**
 * Declare this module's legacy→current rename map to the platform migration.
 *
 * Plugins DECLARE; the theme's ZDZ_Rename_Migration performs the table renames,
 * option-key moves, user-meta moves and cron-hook renames in one place. This is
 * what lets a legacy install (tssch_* / TSSCH_*) upgrade cleanly in
 * place to the zsch_* names. A fresh Zorderz install has no legacy rows, so every
 * entry simply no-ops. Data itself is never seeded here — only renamed if present.
 */
add_filter( 'zdz_rename_map', function ( $map ) {
	$map['tables'] = array_merge( $map['tables'] ?? array(), array(
		'tssch_appointments'      => 'zsch_appointments',
		'tssch_availability'      => 'zsch_availability',
		'tssch_graph_map'         => 'zsch_graph_map',
		'tssch_calendar_accounts' => 'zsch_calendar_accounts',
		'tssch_calendar_feeds'    => 'zsch_calendar_feeds',
		'tssch_external_events'   => 'zsch_external_events',
	) );
	$map['options'] = array_merge( $map['options'] ?? array(), array(
		'tssch_db_version'          => 'zsch_db_version',
		'tssch_graph_config'        => 'zsch_graph_config',
		'tssch_graph_secret'        => 'zsch_graph_secret',
		'tssch_graph_token'         => 'zsch_graph_token',
		'tssch_conncal_config'      => 'zsch_conncal_config',
		'tssch_google_secret'       => 'zsch_google_secret',
		'tssch_ms_delegated_secret' => 'zsch_ms_delegated_secret',
		'tssch_connected_cals'      => 'zsch_connected_cals',
		'tssch_views_v2'            => 'zsch_views_v2',
	) );
	$map['user_meta'] = array_merge( $map['user_meta'] ?? array(), array(
		'tssch_mailbox' => 'zsch_mailbox',
	) );
	$map['cron'] = array_merge( $map['cron'] ?? array(), array(
		'tssch_cron_sync' => 'zsch_cron_sync',
	) );
	return $map;
} );
