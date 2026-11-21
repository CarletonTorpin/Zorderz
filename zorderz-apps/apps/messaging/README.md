# Zorderz Internal Messaging

Internal team messaging for the Zorderz Field OS ecosystem.

A WordPress plugin that provides real-time-ish channels and 1:1 direct messages between Zorderz operators, admins, sales, manufacturing, and tech roles. Renders as an inline dashboard widget inside the Zorderz theme; a full-page view is available at `?zim_page=1`.

**Slug:** `zdz-internal-messaging`
**Namespace:** `ZIM_`

---

## Requirements

- The Zorderz theme of the same release (the theme and the apps bundle move in lockstep; Messaging builds against the `\Zorderz\Widget_App_Interface` the theme exports).
- WordPress **6.0+**
- PHP **8.0+** (matches the theme's floor; tested through 8.3). Requires `openssl` and `hash_hkdf` (both in PHP core).
- MySQL **5.7+** or MariaDB **10.2+** (FULLTEXT search falls back to LIKE if the FT index can't be created).

### Optional but recommended

- **The Chat app (Analytics)**: enables rich `#NNNNN` preview cards from the billing system (via the `/zorderz/v1/freshbooks-preview/{id}` REST endpoint, gated by the `zdz_preview_proxy_available` filter) and the coordinated customer-facing hard-block (via the `zdz_customer_facing_active` filter). Without it, `#NNNNN` references still open the document where one can be shown; preview metadata is just unavailable.
- **HEIC image support**: HEIC uploads are converted server-side to JPEG on hosts with ImageMagick/Imagick; without it, HEIC is accepted only where the host can already handle the format.

---

## Install

1. Messaging ships in the Zorderz apps bundle (`zorderz-apps-<version>.zip`).
2. Activate the bundle. On activation:
	- Creates 8 tables (prefix `wp_zim_*`).
	- Seeds one channel, `#announcements` (administrators post; everyone with Messages reads, the shared device included, read-only), and auto-joins everyone with Messages to it.
	- Any other channel is the business's: an administrator creates it, or the business's Identity Pack lists it under `org.yml` → `messaging.channels` (each with its roles, or `everyone: true`); listed channels auto-join the people holding those roles. A listed channel whose roles are all unknown is dropped, never opened to everyone.
	- Schedules 3 cron jobs:
		- `zim_dispatch_notifications`: every minute (quiet-hours release)
		- `zim_purge_attachments`: daily (orphans + soft-deleted attachments ≥30 days old)
		- `zim_rotate_vapid_if_due`: daily (rotates push keys every 90 days)
	- Generates a VAPID keypair for Web Push.
3. Grant desired users the `zdz_access_app` capability via the Zorderz theme's role editor (or via CLI / `user_can` directly). All `zdz_owner`, `zdz_admin`, `zdz_sales`, `zdz_operator`, `zdz_mfg`, `zdz_tech` roles in the Zorderz ecosystem normally already have this capability.

### Deactivate / uninstall

Deactivation stops crons but leaves data intact. Uninstall (via WordPress → Plugins → Delete) removes tables, options, user meta, and unbinds push subscriptions, **including message history**. Back up `wp_zim_messages` if you need to preserve it.

---

## Architecture highlights

- **Polling over WebSockets**: 3-second HTTP polls, max 2 concurrent per tab. 50 messages per poll cap. Scales to hundreds of concurrent users on a normal shared-host PHP/MySQL stack; WebSockets are the v2 roadmap.
- **Unified `wp_zim_conversations` table**: one ID space for both channels (`kind='channel'`) and DMs (`kind='dm'`). DMs are deterministic: `UNIQUE KEY (user_a, user_b)` with `user_a < user_b` always. There can only ever be one DM between any two users.
- **Soft-delete preserves audit trail**: deletes blank the body, mark `deleted_at`, and keep mention rows so the user's inbox history is intact. Attachments on deleted messages are physically purged 30 days later by cron.
- **Web Push is self-contained**: no Minishlink/WebPush or other external SDK. `openssl` + `hash_hkdf` implement RFC 8291 (aes128gcm) and ES256 VAPID JWTs directly. Keys rotate every 90 days.
- **Customer-facing hard-block at PHP render time**: when the analytics app's customer-facing mode is active for the current user, the widget renders nothing at all and every AJAX endpoint returns **404, not 403**, so the plugin's existence is not leaked to a screen a customer might see.

The Analytics app owns the `/zorderz/v1/freshbooks-preview/{id}` endpoint contract (see its REST layer).

---

## Configuration

Almost nothing to configure. Sensible defaults out of the box:

| Setting                      | Default       | Option key / constant                   |
|------------------------------|---------------|------------------------------------------|
| Edit window                  | 5 minutes     | `ZIM_EDIT_WINDOW_SECONDS`               |
| Attachment size cap          | 5 MB          | `ZIM_MAX_UPLOAD_BYTES`                  |
| Allowed MIME types           | jpeg/png/webp/heic/pdf | `ZIM_ALLOWED_MIMES` (JSON)      |
| Quiet hours                  | the person's own, else the business's (Zorderz → Settings → Quiet hours), else 21:00 to 07:00 | user meta `zim_quiet_hours_`; option `zdz_quiet_hours` (`ZDZ_Quiet_Hours`) |
| Poll interval                | 3000 ms       | hardcoded (JS + server)                  |
| Messages per poll            | 50            | hardcoded                                |
| VAPID key rotation           | 90 days       | `ZIM_PUSH_ROTATION_DAYS`                |
| Email digest per-convo cooldown | 30 minutes | `ZIM_EMAIL_DIGEST_COOLDOWN_SECONDS`     |

Users change their own quiet hours from the widget's ⚙︎ menu or Settings → Notifications.

---

## Admin UI

`WP Admin → Messaging` (visible to `manage_options`):

- **Channels**: list all channels; create new; click through to manage members.
- **Audit log**: CSV export of plugin admin actions (channel creation, member add/remove). Regular message traffic is **not** logged, too voluminous to be actionable.

Regular users use only the inline widget.

---

## Design rules

| #  | Rule                                                               | How it is kept |
|----|--------------------------------------------------------------------|--------------------|
| 1  | No WebSockets                                                      | HTTP polling, 2-in-flight ceiling |
| 2  | No external API clients                                            | Web Push done directly; FreshBooks proxied through Analytics |
| 3  | Mention reconcile only notifies ADDED (edit doesn't re-notify)     | `ZIM_Mentions::reconcile()` diffs and returns `added`/`removed`; only `added` triggers push |
| 4  | Soft-delete preserves mention rows                                 | delete blanks body + stamps `deleted_at`; mention rows untouched |
| 5  | Customer-facing hide at PHP render time, not 403                   | `ZIM_Widget::should_render()` + all AJAX gates return 404 |
| 6  | Push subscription invalidation on logout                           | `clear_auth_cookie` action → deletes this device's subscriptions (the signing-out session's rows) |
| 7  | Quiet hours DEFER not DROP                                         | `ZIM_Notifications::compute_release_at()` computes the next out-of-quiet moment |
| 8  | Indexes declared at CREATE TABLE                                   | all in `db/migrate-1.0.0.php` |
| 9  | Uploads through `wp_handle_upload`                                 | `ZIM_Attachments::handle_upload()` with explicit `'mimes'` |

---

## Performance / scaling

- **Tables & indexes**: every hot query is covered. Message history scroll uses `idx_conv_created (conversation_id, created_at)`. Unread counts are `SELECT COUNT(*) ... WHERE conversation_id = ? AND id > last_read_message_id`; indexed covers.
- **Search**: FULLTEXT index on `wp_zim_messages.body` declared at create-time (named `idx_body_ft`). Falls back to `LIKE %q%` when FULLTEXT isn't available.
- **Growth threshold**: the base schema comfortably handles **1 million messages** on modest hardware. Past that, consider partitioning `wp_zim_messages` by `conversation_id` or archiving messages older than 1 year. Not needed at the scale of one business.
- **Push send cost**: one HTTP request per subscription per notification. Browsers aggressively dedupe by `tag=zim-{conversation_id}`, so a user with 3 tabs open doesn't get 3 alerts.

---

## Security posture

- Every AJAX endpoint: `check_ajax_referer(ZIM_NONCE, 'nonce')` + `current_user_can('zdz_access_app')` + membership check + customer-facing gate. Admin endpoints additionally require `manage_options` or `zdz_owner`/`zdz_admin` role.
- No direct SQL concatenation: every query uses `$wpdb->prepare`.
- Output uses `esc_html`/`esc_attr`/`esc_url` at the boundary; client-side, `DOMPurify` sanitizes the marked.js HTML before insertion.
- Upload path uses `wp_handle_upload` + explicit MIME whitelist + size check.
- Push payloads are E2E encrypted per RFC 8291 (aes128gcm + ECDH). VAPID ES256 signatures are raw-R||S (not DER) per RFC 8292.
- **Vimeo chapter embed.** A first-party training-video embed is never passed through the sanitizers. `ZIM_Messages::extract_video_embeds()` runs *before* `wp_kses_post()` in `post()`/`edit()`, recognizes **only** a trusted-origin `player.vimeo.com` player (origin checked on a parsed URL; id validated numeric), and distills it to a plain-text `[zdz-video]<hex>[/zdz-video]` token that survives kses untouched. Client `wireVideoEmbeds()` rebuilds the player with the iframe origin **hard-fixed** to `player.vimeo.com` and inserts chapter labels via `textContent`. A forged token can never point the iframe off Vimeo. The trusted-origin list is a Core default (Vimeo) exposed via the `zim_video_embed_hosts` filter. The chapter list is read from Zorderz' own class names (`zdz-video-embed`, `zdz-video-chapter`, `zdz-video-heading`, `zdz-video-title`); a business whose pages use other class names names them in its Identity Pack (`mappings.yml messaging.video_embed_classes`, any of `embed`, `chapter`, `heading`, `title`), and code can change them with the `zim_video_embed_classes` filter.

---

## Known limitations / Not in this release

Not in this release:

- **Group DMs**: only 1:1 DMs. Multi-user conversations require a channel.
- **Threaded replies**: one flat message stream per conversation.
- **Reactions / emoji**
- **Typing indicators**
- **Voice / video**
- **Slack or Teams bridges**
- **Message pinning / bookmarks**
- **Cross-conversation search**: per-conversation search only
- **Scheduled send**
- **Polls / forms inside messages**
- **Rich URL unfurls**: only estimate and invoice `#NNNNN` refs (and vault documents) get rich previews
- **Web Push UI for Safari < 16.4**: those users fall back to email digests
- **Real-time typing / presence**: polling does not expose presence
- **Message export**: admins can export the audit log; there is no full message export

---

## Changes

See `CHANGELOG.md` at the top of the repository.

---

## Support / feedback

Please open an issue on the project's repository. Include the release version and what you saw.
