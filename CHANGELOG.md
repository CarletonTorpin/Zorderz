# Changelog

All notable changes to Zorderz are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/), and versions follow
[Semantic Versioning](https://semver.org/). Newest first.

Zorderz ships as two artifacts that upgrade together: the theme
(`zorderz-theme-<version>.zip`, the platform kernel and Core services) and the
apps bundle (`zorderz-apps-<version>.zip`). **Install or upgrade the theme first,
then the apps** — the ordering matters and is not enforced by WordPress.

---

## [1.1.0] — 2026-08-01

A maximal port. This release advances the platform onto the current internal
source, generalizes eight new Core services into the theme, and brings fourteen
previously-specific apps into the bundle after stripping every company, person,
product, place and provider name out of them. What varies between businesses is
now configuration; what stays in code names nobody.

### Added — Core services (theme)

Eight new Core services land in the theme: the **Item Engine** and its **admin
screen**, **Answer Authority**, **Rule Governance**, the **Model Registry**,
**Compensation**, **Document Conventions**, and a provider-agnostic
**Connections / Token Service**.

- **Item Engine** — one admin-defined catalog of Products and Services (two types,
  fixed) with user-named subtypes, aliases, attributes, measurement units and
  reusable pricing schemes. It is the authority for the cross-app **counts
  contract**. Ships with an empty catalog; activation creates schema only, never
  data. An optional, clearly-marked fictional sample set can be applied by hand.
- **Answer Authority** — the single outbound gate for chat, email, push, digest
  and stream. It decides whether a figure may be stated and at what confidence
  tier (confirmed / derived / inferred), so the assistant cannot quietly state a
  number it isn't allowed to.
- **Rule Governance** — a business's operating rules as typed, parameterised
  objects. The assistant's prompt is a *rendering* of the rule set. Tenants add
  and narrow rules but can never override the platform's safety floor.
- **Model Registry** — configurable model slots. Each task's model is chosen in
  settings and swapped without code changes.
- **Compensation** — commission structures, piece rates and floors, as
  configuration. Never seeded, sampled or shipped with data.
- **Document Conventions** — how a business writes and numbers its paperwork,
  applied on output rather than compiled into any engine.
- **Connections / Token Service** — rewritten to be provider-agnostic. A provider
  (billing, CRM, scheduling, Ai) is registered once — credentials, OAuth,
  endpoints, redirect URIs, refresh policy — instead of a grant being copied into
  many separate option families.

### Added — theme

- **Party roster service** (`ZDZ_Party`) — the one authoritative answer to "which
  person?", used by share pickers, assignees, participant lists and mentions. A
  person is selectable if they are an active user with a valid email, and nothing
  else gates them — in particular, selectability is **not** filtered by whether
  the user holds a given app's grant. That incidental-grant filter was a real bug:
  an emailable user never granted an app would silently vanish from that app's
  picker, so something "shared with" them reached no one. Published at
  `GET /wp-json/zorderz/v1/party/people` and via the `zdz_selectable_people`
  filter; email stays server-side.
- **Connected Calendars** in Settings → App Authorizations. The
  `app-authorizations` payload now runs through a `zdz_app_authorizations` filter
  so a plugin can register its own authorization entries; the card renders only
  when a plugin adds a `calendars` entry, and deep-links into the scheduler's own
  per-user connect flow via `?zdz_connect_calendar=open`. No OAuth lives in the
  theme.

### Added — apps

Fourteen newly-generalized apps join the bundle, bringing it to **18 apps**
total (the media and collaboration apps — Camera, Media, Sketch Pad and Team —
were already present):

**Quick-ID, Game, Invoices, Knowledge Base, Scheduler, Jobs, Surveys, Stock,
Leads, Prep, Receipts, Estimates, Commission,** and the **Chat** assistant.

Each app reads its identity from the Core services and registers through the
theme's plugin API. Each declines to register when its dependencies are missing,
so a partial install degrades to fewer tiles rather than a broken dashboard, and
a fatal in one app is isolated from the others.

### Changed

- **The product taxonomy is unified behind the Item Engine counts contract.**
  "What this business sells" used to be hardcoded in eight places — a separate
  product taxonomy in every module, each drifting from the others, and doubling
  as the wire format between apps. All eight copies are replaced by one
  catalog-driven vocabulary. Count categories are **Items**, not a fixed enum, so
  "how many of a given kind?" is a grouping question rather than a schema
  question. There are no hardcoded product names anywhere in the engine.
- **Connections became provider-agnostic** (see above), replacing per-provider
  credential grants scattered across option families with one registration layer.

### Deferred

Named honestly rather than shipped half-done:

- The **Chat** app ships as the assistant, but its analytics sub-systems — a data
  planner, an auditor, a memory, and a voice layer — and roughly **350 starter
  prompts** are deferred to a future **Knowledge pack**.
- The **Team** (messaging) app still registers under its legacy `tsim/v1` REST
  namespace. It is self-consistent (it registers and calls the same string, so
  nothing is broken); the full rename will pick it up later.
- The identity-free cosmetic/UX delta from the internal source (status-chip
  palette, widget-header navigation, layout-shift guards, mobile tap-target
  utilities) is not in this release and still carries its pre-rename class names.

### Verification

- **283 PHP files** parsed with `php -l`, zero failures.
- REST surface consolidated under the single `zorderz/v1` namespace (referenced
  from one `ZDZ_REST_NS` constant), the sole exception being the deferred Team app.
- The release string-scan gate is **clean** of company, roster, customer, brand,
  PII and license-conflict strings across both zips.
- Acceptance is exercised against the WordPress kernel harness scenarios: boot,
  fresh install, upgrade path, the Identity Pack lifecycle, and the packaging
  gate. The harness proves nothing fatals, that data migrates, that apps register
  and that routes and tables are created — it does not prove the browser UI, which
  is why a running install remains the real test.

---

## [1.0.1] — 2026-07-27

The release that came back from actually installing 1.0.0. One fix below is the
difference between the app working and every server call silently failing.

### Fixed

- **The app was calling the wrong REST address.** The rename to the `zorderz/v1`
  namespace moved every route *registration* but missed the four places that hand
  the base URL to the browser, which still said the old namespace. The server
  published routes at one address while the front end asked at another, so every
  request — uploads, dashboard data, preferences, KPI figures, the orchestrator —
  returned a clean 404 with nothing in the log, because nothing went wrong
  server-side. Fixed in all four call sites; the namespace is now a single
  `ZDZ_REST_NS` constant instead of a string repeated across files that have to
  agree, and a boot test asserts that the base handed to the browser matches a
  namespace the server actually answers on, so this fails loudly in future.
- **The sidebar rendered a two-letter company initialism** as the platform
  default whenever no logo was uploaded — on every install. Now derived from the
  business name. It was too short a string for a name-based scan to catch.
- **The nav and login logos ignored the Business Profile**, reading only the old
  theme mods. Both now read the profile first, with the theme mods as an upgrade
  fallback.
- **The login screen hardcoded a company tagline.** Now reads the profile.
- **Setting a palette did nothing** — the brand CSS printed before the stylesheet,
  so equal `:root` specificity let the stylesheet win. Now attached to the
  stylesheet's own handle.
- **Reverting an Identity Pack appeared to do nothing** — revert wrote the option
  directly instead of through the setter, so the request-level cache was never
  cleared.
- **The pack reader mangled quoted values followed by comments**, and did not
  understand the inline `{ all: true }` map a pack uses to grant a role every app.
  Both fixed.
- **Nine employee first names were shipping** in code comments, one of them in a
  visible admin string. All replaced with neutral placeholders; the scan gate was
  widened to catch a roster, not just the company name and contact details.
- **Four duplicate module files were shipping** in the bundle — a plain recursive
  copy had left each module's original entry file beside its renamed `app.php`.
  Removed; packaging now renames explicitly via a script.
- **The static `manifest.json` was still shipping** with a company name, colours
  and dead icon paths. Removed — the manifest is now generated from the Business
  Profile and served at `/zdz-manifest.json`.

### Added

- **Business Profile** (Zorderz → Business Profile) — the one place names, contact
  details, domains, outgoing mail identities, locale, logo artwork and the colour
  palette live. Everything ships neutral or inherited from WordPress; a blank
  field means *inherit*, and the screen shows what it's inheriting. The palette is
  an eleven-step brand ramp across four display modes, so eleven values re-skin
  the whole interface without touching CSS.
- **Identity Packs** (Zorderz → Identity Pack) — a business as a folder of YAML or
  JSON. Preview shows the real before-and-after; applying requires typing `APPLY`,
  takes a snapshot, and is logged; Revert restores exactly what was there. A pack
  cannot create, rename or remove a role. Two of the fifteen pack files
  (`profile`, `org`) are read by this version; the rest are recognised and
  reported, never silently ignored. An example pack ships inside the theme.

### Changed

- **Role app-grants can say "everything" explicitly**, stored as a literal `true`
  rather than reusing `null`, so *not configured* and *configured to everything*
  are never the same value.
- **The old jobs app id is mapped to its new id ahead of the app itself**, so
  existing grants naming it survive the eventual port.
- **Version renumbered to 1.0.1** from the private app's `2.x` lineage. Shipping a
  `2.x` number on a first public release overstated how long Zorderz had existed;
  Zorderz versions start at 1.0.0. The update check compares the version string
  for inequality, not ordering, so moving "backwards" is mechanically safe.

### Setup note

Set **Settings → Permalinks → Post name**. Under plain permalinks, `/wp-json/`
returns your homepage as HTML and the generated manifest at `/zdz-manifest.json`
won't resolve.

### Verification

Four scenarios against the WordPress kernel harness, all clean (upgrade path,
fresh install, the full Identity Pack lifecycle, the packaging gate): 71 PHP files
parsed with zero failures; 49 REST routes under `zorderz/v1` with the browser base
asserted to match a registered namespace; zero company or roster strings in either
zip. The harness proves the kernel boots, data migrates, apps register and routes
and tables are created — it does not prove the browser UI, which is how the
namespace bug reached a real install in the first place.

---

## [1.0.0] — 2026-07

The beginning of the Zorderz line: the first build that could be installed on a
real WordPress site.

Zorderz is the open-source generalization of a single private WordPress business
platform into a distribution any field-service business can self-host. 1.0.0
established the shape everything since builds on:

- **The two-artifact model** — a theme (the platform kernel) plus an apps bundle
  (one plugin containing several apps), installed theme-first.
- **The full rename** — a single PHP namespace and class prefix, options and
  tables carrying migration shims, and one REST namespace (`zorderz/v1`) referenced
  everywhere it's needed.
- **A neutral platform** — roughly 150 hardcoded company strings removed from the
  code, leaving a nameless app whose identity is supplied by the business rather
  than baked in.
- **GPL-2.0-or-later**, replacing the private platform's proprietary license.

1.0.0 was the first build installable on a real site; what installing it surfaced
was fixed in 1.0.1.
