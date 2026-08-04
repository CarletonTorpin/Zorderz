# Changelog

All notable changes to Zorderz are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/), and versions follow
[Semantic Versioning](https://semver.org/). Newest first.

Zorderz ships as two artifacts that upgrade together: the theme
(`zorderz-theme-<version>.zip`, the platform kernel and Core services) and the
apps bundle (`zorderz-apps-<version>.zip`). **Install or upgrade the theme first,
then the apps**: the ordering matters and is not enforced by WordPress.

---

## [1.4.3] - 2026-08-04

A security and housekeeping release addressing three issues an independent review of the
staging install surfaced.

### Security

- **Credentials are no longer rendered in the clear in Core Settings.** The Poe API key, the
  FreshBooks secret and tokens, the Nutshell key and the Review Bridge key were printed into
  the Zorderz Core Settings form as plain-text input values, so the secrets sat in the page
  HTML for anyone who could open that screen. Those fields are now masked: the form shows
  "currently set (hidden)" with an empty password input, and a normal Save keeps the stored
  value unless you type a new one, so the keys are never echoed back to the browser. (The
  Knowledge Vault screen already showed only a masked hint and is unchanged.)

### Fixed

- **Stock admin Dashboard was unreachable.** Tools -> Stock -> Dashboard denied access ("you
  are not allowed to access this page") and its menu link was malformed, because the Dashboard
  submenu registered before its parent Stock menu existed and orphaned. The submenu now
  registers after the parent (admin_menu priority), so the page loads. The front-end Stock
  widget was unaffected either way.
- **Scheduler connections endpoint returned 403 when the feature is off.** With Connected
  Calendars disabled (the scheduler runs local-only until Mode A is configured),
  `/scheduler/connections` denied even an administrator with a bare 403, which read like a bug.
  It now returns a 404, matching the documented intent that these routes behave as if they do
  not exist while the feature is off. No change when the feature is enabled.

Theme and apps move together to 1.4.3. Estimates ZEST 1.25.5 unchanged.

---

## [1.4.2] - 2026-08-04

A completeness fix for Company Data Export/Import, found by an independent review of a live
export: it now also carries Zorderz **custom post types** and **taxonomies**. The export
previously handled custom tables, options, users and attachments - but item subtypes are stored
as a taxonomy (`zdz_item_subtype`, with scope/priority/type term meta), and Installation
Receipts and Bug Reports are custom post types, none of which were captured. All are now
discovered by name prefix, like the rest of the export, and restore with their metadata.

### Fixed

- **Taxonomies now export and import.** Zorderz taxonomy terms and their term meta travel with
  the bundle (for example the item-subtype terms and their scope / priority / type), restored
  through the term API so the shared terms tables stay safe. The item-to-subtype assignment
  already survived on each item row, but the subtype definitions themselves did not until now.
- **Custom post types now export and import.** Zorderz post types (Installation Receipts, Bug
  Reports) and their postmeta travel with the bundle, restored preserving ids. Previously only
  attachments were captured among post types, so a business that used Receipts would have lost
  them on migration.

### Notes

- Standard WordPress content (generic posts, pages, comments, core categories and tags) stays
  out of scope by design - that is stock WordPress content, handled by WordPress' own
  Tools -> Export. This feature carries Zorderz-owned data: custom tables, options, users,
  media, and Zorderz-registered post types and taxonomies. Theme fix only; the apps bundle
  moves to 1.4.2 in lock-step. Estimates ZEST 1.25.5 unchanged.

---

## [1.4.1] - 2026-08-04

A correctness fix for 1.4.0's Company Data Export, found by running it on real data. On some
hosts (WP Engine included) get_users() does not return the password hash, so exported users
carried an empty password and an import could leave the owner's admin account unable to log
in. The export now reads user rows straight from the users table so hashes travel with the
roster, and the import never writes an empty password. Because hashes now travel, treat the
downloaded bundle as sensitive. Theme fix only; the apps bundle moves to 1.4.1 in lock-step.

### Fixed

- **Exported users had no password (import lock-out risk).** collect_users now reads the
  users table directly via $wpdb instead of relying on get_users(), which returned an empty
  user_pass on WP Engine. Password hashes now export, so logins carry over to the new install.
- **Import never leaves an account password-less.** If an incoming user has no password hash,
  the import preserves the target account's existing password (which protects the admin
  running the import) or, for a brand-new account, sets a strong random password to be reset.

---

## [1.4.0] - 2026-08-04

A new capability: Company Data Export and Import. A business can now take all of its Zorderz
data off one install as a single portable file and restore it on another (a fresh WordPress
plus Zorderz), from Tools -> Zorderz Data. This is the portability, backup, and migration
tool in one, and it reports a manifest of what moved so a migration can be scored. Theme
feature only; the apps bundle moves to 1.4.0 in lock-step with no app-side changes.

### Added

- **Company Data Export / Import (Tools -> Zorderz Data).** Export writes every Zorderz area
  to one JSON bundle: settings and business profile (options), the Item Engine catalog, the
  user roster with roles, all app custom tables (estimates, invoices, knowledge, and the
  rest), and media references, with a manifest of per-area record counts. Import restores a
  bundle onto a fresh install. Built on WordPress' own data-portability conventions: data
  moves through get_option/update_option and typed rows, never a raw SQL find-and-replace
  (which is what corrupts serialized data); tables are discovered by prefix so new apps are
  covered automatically; and rows keep their primary keys so every internal reference stays
  valid on the fresh target. A dry run previews the restore counts before anything is written.
- **Security by construction.** Connection credentials (Poe, FreshBooks, Nutshell, calendar
  OAuth) are never exported: they are removed by an option-name denylist, by scrubbing
  secret-named columns out of table rows, and by skipping credential and queue tables. The
  new install re-connects its own services. Export and import require the manage_options
  capability and are nonce-protected.

### Notes

- Import assumes a FRESH target (empty Zorderz tables); re-import is idempotent. Media files
  travel by copying wp-content/uploads separately; the bundle carries the references so they
  resolve once the files are in place. Users are restored with their original ids, so the
  default admin on the fresh install may be replaced by the imported owner. Log in with your
  existing Zorderz credentials after an import.

---

## [1.3.6] - 2026-08-04

A small cosmetic fix for the built-in estimates. After an estimate parse finished, the grey
"Reading" status label stayed on screen above the priced preview instead of disappearing, so
a completed parse could look like it was still working. The label now clears the moment the
priced preview appears. Theme and apps move together to 1.3.6.

### Fixed

- **Estimate widget "Reading" label did not clear after a parse.** The status element is
  hidden in JavaScript with the hidden attribute, but the widget stylesheet gave it an
  explicit display that overrode the browser's built-in hidden rule, so clearing the status
  left the label visible above the finished preview. A scoped rule now hides the status
  element when it is marked hidden, so the label disappears as soon as the priced preview
  renders. The parse itself was unaffected. Estimates app ZEST 1.25.5; front-end only, no
  schema change.

---

## [1.3.5] - 2026-08-04

A correctness release for the built-in estimates. The asynchronous estimate text parse
introduced in 1.3.4 returned an empty Ai response ("No response content.") on WP Engine,
so typing or dictating an estimate produced a parse error instead of a priced preview. The
text parse now runs synchronously again, the path that worked through 1.3.3: it completes
in a few seconds and prices every line correctly. The genuinely slow paths, photo parse
and PDF import, stay asynchronous and are verified working. Theme and apps move together to
1.3.5.

### Fixed

- **Estimate text parse errored on WP Engine ("No response content.").** 1.3.4 routed the
  text parse through a background job; on WP Engine that job's catalog-augmented Ai request
  came back empty every time, while the same input parsed correctly on the synchronous
  endpoint with the identical model and key. The text parse now calls the synchronous
  endpoint directly, as it did through 1.3.3, so dictated or typed estimates return their
  priced preview again. A text parse completes well under a managed host's gateway timeout,
  so the background job it briefly used was never needed. The photo parse and PDF-import
  paths remain asynchronous (they are slow and their background jobs are confirmed
  working). Estimates app ZEST 1.25.4; no schema change.

---

## [1.3.4] - 2026-08-04

An enhancement to the built-in estimates. The Ai-assisted estimate parse now runs as a
background job the browser polls, instead of holding one request open, so a slow parse
can no longer approach a managed host's gateway timeout and return a 502. This is the
same async pattern the Chat assistant already uses; the synchronous parse remains as an
automatic fallback if the enqueue fails. Theme and apps move together to 1.3.4.

### Changed

- **Estimate text parse is now asynchronous.** Typing or dictating an estimate and
  pressing Parse now enqueues a background job and polls for the result (showing parse
  progress), rather than running the parse inside one long request. A slow, catalog and
  Ai-augmented parse can no longer hit the gateway timeout that produced a 502; it always
  completes and shows the priced preview. The previous synchronous parse is kept as an
  automatic fallback when the job cannot be enqueued, and the photo and PDF-import paths
  (already asynchronous) are unchanged. Estimates app ZEST 1.25.3; no schema change.

---

## [1.3.3] - 2026-08-03

A correctness release for the built-in estimates. Estimates created by dictating or
typing them (the Ai-assisted path) were being saved with every price at $0.00 because
the catalog price lookup asked the Item Engine with the wrong id. Catalog prices now
resolve as they should, overpaid invoices no longer show a negative amount due, and the
Estimates widget status text renders correctly. Theme and apps move together to 1.3.3.

### Fixed

- **Ai-parsed estimates priced every line at $0.00.** The catalog price resolver passed
  the item id where the Item Engine expects a pricing-scheme id, so it never resolved
  and each line fell back to $0.00 (the no-Ai fallback parser already worked around
  this). It now resolves from the scheme id, and the price fill asks for the unit rate
  (quantity one) so a per-unit price is not multiplied by the quantity a second time.
  Dictated or typed estimates now carry their catalog prices. Estimates app ZEST 1.25.2.
- **Overpaid invoices showed a negative amount due.** A payment at or above the invoice
  total now clamps the amount due at zero everywhere it appears (the management console,
  the printable invoice, the import review, and the payment response) instead of showing
  a negative figure.
- **Widget status text showed a raw HTML entity.** The Estimates widget status (for
  example while reading or creating an estimate) rendered a literal entity instead of an
  ellipsis; it now shows a proper ellipsis.

---

## [1.3.2] - 2026-08-03

A front-end hotfix for the Estimates widget. On the dashboard the widget's script
could evaluate before the theme finished injecting the widget markup, so it found no
root element and stopped: the Open and History lists stayed on the "Loading"
placeholder and the tabs did not respond. The widget now initializes when its markup
is present. Server side was already correct; this is a front-end fix only. Theme and
apps move together to 1.3.2.

### Fixed

- **Estimates widget did not initialize on the dashboard.** The widget script loads in
  the footer and could run before the theme's renderWidgets() injected the widget
  markup, so it bailed on a missing root and never bound its tabs or loaded its lists;
  the Open and History panels sat on the "Loading" placeholder forever. It now boots
  when the markup is present, waiting for the theme's zdz_widgets_rendered event with a
  DOMContentLoaded handler and a short poll as fallbacks, and it guards against double
  initialization. The estimate list, the tabs, and the parse-to-create flow work again.
  The server side was already correct (the list endpoint and the estimate preview route
  both respond normally). Estimates app internal version ZEST 1.25.1; no schema change.

---

## [1.3.1] - 2026-08-03

A hotfix for a site-down bug. Under one upgrade path a Knowledge Base database
column could be missing while the module still expected it, and on PHP 8.4 the
resulting query error could take the whole site down with a 502 on every page.
The Knowledge Base now repairs its own schema on every load instead of only when
its internal version number advances, so a missing column or index is added back
automatically. Theme and apps move together to 1.3.1.

### Fixed

- **Site-down 502 from a missing Knowledge Base column.** The Knowledge Base read
  paths query an `is_pricing_authority` column. If that column was absent (for
  example after a database was copied from an environment that predated the
  column, while the stored schema version already matched the running code), PHP
  8.4 turned the failed query into a fatal error and every page returned a 502.
  The module now verifies and heals its own table on every load, independent of
  the stored version number, adding any missing column or index in place. A short
  transient records that the schema is healthy, so the check costs nothing on the
  common path.

---

## [1.3.0] - 2026-08-03

A features-and-cleanup release. The headline is a built-in way to bring an existing
business's paperwork in: upload a PDF estimate or invoice and import it. Chat turns no
longer block, the last of the pre-Zorderz "TS" branding is gone, and the docs are cleaned
up. Theme and apps move together to 1.3.0.

### Added

- **Manual PDF import for estimates and invoices.** Upload an existing business's PDF
  estimate or invoice and it is parsed into the same canonical document model the built-in
  generator uses, then shown in an editable preview to review and confirm before anything
  is saved. Text is extracted in the browser (vendored pdf.js: no server-side PDF
  dependency and nothing to install); parsing uses the configured Ai model when present,
  with a paste-the-text or manual-entry fallback when it is not, or when a PDF is
  image-only. Totals are reconciled against the printed total and any mismatch is flagged,
  never silently corrected. This is the on-ramp for someone migrating onto Zorderz: their
  history comes with them. Nothing is trusted to the model for a side effect; a human
  confirms every import.
- **Single-operator mode (off by default).** A solo owner who runs everything can turn on
  one setting to self-schedule their own jobs and close them with a self-attested
  completion, instead of being blocked by the dispatcher-and-crew rules (assignment by a
  dispatcher, two-party sign-off) that a one-person shop cannot satisfy. With the mode off,
  every existing multi-user guard is unchanged.

### Changed

- **Chat turns are now asynchronous.** A slow, vault-augmented turn used to hold one
  request open long enough to hit a managed host's gateway timeout and return a 502. The
  turn now runs as a background job the browser polls, so it always completes and is saved.
  The previous synchronous path remains as a fallback, every answer still passes through
  the same Answer Authority gate, and shared-device (kiosk) turns stay synchronous and
  unrecorded as before.
- **The pre-Zorderz "TS" branding is gone.** The remaining app headers that read
  "Zorderz - TS - X" are now "Zorderz X", and the Scheduler's admin label, cron entry, and
  log beacons read "Zorderz Scheduler". Functional identifiers (class prefixes, the
  tsim/v1 REST namespace, backward-compatible meta keys) are unchanged.

### Docs

- Removed every em and en dash from the repository's Markdown (README, changelog, contract
  specs, app READMEs), per house style.
