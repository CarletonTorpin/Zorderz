# TS Internal Messaging

Internal team messaging for the Zorderz Field OS ecosystem.

A WordPress plugin that provides real-time-ish channels and 1:1 direct messages between Zorderz operators, admins, sales, manufacturing, and tech roles. Renders as an inline dashboard widget inside the Zorderz theme; a full-page view is available at `?zim_page=1`.

**Version:** 1.0.18
**Slug:** `zdz-internal-messaging`
**Namespace:** `ZIM_`

---

## Requirements

- Zorderz theme **2.13.1 or later** (builds against the `\Zorderz\Widget_App_Interface` exported since theme 2.0; falls back to the older `App_Interface` on older theme branches).
- WordPress **6.0+**
- PHP **8.0+** (matches the theme's floor; tested through 8.3). Requires `openssl` and `hash_hkdf` (both in PHP core).
- MySQL **5.7+** or MariaDB **10.2+** (FULLTEXT search falls back to LIKE if the FT index can't be created).

### Optional but recommended

- **`zdz-sales-analytics` v1.12.2+** — enables rich FreshBooks `#NNNNN` preview cards (via the `/zorderz/v1/freshbooks-preview/{id}` REST endpoint) and the coordinated customer-facing hard-block (via `TSA_Customer_Facing::is_active_for_user()`). Without TSA, `#NNNNN` references still link out to FreshBooks search; preview metadata is just unavailable.
- **`zdz-events-relay` (TSER) for HEIC image support** — when present, HEIC uploads are converted server-side to JPEG via `TSER_Heic::convert_to_jpeg()`. Without TSER, HEIC is accepted only on hosts that can handle it via ImageMagick.

---

## Install

1. Drop `zdz-internal-messaging/` into `wp-content/plugins/`.
2. Activate. On activation:
	- Creates 7 tables (prefix `wp_zim_*`).
	- Seeds 5 default channels: `#announcements`, `#sales`, `#ops`, `#mfg`, `#techs`.
	- Auto-joins every existing `zdz_access_app` user to `#announcements` and to the channel matching their primary role.
	- Schedules 3 cron jobs:
		- `zim_dispatch_notifications` — every minute (quiet-hours release)
		- `zim_purge_attachments` — daily (orphans + soft-deleted attachments ≥30 days old)
		- `zim_rotate_vapid_if_due` — daily (rotates push keys every 90 days)
	- Generates a VAPID keypair for Web Push.
3. Grant desired users the `zdz_access_app` capability via the Zorderz theme's role editor (or via CLI / `user_can` directly). All `zdz_owner`, `zdz_admin`, `zdz_sales`, `zdz_operator`, `zdz_mfg`, `zdz_tech` roles in the Zorderz ecosystem normally already have this capability.

### Deactivate / uninstall

Deactivation stops crons but leaves data intact. Uninstall (via WordPress → Plugins → Delete) removes tables, options, user meta, and unbinds push subscriptions — **including message history**. Back up `wp_zim_messages` if you need to preserve it.

---

## Architecture highlights

- **Polling over WebSockets** (Trap 1) — 3-second HTTP polls, max 2 concurrent per tab. 50 messages per poll cap. Scales to hundreds of concurrent users on a normal shared-host PHP/MySQL stack; WebSockets are the v2 roadmap.
- **Unified `wp_zim_conversations` table** — one ID space for both channels (`kind='channel'`) and DMs (`kind='dm'`). DMs are deterministic: `UNIQUE KEY (user_a, user_b)` with `user_a < user_b` always. There can only ever be one DM between any two users.
- **Soft-delete preserves audit trail** — deletes blank the body, mark `deleted_at`, and keep mention rows so the user's inbox history is intact. Attachments on deleted messages are physically purged 30 days later by cron.
- **Web Push is self-contained** (Trap 2) — no Minishlink/WebPush or other external SDK. `openssl` + `hash_hkdf` implement RFC 8291 (aes128gcm) and ES256 VAPID JWTs directly. Keys rotate every 90 days.
- **Customer-facing hard-block at PHP render time** (Trap 5) — when the analytics app's customer-facing mode is active for the current user, the widget renders nothing at all and every AJAX endpoint returns **404, not 403**, so the plugin's existence is not leaked to a screen a customer might see.

See `patches/tsa-v1.11.4-preview-endpoint.md` for the TSA coordination contract.

---

## Configuration

Almost nothing to configure. Sensible defaults out of the box:

| Setting                      | Default       | Option key / constant                   |
|------------------------------|---------------|------------------------------------------|
| Edit window                  | 5 minutes     | `ZIM_EDIT_WINDOW_SECONDS`               |
| Attachment size cap          | 5 MB          | `ZIM_MAX_UPLOAD_BYTES`                  |
| Allowed MIME types           | jpeg/png/webp/heic/pdf | `ZIM_ALLOWED_MIMES` (JSON)      |
| Quiet hours (per-user)       | 21:00 – 07:00 | user meta `zim_quiet_hours_{start,end}` |
| Poll interval                | 3000 ms       | hardcoded (JS + server)                  |
| Messages per poll            | 50            | hardcoded                                |
| VAPID key rotation           | 90 days       | `ZIM_PUSH_ROTATION_DAYS`                |
| Email digest per-convo cooldown | 30 minutes | `ZIM_EMAIL_DIGEST_COOLDOWN_SECONDS`     |

Users change their own quiet hours from the widget's ⚙︎ menu.

---

## Admin UI

`WP Admin → Messaging` (visible to `manage_options`):

- **Channels**: list all channels; create new; click through to manage members.
- **Audit log**: CSV export of plugin admin actions (channel creation, member add/remove). Regular message traffic is **not** logged — too voluminous to be actionable.

Regular users use only the inline widget.

---

## Traps we followed (MEP)

In order, with the line these live at in the prompt spec:

| #  | Trap                                                               | How we followed it |
|----|--------------------------------------------------------------------|--------------------|
| 1  | No WebSockets                                                      | HTTP polling, 2-in-flight ceiling |
| 2  | No external API clients                                            | Web Push done directly; FreshBooks proxied through TSA |
| 3  | Mention reconcile only notifies ADDED (edit doesn't re-notify)     | `ZIM_Mentions::reconcile()` diffs and returns `added`/`removed`; only `added` triggers push |
| 4  | Soft-delete preserves mention rows                                 | delete blanks body + stamps `deleted_at`; mention rows untouched |
| 5  | Customer-facing hide at PHP render time, not 403                   | `ZIM_Widget::should_render()` + all AJAX gates return 404 |
| 6  | Push subscription invalidation on logout                           | `clear_auth_cookie` action → deletes all push subs for the user |
| 7  | Quiet hours DEFER not DROP                                         | `ZIM_Notifications::compute_release_at()` computes the next out-of-quiet moment |
| 8  | Indexes declared at CREATE TABLE                                   | all in `db/migrate-1.0.0.php` |
| 9  | Uploads through `wp_handle_upload`                                 | `ZIM_Attachments::handle_upload()` with explicit `'mimes'` |

---

## Performance / scaling

- **Tables & indexes**: every hot query is covered. Message history scroll uses `idx_conv_created (conversation_id, created_at)`. Unread counts are `SELECT COUNT(*) ... WHERE conversation_id = ? AND id > last_read_message_id` — indexed covers.
- **Search**: FULLTEXT index on `wp_zim_messages.body` declared at create-time (named `idx_body_ft`). Falls back to `LIKE %q%` when FULLTEXT isn't available.
- **Growth threshold**: the base schema comfortably handles **1 million messages** on modest hardware. Past that, consider partitioning `wp_zim_messages` by `conversation_id` or archiving messages older than 1 year. Not needed at MEP scale.
- **Push send cost**: one HTTP request per subscription per notification. Browsers aggressively dedupe by `tag=zim-{conversation_id}`, so a user with 3 tabs open doesn't get 3 alerts.

---

## Security posture

- Every AJAX endpoint: `check_ajax_referer(ZIM_NONCE, 'nonce')` + `current_user_can('zdz_access_app')` + membership check + customer-facing gate. Admin endpoints additionally require `manage_options` or `zdz_owner`/`zdz_admin` role.
- No direct SQL concatenation — every query uses `$wpdb->prepare`.
- Output uses `esc_html`/`esc_attr`/`esc_url` at the boundary; client-side, `DOMPurify` sanitizes the marked.js HTML before insertion.
- Upload path uses `wp_handle_upload` + explicit MIME whitelist + size check.
- Push payloads are E2E encrypted per RFC 8291 (aes128gcm + ECDH). VAPID ES256 signatures are raw-R||S (not DER) per RFC 8292.

---

## Known limitations / Not in this release

Deliberately deferred to keep the MEP small and reviewable:

- **Group DMs** — only 1:1 DMs. Multi-user conversations require a channel.
- **Threaded replies** — one flat message stream per conversation.
- **Reactions / emoji**
- **Typing indicators**
- **Voice / video**
- **Slack or Teams bridges**
- **Message pinning / bookmarks**
- **Cross-conversation search** — per-conversation search only
- **Scheduled send**
- **Polls / forms inside messages**
- **Rich URL unfurls** — only FreshBooks `#NNNNN` refs get rich previews
- **Web Push UI for Safari < 16.4** — those users fall back to email digests
- **Real-time typing / presence** — polling does not expose presence
- **Message export** — admins can export audit log; full message export is v1.1

---

## Changelog

### 1.0.24 — 2026-05

General Account Hardening — messaging lockdown for the shared workshop kiosk.

Part of the platform-wide "General Account Hardening" effort. The shop iPad is logged into a single shared account (`general@…`, role `zdz_general`) that everyone touches, so it must carry the fewest privileges. On the messaging side that means one thing: **it can read `#announcements`, and it can send nothing — no channel posts, no DMs, no edits, deletes, uploads, channel creation, or conversation creation.** This release implements that as a role-derived, read-only access mode.

- **Added**: read-only role support. New helpers in the bootstrap — `zim_read_only_roles()` (filterable; defaults to `['zdz_general']`), `zim_user_is_read_only()`, and `zim_user_can_write()`. A single source of truth on the messaging side; future read-only roles need no code change, just the filter.
- **Changed**: `zim_user_has_access()` now admits read-only roles. The hardening role is defined with only `read` caps and deliberately does **not** carry `zdz_access_app`, yet it must still *read* announcements. Admitting it at the access gate (while every write path is independently blocked) is what makes "read the announcements, send nothing" possible without granting the broad app capability. It also lets `seed_defaults()` auto-join the kiosk to `#announcements` — and only `#announcements`, since the kiosk matches no other channel's role whitelist.
- **Added**: deterministic, server-side write blocks at every entry point —
  - **Model chokepoint**: `ZIM_Messages::post()` refuses read-only authors. Every write funnels through `post()` (AJAX `zim_post`, the REST `/post` route, any future caller), so no forgotten higher-layer gate can re-open a send path.
  - **Conversation creation**: `ZIM_DMs::get_or_create_conversation()` refuses a read-only initiator — no DMs, no self-DM "notes."
  - **AJAX**: a new `gate_write()` guards `zim_post`, `zim_edit`, `zim_delete`, `zim_bulk_delete`, `zim_upload`, and `zim_dm_open`; `gate_admin()` (channel-create, member-add) also refuses read-only roles.
  - **REST**: the `tsim/v1/post` route — the cross-plugin "post to #channel" surface Brain Bot uses — returns a clean `403 zim_read_only` for read-only users.
- **Security note**: this closes the messaging-side write back-door behind the Session 406 autonomous-posting incident. For the most-shared account the send capability is *removed*, not merely discouraged at the prompt layer — the structural fix the platform learned it needed.
- **Changed (UI)**: for the kiosk, the message composer is **not rendered into the DOM at all** (removed, not disabled). A muted read-only notice ("Read-only on this shared device…") shows in its place once a conversation is opened. The "New DM" affordance is hidden. `zimData.isReadOnly` drives client behavior: `sendMessage()`, `openNewDm()`, and the TSA-embed DM-route/auto-send are all short-circuited. (These are courtesy UX; the server blocks are the guarantee.)
- **Changed**: `zdz_general` added to the app-registration role lists so the Messages tile is visible on the workshop iPad.
- **No DB migration**: read-only behavior is entirely role-derived; existing `#announcements` auto-join already provides the kiosk's read access.

> **Scope note**: the other halves of the kiosk hardening (greeting alias, dashboard KPI suppression, ephemeral chat, EXIF media provenance, the Brain Bot kiosk prompt block, and the server-side TSIM-marker strip in the analytics response pipeline) live in the theme and `zdz-sales-analytics`. This release covers only the Internal Messaging plugin's part: read-only announcements with zero send capability.

### 1.0.18 — 2026-04

iPad UX hardening + icon system overhaul.

- **Changed**: all emoji icons (💬 ⚙︎ 📎 🔍 🔔 ➤ ☰ ✕ +) replaced with inline Lucide SVG icons throughout the widget. Emojis render inconsistently across platforms — different sizes on iOS vs Android, different weights on macOS vs Windows — and look out of place against the design system's Inter + token-based typography. The SVG icons are 20×20, stroke-based, and inherit `currentColor` so they respect all four themes automatically.
- **Changed**: inline dashboard widget height increased from 560px to 680px (desktop) and 480px to 560px (mobile). The previous height caused excessive vertical scrolling within the constrained tile, especially with image attachments or long conversations. The new height keeps the tile large enough to be useful without overwhelming the dashboard.
- **Fixed**: "Loading messages…" spinner could hang indefinitely if the initial `zim_fetch_before` AJAX call failed silently (network timeout, stalled connection, or 502 from the host). A 12-second hard timeout now replaces the spinner with a tap-to-retry prompt. The `.catch()` path on the fetch also shows a retry prompt instead of silently leaving the spinner up. Same pattern as the v1.0.16 iframe-loader timeout fallback.
- **Fixed**: scroll-back to load older messages was difficult on iPad. The scroll threshold was 60px — too tight for iOS momentum scrolling, which overshoots precise positions. Threshold raised to 150px. A compact spinner now appears at the top while older messages load (visual feedback that something is happening). When there are no more older messages, a "Beginning of conversation" marker appears instead of silently doing nothing.
- **Fixed**: on iPad (especially in iframe embed mode), the software keyboard could dismiss when the user scrolled or tapped outside the textarea, and then refuse to reopen on subsequent taps. This is a longstanding iOS WebKit focus-management bug inside iframes. Three mitigations added: (a) a `touchstart` handler on the composer area that re-focuses the textarea when the user taps back into it; (b) `touch-action: manipulation` + `-webkit-appearance: none` on the textarea to prevent iOS gesture conflicts; (c) a `visualViewport` resize listener that re-scrolls the message list to the bottom when the keyboard dismisses, preventing the "messages jumped and I lost my place" disorientation.
- **Changed**: back button redesigned with inline SVG chevron (was a CSS pseudo-element `‹` character) and larger 44px touch target per Apple HIG minimum. Hover/focus-visible state matches the rest of the icon button system. The button text reads "Messages" (was previously bare text with a character prefix) so it's clearly a navigation action, not a browser back button — addressing the user confusion reported when using the browser's own back/forward nav alongside the in-app back button.

> **Note**: the "Self-Check" feature mentioned on the board is a TS Sales Analytics (TSA) feature, not an Internal Messaging feature. Self-Check is the analytics app's AI self-review system (v1.13.1) that optionally validates its own answers before displaying them. It has no counterpart in TSIM.

### 1.0.17 — 2026-04

Polling efficiency.

- **Changed**: `zim_sidebar` and `zim_poll` (the inline widget's 3-second polls) and the bottom-nav `zim_sidebar` badge poll (every 45s) are now gated on the Page Visibility API. While `document.hidden` is true — background tab, minimized window, locked screen on most browsers — the timers are fully cleared rather than continuing to fire. When the tab returns to foreground each poll fires an immediate "catch-up" tick, then resumes its normal interval. For users who keep this site in a background tab, this drops their messaging-related admin-ajax traffic to zero until they look at it.
- **Changed**: the inline widget's two 3-second polls additionally check `getBoundingClientRect()` on the widget root each tick; if the rect is zero-area (the theme has hidden the containing sub-view via `display:none`) or sits entirely outside the viewport (the user scrolled past it), the network call is skipped. The `setInterval` keeps firing — the rect read costs microseconds — but no admin-ajax request leaves the browser. So polling now matches what the user can actually see.
- **Fixed**: switching the active conversation in the inline widget previously left the previous conversation's `visibilitychange` listener attached forever (one new listener per switch). Each old listener would race to restart its already-cleared timer on every tab refocus. The new `pollable()` helper exposes a `destroy()` method that clears the timer AND unsubscribes the listener, and `selectConversation()` + `hideAll()` use it. No more listener leak.

### 1.0.16 — 2026-04

Cross-plugin sub-view bleed + iframe loader hang.

- **Fixed**: `#sv-team` was rendering on every page (Settings, Apps, Records, Chat) because `nav-inject.css` declared `display: flex` on the bare ID selector, defeating the theme's `.sub-view { display: none }` via ID specificity. The Team skeleton and iframe were therefore visible across the whole SPA, manifesting as a "Loading Team messaging…" banner that never went away. All `#sv-team` rules in `nav-inject.css` are now gated on `.active`, so the theme's hidden state wins for every other view and our layout overrides only apply when the user is actually on the Team tab. Theme v2.14.0+ also enforces `.sub-view:not(.active) { display:none !important }` as a safety net; the two fixes are complementary.
- **Fixed**: the iframe loader had no timeout fallback. If the embed iframe failed to fire its `load` event (network stall, blocked redirect, push-permission prompt the user ignored), the skeleton stayed visible indefinitely. Added an 8-second hard fallback that reveals the iframe regardless. Both the `load` event and the timeout funnel through a single `reveal()` function with a `revealed` flag, so they can't double-fire or fight each other.

### 1.0.3 — 2026-04

"Type @riley in analytics, talk to Riley" integration.

- **Added**: REST endpoint `GET /wp-json/tsim/v1/user-by-login?login=<name>` — resolves a WordPress login to `{ user_id, login, name }`. Returns 404 for unknowns or non-teammates (no membership leak). Used by TSA v1.12.3's @-mention intercept.
- **Added**: cross-frame postMessage protocol. When messaging is running inside an iframe:
	- On boot, announces readiness to the parent (`{ type: 'zim-embed-ready' }`).
	- Accepts `{ type: 'zim-embed-dm-with', user_id, body, auto_send }` from the parent — opens or creates a DM with that user and, if `auto_send`, sends `body` as the first message.
- **Behavior**: this makes the analytics app's chat composer feel like a unified inbox. Typing `@riley hey` in the analytics input and pressing Enter no longer sends to the AI — it opens a DM with Riley in the team panel and delivers "hey" to him.

### 1.0.2 — 2026-04

Tile-visibility fix + TSA inline embed integration.

- **Fixed**: the "Messages" tile was invisible to non-admin users (sales, operator, mfg, tech). The theme's plugin-api gates tiles behind per-user `zdz_allowed_apps` meta for non-admin roles, and 1.0.1 never wrote to that meta. On activation, 1.0.2 now grants `internal-messaging` to every user holding the `zdz_access_app` capability (unless explicitly denied), and the same grant runs on `wp_login` so users created or promoted later also receive it. Users with an explicit deny in `zdz_denied_apps` are still honored.
- **Added**: REST endpoint `GET /wp-json/tsim/v1/unread-total` (permission `zdz_access_app`). Returns `{ unread: <int>, by_conversation: [...] }` — total unread messages across all conversations the current user belongs to. Same customer-facing 404-not-403 rule as admin-ajax. Exists so TSA v1.12.3+ can drive its "💬 Team" button unread badge without needing messaging's admin-ajax nonce in scope.
- **Integration**: with `zdz-sales-analytics` v1.12.3+, a 💬 Team button appears in the TSA chat header. Clicking slides in a right-side panel hosting the messaging UI (via iframe to `?zim_page=1&zdz_embed=tsa`). Messaging itself remains a standalone tile; the TSA panel is an additional access point.

### 1.0.1 — 2026-04

Audit-log integration fixes discovered against Zorderz theme 2.13.1.

- **Fixed**: `ZDZ_Admin_Dashboard` was being referenced under `\Zorderz\` namespace, but the theme defines it in the global namespace. All `class_exists` guards were silently returning false, so admin force-deletes, channel creation, and member add/remove were never audit-logged. Now resolved — audit entries flow through the theme's log correctly.
- **Fixed**: `ZDZ_Admin_Dashboard::log_action()` was being called with the wrong argument shape (assumed `(slug, meta_array)`; actual signature is `(user_id, action_type, detail, app_id, meta)`). Corrected to the theme's 5-argument form with `app_id='zdz-internal-messaging'` for filterable export.
- **Fixed**: Admin CSV export was calling a non-existent `get_log_entries()` helper on the theme class. Now queries `wp_zdz_audit_log` directly with `WHERE app_id = 'zdz-internal-messaging'` and exports the 5 persisted columns (`created_at`, `user_id`, `action_type`, `action_detail`, `meta_json`).
- **Changed**: `Requires PHP` bumped from 7.4 to 8.0 to match the theme's PHP floor. No syntax changes — plugin code was already 7.4-compatible; the version declaration now just reflects the ecosystem expectation.

### 1.0.0 — 2026-04

Initial release.

- Channels + 1:1 DMs
- @mentions with push + email digest fallback
- Per-conversation search (FULLTEXT w/ LIKE fallback)
- Attachments (jpeg/png/webp/heic/pdf, 5 MB cap)
- FreshBooks `#NNNNN` preview cards (via TSA)
- Web Push (self-contained, 90-day VAPID rotation)
- Quiet hours with deferred digest
- Customer-facing hard block
- WP Admin surface for channel/member management + audit export

---

## Support / feedback

Please file issues through the internal Zorderz project tracker. Include the plugin version, theme version, and the output of `wp tsim doctor` (a diagnostic WP-CLI command planned for v1.1).
