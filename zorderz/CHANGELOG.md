# Zorderz 1.3.3 (theme + apps)

**August 3, 2026 · Zorderz Core theme 1.3.3 · Zorderz Apps bundle 1.3.3**

A correctness release for the built-in estimates. Estimates created by dictating or typing them (the Ai-assisted path) were saved with every price at $0.00: the catalog price resolver asked the Item Engine with the item id where it expects a pricing-scheme id, so nothing resolved and each line fell back to $0.00 (the no-Ai fallback already worked around this). Prices now resolve from the scheme id, and the fill asks for the unit rate (quantity one) so a per-unit price is not squared against the quantity. Overpaid invoices no longer show a negative amount due (it is clamped at zero in the console, the printable invoice, the import review, and the payment response), and the Estimates widget status text now renders a proper ellipsis instead of a raw HTML entity. Internal app version this release: Estimates (ZEST) 1.25.2.

---

# Zorderz 1.3.2 (theme + apps)

**August 3, 2026 · Zorderz Core theme 1.3.2 · Zorderz Apps bundle 1.3.2**

A front-end hotfix for the Estimates widget. On the dashboard the widget's footer script could evaluate before the theme's renderWidgets() injected the widget markup, so it bailed on a missing root element and left the Open and History lists stuck on the "Loading" placeholder with unresponsive tabs. The widget now boots when its markup is present, waiting for the theme's zdz_widgets_rendered event with a DOMContentLoaded handler and a short poll as fallbacks, and it guards against double initialization. The estimate list, the tabs, and the parse-to-create flow work again. The server side was already correct (the list endpoint and the estimate preview route both respond normally); this release changes only the widget's boot. Internal app version this release: Estimates (ZEST) 1.25.1.

---

# Zorderz 1.3.1 (theme + apps)

**August 3, 2026 · Zorderz Core theme 1.3.1 · Zorderz Apps bundle 1.3.1**

A hotfix for a site-down bug. The Knowledge Base read paths query an `is_pricing_authority` column; if that column was missing while the module's stored schema version already matched the running code (for instance after a database was copied from an environment that predated the column), PHP 8.4 turned the failed query into a fatal error and every page returned a 502. The Knowledge Base now verifies and heals its own table on every load, independent of the stored version number, so a missing column or index is added back in place, and a short transient marks the schema healthy so the check is free on the common path. Theme and apps move together to 1.3.1. Internal app version this release: Knowledge Base (ZKV) 1.7.2.

---

# Zorderz 1.3.0 (theme + apps)

**August 3, 2026 · Zorderz Core theme 1.3.0 · Zorderz Apps bundle 1.3.0**

A features-and-cleanup release, shipped in lockstep. Manual PDF import lands in the Estimates app: upload an existing business's PDF estimate or invoice, have it parsed into the canonical document model (browser-side pdf.js extraction, Ai-assisted parse with a manual fallback), review an editable preview, and import through the existing endpoints. Chat turns became asynchronous so a slow one can no longer hit a managed-host 502 (background job plus polling, with the synchronous path kept as a fallback and the Answer Authority gate unchanged). Single-operator mode (off by default) lets a solo owner self-schedule and self-attest job completion without the dispatcher-and-crew guards. The last pre-Zorderz "TS" branding was removed from the remaining app headers and the Scheduler label, and every em dash was swept out of the repository's Markdown. A clean install was reconfirmed to ship empty. Internal app versions this release: Estimates (ZEST) 1.25.0, Chat (ZANA) 1.2.0, Jobs (ZJOB) 1.17.0.

---

# Zorderz 1.2.0 (theme + apps, unified versioning)

**August 2, 2026 · Zorderz Core theme 1.2.0 · Zorderz Apps bundle 1.2.0**