- Confirmed a clean install ships empty: activation creates roles and the generic
  Login/Register/Terms pages only, and every business-data path (Item Engine,
  Compensation, Business Profile, Identity Pack, the demo catalog) is create-schema-only or
  hand-applied, never seeded.

### Verification

Both zips rebuilt at 1.3.0; 286 PHP files parse clean; the new client scripts parse clean;
imports write only through the existing manage_options-gated endpoints; the async chat path
preserves the synchronous fallback and the outbound gate. Internal app versions this
release: Estimates (ZEST) 1.25.0, Chat (ZANA) 1.2.0, Jobs (ZJOB) 1.17.0.

---

## [1.2.0] - 2026-08-02

The release that came back from the first full end-to-end install: a business set
up from scratch on a clean WordPress, then actually used. 1.1.0 proved the platform
boots; 1.2.0 is what using it surfaced, plus the first built-in, no-external-API way
to run estimates and invoices.

**Unified versioning starts here.** From 1.2.0 on, the two artifacts ship on one
number: "Zorderz 1.2.0" is theme 1.2.0 and apps 1.2.0, and they move together from
now on. The apps inside the bundle keep their own internal version constants (each
drives its own schema migrations); it is the distribution that is single-numbered.

### Added

- **A built-in estimate and invoice generator that needs no external billing API.**
  One shared document engine renders estimates and invoices in a single consistent,
  print-ready layout, pulling the company header straight from the Business Profile
  so both look on-brand and identical. Line items and pricing come from the Item
  Engine, so pricing is deterministic and works fully offline. An estimate converts
  to an invoice (continuing an existing number sequence), and payments are recorded
  and tracked from sent, to partial, to paid. Staff drive all of it from a
  self-contained console. This is deliberately **documents and payment tracking
  only**: not a billing gateway, not inventory, not accounts-receivable aging, and
  not a CRM. It is the floor that lets a business whose only tool is Zorderz operate
  from day one; a connected provider (Stripe, FreshBooks) stays the system of record
  when present and maps onto this same model.
