# Zorderz — Scheduler (module)

A calendar for the Zorderz dashboard: personal appointments, paint-your-own
availability, and a colour-coded shared team calendar. It works entirely on its
own tables (local-first) and can optionally sync with outside calendars in one
of two modes — **both ship empty and off**.

This is a bundled app **module**, not a standalone plugin. It loads from
`zorderz-apps.php`, registers with the theme through the `zdz_register_apps`
filter on `after_setup_theme`, and declines cleanly when the theme is absent.

- **Class prefix:** `ZSCH_` · **function/option/table prefix:** `zsch_`
- **App id:** `scheduler`
- **REST:** every route under the theme's single `ZDZ_REST_NS` constant, at
  `zorderz/v1/scheduler/…` (the namespace literal is never hand-typed).
- **Data discipline:** no business data is seeded. All credentials, tenant ids,
  mailboxes and the default time zone ship **empty**; schema migration only.

## Calendar sync — two optional modes

### 1. Connected Calendars (the universal, primary path)

Each user connects **their own** Google and/or Microsoft (Exchange) account via
per-user *delegated* OAuth and picks which of their calendars count as busy time.
This needs no org-wide admin and works for any provider mix — it is the mode a
fresh tenant should use. Only the user sees their event details; teammates see
only busy/free.

**Admin setup** (Settings → Scheduler → *Connected Calendars*):

1. Register a **Google OAuth client** (Web application) and/or a **Microsoft
   Entra multitenant delegated** app. These are separate from Mode A below.
2. Register the exact redirect URI the settings screen prints for each provider
   (`/?zsch_oauth=callback&provider=google|microsoft`).
3. Paste each provider's client id + client secret (secrets are stored
   server-side only, never returned to the browser).
4. Tick **Enable Connected Calendars**.

Users then connect from the Schedule widget's ⚙ *Connected calendars* card, or
from the theme's **Settings → App Authorizations → Connect a Calendar** button,
which deep-links here via `?zdz_connect_calendar=open` (the cross-component
contract with the theme's App Authorizations card).

Immutable provider keys identify an account (Google `sub`, Entra `tid:oid`);
email is a display label only. Tokens are encrypted at rest.

### 2. Mode A — org-wide Microsoft 365 app (optional convenience)

Where a whole team lives in **one** Microsoft 365 tenant, an admin may register a
single Azure AD app so the platform two-way syncs every mailbox without each user
connecting. This is a convenience for that specific topology, not an assumption.

**Azure setup** (Settings → Scheduler → *Mode A*):

1. In the Azure portal, **App registrations → New registration** (single tenant).
2. **API permissions → Microsoft Graph → Application permissions →
   `Calendars.ReadWrite`**, then **Grant admin consent** for the organization.
3. **Certificates & secrets → New client secret**; copy the secret *value*.
4. In the settings screen paste the **Directory (tenant) ID**, **Application
   (client) ID** and the **client secret**, set the default time zone if you want
   to pin one, and tick **Enable sync**. Use **Test connection** to verify.

Per-user mailbox targeting defaults to the WordPress account email but is
overridable via the `zsch_mailbox` user meta (and the `zsch_mailbox_for_user`
resolution can be adapted when a Core identity source lands — see below).

Until either mode is configured, the Graph/OAuth surfaces are safe no-ops and the
calendar runs local-first.

## Identity & Core services

Identity is read from Core, never hardcoded:

- **Time zone** resolves through `ZDZ_Business_Profile` / the WP site setting
  (`ZSCH_Settings::default_tz()`), with an optional admin override. No region is
  baked in; the schema default is `UTC`.
- **Roles / access** use the platform role slugs (`zdz_owner … zdz_general`) and
  caps (`zdz_access_app`); the shared kiosk (`zdz_general`) is read-only and every
  write path refuses it server-side.
- **Microsoft Graph** binds to a future shared `ZDZ_Core_Graph` when present
  (`class_exists` guard) and falls back to the module's own client otherwise — no
  competing client is forced.
- **Connections (future):** Mode A credentials and per-user tokens are the shape
  the Core *Connections* layer will own (`calendar.org`, `calendar.party.*`); the
  module keeps its own storage until that service exists.

## Appointment states / Flow

Appointment and availability state values (`confirmed`/`tentative`/`cancelled`,
`open`/`busy`) are the app's own for now. When the Core **Flow** service lands,
they bind to a tenant-declared state machine via a documented Flow hook; until
then they stay in-app and no competing taxonomy is invented.

## Legacy upgrade

A legacy install upgrades in place: the module declares its `tssch_* → zsch_*`
table, option, user-meta and cron renames through the `zdz_rename_map` filter, and
the theme's `ZDZ_Rename_Migration` performs them. Fresh installs no-op.

## Tables

`wp_zsch_appointments` · `wp_zsch_availability` · `wp_zsch_graph_map` ·
`wp_zsch_calendar_accounts` · `wp_zsch_calendar_feeds` · `wp_zsch_external_events`