**Unified versioning starts here.** From 1.2.0 on, the distribution ships as one number: "Zorderz 1.2.0" means Zorderz Core theme 1.2.0 *and* Zorderz Apps bundle 1.2.0, and the two artifacts move together from here forward. This release rolls up the run of point releases that came out of the first real end-to-end install and stamps both artifacts at 1.2.0. Internally the theme moved 1.1.0 → 1.1.1 and the apps bundle moved 1.1.1 → 1.1.9; the section-by-section record of each step is preserved below, unchanged, for full transparency. The individual apps inside the bundle keep their own internal version constants (they drive each app's own `dbDelta` migrations); it is the distribution that is now single-numbered, not each app.

These changes came out of the first real end-to-end install: a fictional company set up from scratch on a clean, managed WordPress host. 1.1.0 proved the platform boots; this run is what came back from actually using it. Install order is unchanged (theme first, then the apps bundle), and every schema change is additive and self-heals through `dbDelta`.

## Theme (Zorderz Core): 1.1.0 → 1.1.1

**Config screens were denied to a full administrator (blocker).** Business Profile, Identity Pack and the Item Engine admin returned "Sorry, you are not allowed to access this page." The submenu classes hook `admin_menu` before the parent Zorderz menu exists (the parent was only instantiated inside its own `init` callback), so the submenus were orphaned and WordPress refused them. `ZDZ_Core_Settings::get_instance();` now runs immediately after its `require_once` in `functions.php`, registering the parent first.

**User Management rendered blank.** Its JavaScript was gated on the hook suffix `zorderz_page_ts-user-management`, a leftover `ts-` id from the rename, while the page registers as `zorderz_page_zdz-user-management`. Corrected the guard.

**The theme still said "TS."** `style.css` read `Zorderz - TS - Core`; renamed to **Zorderz Core**.

## Apps (Zorderz Apps): 1.1.1 → 1.1.9

**1.1.1, de-branding.** Bundle plugin header `Zorderz - TS - Apps` → **Zorderz Apps**. (Several individual app headers still carry the `TS` prefix; sweep pending.)

**1.1.2 to 1.1.4, Estimates works with no billing API.** The front-end hung forever on a failed call (added a `.catch`); creating an estimate required FreshBooks (now falls back to a local number when no billing API is connected); and the Ai-free fallback parser prices line items from the Item Engine, with a fix for `$0.00` totals caused by handing the price resolver an item id where it expects a pricing-scheme id.

**1.1.4, Knowledge Vault storage hardening (ZKV 1.6.0 → 1.7.0).** On nginx and other managed hosts the deny-all `.htaccess` is inert, which raised a permanent scary health warning. Vault files now write under an unguessable per-file random subdirectory, and the warning only fires where a file rule can actually apply; the authenticated route plus the random path is the guarantee.

**1.1.5, the vault could accept no documents (severe), and it now feeds Chat (ZKV 1.7.0 → 1.7.1).** Every ingestion path inserts `is_pricing_authority`/`transcript_status`, but those columns were declared only in the version-gated migration, which a fresh install skips, so every insert failed with "Failed to create document record." Both columns now live in the `CREATE TABLE`, so `dbDelta` self-heals. Separately, the assistant reads each turn's data from the empty `zdz_analytics_data_context` filter and the vault's retrieval bridge was never connected to it; the knowledge app now hooks that filter, so an indexed, permitted document is answerable in Chat.

**1.1.6, Estimates get a real document, plus a debug window (ZEST → 1.22.0).** New `ZEST_Doc_Renderer` produces a FreshBooks-style printable document driven entirely by the Business Profile; `zest_estimates` gains the parity fields (org, doc number/date, discount type/value, tax, shipping, terms, converted-invoice link); a printable page at `/?zest_doc=<id>`; a generic importer `POST {ns}/estimate/import`; and an admin debug-log reader `GET {ns}/diagnostics/debug-log`.

**1.1.7, readable logs on a syslog host.** This install routes PHP errors to syslog with debug logging off, so there was no file to read. Opt-in capture (`?capture=on`) redirects `error_log()` into a readable `wp-content/zorderz-debug.log`. Off by default.

**1.1.8, the no-API invoice, estimate→invoice conversion, and payments (ZEST → 1.23.0).** New `zest_invoices` and `zest_payments`, built in the estimate app to reuse the shared renderer (the Stripe-based Invoices app stays an optional connector). Estimate→invoice conversion continues an existing number sequence; payments track partial → paid. Printable invoice at `/?zest_inv=<id>`; endpoints `POST {ns}/invoice/import`, `POST {ns}/estimate/<id>/convert`, `POST {ns}/invoice/<id>/payment`.

**1.1.9, the staff UI (ZEST → 1.24.0).** A self-contained Estimates & Invoices console at `/?zdz_docs=1` with one-click View/Print, Convert, and Record-Payment, built as its own page so it does not disturb the app widget.

## Still true, still not done

`Zorderz - TS -` remains in several individual app headers and the "TS Scheduler" label; the Stock admin page is gated separately; chat turns are synchronous (a slow one can hit a managed-host origin timeout, the 502; async is the next hardening); and the manual PDF import of an existing business's estimates/invoices is designed and endpoint-ready but not yet built.

---

# Zorderz: what changed in 1.1.0

**August 1, 2026**

Advances the theme kernel onto the current internal source. Two features land, both additive and backward-compatible.

## New: the Party roster service

`ZDZ_Party` (`inc/class-zdz-party.php`) is the one authoritative answer to "which person?", used by share pickers, assignees, participant lists and future @-mentions. A person is selectable if they are an **active registered user with a valid email**, and nothing else gates them; in particular selectability is **not** filtered by whether the user happens to hold a given app's grant. That incidental-grant filter is the bug this kills: a real, emailable user who was never granted an app would silently vanish from that app's picker, so a transcript "shared with" them reached no one. Consumers read this list instead of rolling their own `get_users()` + capability filter.

- PHP: `ZDZ_Party::selectable_people( $args )`
- Filter (extend, never re-gate): `zdz_selectable_people`
- REST: `GET /wp-json/zorderz/v1/party/people?search=&exclude=1,2` (returns id/name/initials/role; email stays server-side)

Excludes the shared-kiosk role (`zdz_general`) and users flagged inactive (`zdz_inactive` user-meta, with a read-time alias to the pre-rename `ts_inactive` key). Contract: `PARTY-ROSTER-CONTRACT-v1.md`.

## New: Connected Calendars in Settings → App Authorizations

The App Authorizations section (FreshBooks / Nutshell) can now show a per-user **Connected Calendars** card. The `app-authorizations` REST payload runs through a new **`zdz_app_authorizations`** filter (`$data`, `$user_id`) so a plugin can register its own authorization entries; the card renders only when a plugin adds a `calendars` entry, so it is a clean no-op otherwise. The button deep-links into a scheduler plugin's own connect modal via the query var **`?zdz_connect_calendar=open`**; no OAuth lives in the theme, it just routes into the scheduler's per-user flow.

## Deferred

The rest of the internal 2.38.0 delta (the widget-header navigation change, the wrapping status-chip palette, the layout-shift guards, the jump-link helper and the mobile tap-target/label utilities) is identity-free cosmetic/UX polish and is not part of this release. It still carries the old `.ts-*` class and helper names and will be renamed when it is ported.

---

# Zorderz: what changed in 1.0.1

**`zorderz-theme-1.0.1.zip` · `zorderz-apps-1.0.1.zip` · July 27, 2026**

Upgrade both. Install the theme first, then the plugin; that order still matters and is still not enforced.

1.0.0 was the first build that could be installed on a real site. 1.0.1 is what came back from actually installing it. One of the fixes below is the difference between the app working and every server call silently failing.

---

## Read this first: the app was calling the wrong address

**The photo upload was not broken. Nothing was reaching the server at all.**

The rename from the old identifiers moved every route registration to the `zorderz/v1` namespace, but missed the four places that hand the base URL to the browser. Those still said `ts/v1`. So the server was publishing routes at one address while the app asked at another, and every single request (uploads, dashboard data, preferences, KPI figures, the orchestrator) returned a clean 404.

The reason nothing appeared in the debug log is that **nothing went wrong server-side**. WordPress was asked for a route that does not exist, answered correctly, and had nothing to report. The failure lived entirely in the gap between what the browser asked for and what the server publishes, which is exactly the kind of fault a log cannot show you.

Fixed in all four places (the main app, the login bridge, the admin dashboard, and the integration-health panel), and the namespace is now a single `ZDZ_REST_NS` constant rather than a string repeated across files that have to agree. The boot test now asserts that the base handed to the browser matches a namespace the server actually answers on, so this class of fault fails loudly instead of quietly.

**Also set Settings → Permalinks → Post name.** Plain permalinks make `/wp-json/` return your homepage as HTML. WordPress routes around it correctly so nothing is broken, but plenty of things assume pretty permalinks, including the generated app manifest at `/zdz-manifest.json`.

---

## New: the Business Profile

1.0.0 removed roughly 150 hardcoded company strings and left a nameless app. This is where those values now live.

**Zorderz → Business Profile** holds names, contact details, domains, outgoing mail identities, locale, logo artwork and the colour palette. Every field replaces something that used to be typed into a PHP file. Out of the box everything is neutral or derived from WordPress itself (your site title, admin email, host, timezone), so a fresh install is coherent rather than empty and contains no company's details anywhere.

A blank field means *inherit*, and the screen shows you what it is currently inheriting, so blank never reads as *nothing*.

### The palette

The stylesheet derives every themed colour from an eleven-step brand ramp across all four display modes, so replacing those eleven values re-skins the entire interface without touching CSS. Seven dashboard tile colours work the same way. Only values you actually change are emitted, so an untouched palette costs nothing.

### Logo artwork

Four slots: **wide 2:1** and **square 1:1**, each in **dark ink** and **light ink**.

Ink, not background. A "dark logo" is drawn in dark colours and therefore belongs on a light surface; a "light logo" is the white version for the topbar. That inversion trips everyone up, so nothing in the code asks you to think about it; a caller says which surface it is drawing on and the right file is chosen.

Supply one file and everything falls back to it, laid out at its real proportions rather than stretched. The home-screen icon is the one refusal: it takes a square or the default, because a wordmark squashed into a launcher tile looks worse than no logo. The settings screen shows a live table of what will actually be used where, and tells you if a PNG's proportions do not match its slot: a note, not a rejection.

---

## New: Identity Packs

**Zorderz → Identity Pack** is the same thing from files instead of a form. A pack is a folder of `.yml` or `.json` describing a business; applying one turns a blank install into that business's app.

Preview shows the real before-and-after of every value that would change. Applying requires typing `APPLY`, takes a snapshot first, and is logged. Revert restores exactly what was there.

**A pack cannot create, rename or remove a role.** Role slugs belong to the platform and several are matched as literal strings by security checks, so a pack that could rename one could silently switch off a privacy boundary. A pack may relabel a role and choose which apps it opens with. That is the whole surface, and there is a test that tries to break it.

Two of the fifteen pack files are read by this version: `profile` and `org`. The other thirteen are recognised and reported, never silently ignored, so a pack written for a later version loses nothing by being loaded now. Anything a pack carries that this version cannot use is listed on the preview screen.

An example pack ships inside the theme. Apply it on a throwaway install to watch the whole interface change colour, then revert.

---

## Fixed

**The sidebar said `TS`.** With no logo uploaded, the nav button rendered one company's initials as the platform default, on every install anywhere. Now derived from the business name. It was too short a string for any name-based scan to catch; it took looking at a running install.

**The nav and login logos ignored the Business Profile.** They read only the old theme mods, so the artwork system was not actually wired to the two places a logo appears. Both now read the profile first, with the theme mods kept as an upgrade fallback.

**The login screen hardcoded one company's tagline.** Now reads the profile.

**Setting a palette did nothing.** The brand CSS printed *before* the stylesheet. Both target `:root`, so equal specificity means the last one wins, and the last one was `style.css`. Silently, with no error. Now attached to the stylesheet's own handle, which WordPress prints immediately after it.

**Reverting a pack appeared to do nothing.** Revert wrote the option directly rather than through the setter, so the request-level cache was never cleared and every read afterwards returned the profile that had just been replaced.

**The pack reader mangled quoted values followed by comments.** It stripped comments only from unquoted values, so `name: "Acme"   # note` came back as the entire line. A business applying a well-commented pack would have found itself literally named `"Acme"   # note`.

**The pack reader did not understand `{ all: true }`**, which is exactly how a pack says "this role gets every app". Inline maps were unsupported and the grant was dropped. It looked correct because the default for an owner is also *every app*; only a test that asserted on the preview rather than the outcome caught it.

**Nine employee first names were shipping in the public files.** They were worked examples in code comments, and one was in a *visible* admin string on the user-profile screen. The 1.0.0 gate scanned for the company name, the address and the phone numbers; it did not scan for the roster. All replaced with neutral placeholders, the visible string rewritten, and the gate widened.

**Four duplicate module files were shipping in the plugin.** The bundle renames each module's entry file to `app.php`, so a plain recursive copy dropped the original in beside it and the loader kept reading the stale one. Two copies of the same classes on disk is one careless include away from a fatal. Removed, and the packaging is now a script that does the rename explicitly rather than a hand-run copy.

**The static `manifest.json` was still shipping** with one company's name and colours, and icon paths pointing at a theme folder that no longer existed. Removed: the manifest is generated from the profile at `/zdz-manifest.json`.

---

## Changed

**Role app-grants can say "everything" explicitly.** Stored as a literal `true` rather than reusing `null`, so *not configured* and *configured to everything* can never be the same value.

**`ts-jobs` → `zdz-jobs` is now in the rename map**, ahead of the app itself. Grants naming it already exist; mapping it now means they survive the port instead of pointing at a dead id on the day it lands.

**Version renumbered from 2.37.1 to 1.0.1.** That 2.x number was the version lineage of the private app Zorderz was extracted from. Shipping it on a first public release overstates how long Zorderz has existed and hides that this is a new line. The update check compares the version string for inequality rather than ordering, so going backwards is mechanically safe; it fires the reload prompt once, which is correct. The visible consequence is that an install coming from the private app will show a smaller number than it did before.

---

## Still true, still not fixed

- **No chat app.** The Chat item in the nav is injected by the analytics plugin, which is the heaviest component to generalise and is not in this batch. `front-page.php` says so at the point where it would appear. Team (messaging) is present and working.
- **The login screen has a hardcoded blue palette** with no connection to the brand ramp. Cosmetic, but worth doing before showing that screen to anyone as "themed".
- **The messaging plugin still registers under `tsim/v1`.** Self-consistent (it registers and calls the same string, so nothing is broken), but the full rename should eventually pick it up.
- **The Business Profile has more fields than consumers.** The palette, manifest, mail senders, logos, role labels and grants are read by shipped code. The address, licence line and review links are stored and available, but the surfaces that consumed them are not in this batch.
- **The bundled place dataset is San Diego.** The geocoder mechanism is generic; the dataset should become a swappable pack.
- **Messaging seeds channels** named after one company's departments. Should become a setup choice.
- **Revert is one step deep.** A second apply replaces the first apply's snapshot. Clearing the profile is the way back to neutral.

---

## Verification

Four scenarios against the WordPress kernel harness, all clean: upgrade path, fresh install, the full Identity Pack lifecycle (74 assertions), and the packaging gate.

| | |
|---|---|
| PHP files parsed | 71, zero failures |
| Boot errors / warnings / diagnostics | 0 / 0 / 0 |
| Apps registered | 5 |
| REST routes | 49 under `zorderz/v1` |
| REST base agreement | asserted: browser base must match a registered namespace |
| Company or roster strings in either zip | 0 across 30 terms |

The harness is not WordPress. It proves nothing fatals, that data migrates, that apps register, that routes and tables get created, and that the pack lifecycle behaves. It does not prove the dashboard renders, that JavaScript behaves, or that uploads work, which is why installing it found the namespace bug and the harness did not.