- **A generic document importer**, so an existing business's estimates and invoices
  can be brought in and tracked. The manual PDF-parsing front end is designed and
  endpoint-ready and is the next milestone.
- **The Knowledge Vault now feeds the Chat assistant.** An indexed, permitted
  document becomes answerable in Chat through the single neutral data seam the
  assistant reads each turn from. Visibility is enforced at the source, so wiring
  the two together added reach, not exposure.
- **An admin debug-log reader**, with opt-in capture of PHP error output to a
  readable file, for managed hosts that keep no readable log by default. Off by
  default.

### Fixed

- **Config screens were denied to a full administrator (blocker).** Business
  Profile, Identity Pack and the Item Engine admin returned "you are not allowed to
  access this page," because the submenus registered before their parent menu
  existed. The parent is now registered first.
- **User Management rendered blank**, gated on a stale pre-rename hook id. Corrected.
- **The Estimates app was unusable without a billing API.** It hung forever on a
  failed call (now degrades to an honest error), required an external provider to
  create an estimate (now falls back to a local number), and priced everything at
  zero because the price resolver was handed the wrong id (fixed).
- **The Knowledge Vault could accept no documents at all (severe).** Two columns
  were declared only in a version-gated migration that fresh installs skip, so every
  upload failed with "Failed to create document record." Both now live in the table
  definition and self-heal on every install and upgrade.
- **Knowledge Vault file storage hardened** for managed hosts where a deny-all
  `.htaccess` is inert: vault files now write under an unguessable per-file random
  subdirectory.

### Changed

- **The theme is now titled "Zorderz Core"** (a leftover pre-release prefix
  removed), and the apps bundle header no longer carries it.
- **Both artifacts renumbered to 1.2.0** in lockstep (see "Unified versioning"
  above).

### Scope, on purpose

The built-in estimates and invoices are documents and payment tracking, nothing
more. Zorderz does not aim to become a CRM (the system of record for the whole
customer relationship: contacts, accounts, the sales pipeline, activity history and
reporting) or a comprehensive billing platform. WordPress is the wrong place to
store and move that much data, and duplicating a CRM inside it would only bloat the
database and slow the apps. Compatibility with external CRMs is the path, through
the same Connections layer that already handles billing, scheduling and Ai;
WooCommerce is not involved.

### Still pending

A legacy `TS` prefix from the pre-Zorderz source still appears in a few individual
app headers and one admin label (cosmetic; sweep pending); chat turns are
synchronous (a slow one can hit a managed-host origin timeout, and async is the next
hardening); and the manual PDF import front end is designed but not yet built.

### Verification

Both zips rebuilt at 1.2.0; PHP files parse clean; the REST surface stays under the
single `zorderz/v1` namespace; the release string-scan is clean of company, roster,
customer and brand strings; and the estimate, invoice and payment lifecycle (create,
convert, record payment through to paid) was exercised on a live install through the
console UI.

---

## [1.1.0] - 2026-08-01

A maximal port. This release advances the platform onto the current internal
source, generalizes eight new Core services into the theme, and brings fourteen
previously-specific apps into the bundle after stripping every company, person,
product, place and provider name out of them. What varies between businesses is
now configuration; what stays in code names nobody.

### Added: Core services (theme)

Eight new Core services land in the theme: the **Item Engine** and its **admin
screen**, **Answer Authority**, **Rule Governance**, the **Model Registry**,
**Compensation**, **Document Conventions**, and a provider-agnostic
**Connections / Token Service**.

- **Item Engine**: one admin-defined catalog of Products and Services (two types,
  fixed) with user-named subtypes, aliases, attributes, measurement units and
  reusable pricing schemes. It is the authority for the cross-app **counts
  contract**. Ships with an empty catalog; activation creates schema only, never
  data. An optional, clearly-marked fictional sample set can be applied by hand.
- **Answer Authority**: the single outbound gate for chat, email, push, digest
  and stream. It decides whether a figure may be stated and at what confidence
  tier (confirmed / derived / inferred), so the assistant cannot quietly state a
  number it isn't allowed to.
- **Rule Governance**: a business's operating rules as typed, parameterised
  objects. The assistant's prompt is a *rendering* of the rule set. Tenants add
  and narrow rules but can never override the platform's safety floor.
- **Model Registry**: configurable model slots. Each task's model is chosen in
  settings and swapped without code changes.
- **Compensation**: commission structures, piece rates and floors, as
  configuration. Never seeded, sampled or shipped with data.
- **Document Conventions**: how a business writes and numbers its paperwork,
  applied on output rather than compiled into any engine.
- **Connections / Token Service**: rewritten to be provider-agnostic. A provider
  (billing, CRM, scheduling, Ai) is registered once, credentials, OAuth,
  endpoints, redirect URIs, refresh policy, instead of a grant being copied into
  many separate option families.

### Added: theme

- **Party roster service** (`ZDZ_Party`): the one authoritative answer to "which
  person?", used by share pickers, assignees, participant lists and mentions. A
  person is selectable if they are an active user with a valid email, and nothing
  else gates them, in particular, selectability is **not** filtered by whether
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

### Added: apps

Fourteen newly-generalized apps join the bundle, bringing it to **18 apps**
total (the media and collaboration apps, Camera, Media, Sketch Pad and Team ,
were already present):

**Quick-ID, Game, Invoices, Knowledge Base, Scheduler, Jobs, Surveys, Stock,
Leads, Prep, Receipts, Estimates, Commission,** and the **Chat** assistant.

Each app reads its identity from the Core services and registers through the
theme's plugin API. Each declines to register when its dependencies are missing,
so a partial install degrades to fewer tiles rather than a broken dashboard, and
a fatal in one app is isolated from the others.

### Changed

- **The product taxonomy is unified behind the Item Engine counts contract.**
  "What this business sells" used to be hardcoded in eight places, a separate
  product taxonomy in every module, each drifting from the others, and doubling
  as the wire format between apps. All eight copies are replaced by one
  catalog-driven vocabulary. Count categories are **Items**, not a fixed enum, so
  "how many of a given kind?" is a grouping question rather than a schema
  question. There are no hardcoded product names anywhere in the engine.
- **Connections became provider-agnostic** (see above), replacing per-provider
  credential grants scattered across option families with one registration layer.

### Deferred

Named honestly rather than shipped half-done:

- The **Chat** app ships as the assistant, but its analytics sub-systems, a data
  planner, an auditor, a memory, and a voice layer, and roughly **350 starter
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
  and that routes and tables are created, it does not prove the browser UI, which
  is why a running install remains the real test.

---

## [1.0.1] - 2026-07-27

The release that came back from actually installing 1.0.0. One fix below is the
difference between the app working and every server call silently failing.

### Fixed

- **The app was calling the wrong REST address.** The rename to the `zorderz/v1`
  namespace moved every route *registration* but missed the four places that hand
  the base URL to the browser, which still said the old namespace. The server
  published routes at one address while the front end asked at another, so every
  request, uploads, dashboard data, preferences, KPI figures, the orchestrator ,
  returned a clean 404 with nothing in the log, because nothing went wrong
  server-side. Fixed in all four call sites; the namespace is now a single
  `ZDZ_REST_NS` constant instead of a string repeated across files that have to
  agree, and a boot test asserts that the base handed to the browser matches a
  namespace the server actually answers on, so this fails loudly in future.
- **The sidebar rendered a two-letter company initialism** as the platform
  default whenever no logo was uploaded, on every install. Now derived from the
  business name. It was too short a string for a name-based scan to catch.
- **The nav and login logos ignored the Business Profile**, reading only the old
  theme mods. Both now read the profile first, with the theme mods as an upgrade
  fallback.
- **The login screen hardcoded a company tagline.** Now reads the profile.
- **Setting a palette did nothing**: the brand CSS printed before the stylesheet,
  so equal `:root` specificity let the stylesheet win. Now attached to the
  stylesheet's own handle.
- **Reverting an Identity Pack appeared to do nothing**: revert wrote the option
  directly instead of through the setter, so the request-level cache was never
  cleared.
- **The pack reader mangled quoted values followed by comments**, and did not
  understand the inline `{ all: true }` map a pack uses to grant a role every app.
  Both fixed.
- **Nine employee first names were shipping** in code comments, one of them in a
  visible admin string. All replaced with neutral placeholders; the scan gate was
  widened to catch a roster, not just the company name and contact details.
- **Four duplicate module files were shipping** in the bundle, a plain recursive
  copy had left each module's original entry file beside its renamed `app.php`.
  Removed; packaging now renames explicitly via a script.
- **The static `manifest.json` was still shipping** with a company name, colours
  and dead icon paths. Removed, the manifest is now generated from the Business
  Profile and served at `/zdz-manifest.json`.

### Added

- **Business Profile** (Zorderz → Business Profile): the one place names, contact
  details, domains, outgoing mail identities, locale, logo artwork and the colour
  palette live. Everything ships neutral or inherited from WordPress; a blank
  field means *inherit*, and the screen shows what it's inheriting. The palette is
  an eleven-step brand ramp across four display modes, so eleven values re-skin
  the whole interface without touching CSS.
- **Identity Packs** (Zorderz → Identity Pack): a business as a folder of YAML or
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
and tables are created, it does not prove the browser UI, which is how the
namespace bug reached a real install in the first place.

---

## [1.0.0] - 2026-07

The beginning of the Zorderz line: the first build that could be installed on a
real WordPress site.

Zorderz is the open-source generalization of a single private WordPress business
platform into a distribution any field-service business can self-host. 1.0.0
established the shape everything since builds on:

- **The two-artifact model**: a theme (the platform kernel) plus an apps bundle
  (one plugin containing several apps), installed theme-first.
- **The full rename**: a single PHP namespace and class prefix, options and
  tables carrying migration shims, and one REST namespace (`zorderz/v1`) referenced
  everywhere it's needed.
- **A neutral platform**: roughly 150 hardcoded company strings removed from the
  code, leaving a nameless app whose identity is supplied by the business rather
  than baked in.
- **GPL-2.0-or-later**, replacing the private platform's proprietary license.

1.0.0 was the first build installable on a real site; what installing it surfaced
was fixed in 1.0.1.
