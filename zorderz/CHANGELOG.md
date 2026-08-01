# Zorderz — what changed in 1.1.0

**August 1, 2026**

Advances the theme kernel onto the current internal source. Two features land, both additive and backward-compatible.

## New: the Party roster service

`ZDZ_Party` (`inc/class-zdz-party.php`) is the one authoritative answer to "which person?" — used by share pickers, assignees, participant lists and future @-mentions. A person is selectable if they are an **active registered user with a valid email**, and nothing else gates them; in particular selectability is **not** filtered by whether the user happens to hold a given app's grant. That incidental-grant filter is the bug this kills: a real, emailable user who was never granted an app would silently vanish from that app's picker, so a transcript "shared with" them reached no one. Consumers read this list instead of rolling their own `get_users()` + capability filter.

- PHP: `ZDZ_Party::selectable_people( $args )`
- Filter (extend, never re-gate): `zdz_selectable_people`
- REST: `GET /wp-json/zorderz/v1/party/people?search=&exclude=1,2` (returns id/name/initials/role; email stays server-side)

Excludes the shared-kiosk role (`zdz_general`) and users flagged inactive (`zdz_inactive` user-meta, with a read-time alias to the pre-rename `ts_inactive` key). Contract: `PARTY-ROSTER-CONTRACT-v1.md`.

## New: Connected Calendars in Settings → App Authorizations

The App Authorizations section (FreshBooks / Nutshell) can now show a per-user **Connected Calendars** card. The `app-authorizations` REST payload runs through a new **`zdz_app_authorizations`** filter (`$data`, `$user_id`) so a plugin can register its own authorization entries; the card renders only when a plugin adds a `calendars` entry, so it is a clean no-op otherwise. The button deep-links into a scheduler plugin's own connect modal via the query var **`?zdz_connect_calendar=open`** — no OAuth lives in the theme, it just routes into the scheduler's per-user flow.

## Deferred

The rest of the internal 2.38.0 delta — the widget-header navigation change, the wrapping status-chip palette, the layout-shift guards, the jump-link helper and the mobile tap-target/label utilities — is identity-free cosmetic/UX polish and is not part of this release. It still carries the old `.ts-*` class and helper names and will be renamed when it is ported.

---

# Zorderz — what changed in 1.0.1

**`zorderz-theme-1.0.1.zip` · `zorderz-apps-1.0.1.zip` · July 27, 2026**

Upgrade both. Install the theme first, then the plugin — that order still matters and is still not enforced.

1.0.0 was the first build that could be installed on a real site. 1.0.1 is what came back from actually installing it. One of the fixes below is the difference between the app working and every server call silently failing.

---

## Read this first: the app was calling the wrong address

**The photo upload was not broken. Nothing was reaching the server at all.**

The rename from the old identifiers moved every route registration to the `zorderz/v1` namespace, but missed the four places that hand the base URL to the browser. Those still said `ts/v1`. So the server was publishing routes at one address while the app asked at another, and every single request — uploads, dashboard data, preferences, KPI figures, the orchestrator — returned a clean 404.

The reason nothing appeared in the debug log is that **nothing went wrong server-side**. WordPress was asked for a route that does not exist, answered correctly, and had nothing to report. The failure lived entirely in the gap between what the browser asked for and what the server publishes, which is exactly the kind of fault a log cannot show you.

Fixed in all four places — the main app, the login bridge, the admin dashboard, and the integration-health panel — and the namespace is now a single `ZDZ_REST_NS` constant rather than a string repeated across files that have to agree. The boot test now asserts that the base handed to the browser matches a namespace the server actually answers on, so this class of fault fails loudly instead of quietly.

**Also set Settings → Permalinks → Post name.** Plain permalinks make `/wp-json/` return your homepage as HTML. WordPress routes around it correctly so nothing is broken, but plenty of things assume pretty permalinks — including the generated app manifest at `/zdz-manifest.json`.

---

## New: the Business Profile

1.0.0 removed roughly 150 hardcoded company strings and left a nameless app. This is where those values now live.

**Zorderz → Business Profile** holds names, contact details, domains, outgoing mail identities, locale, logo artwork and the colour palette. Every field replaces something that used to be typed into a PHP file. Out of the box everything is neutral or derived from WordPress itself — your site title, admin email, host, timezone — so a fresh install is coherent rather than empty and contains no company's details anywhere.

A blank field means *inherit*, and the screen shows you what it is currently inheriting, so blank never reads as *nothing*.

### The palette

The stylesheet derives every themed colour from an eleven-step brand ramp across all four display modes, so replacing those eleven values re-skins the entire interface without touching CSS. Seven dashboard tile colours work the same way. Only values you actually change are emitted, so an untouched palette costs nothing.

### Logo artwork

Four slots: **wide 2:1** and **square 1:1**, each in **dark ink** and **light ink**.

Ink, not background. A "dark logo" is drawn in dark colours and therefore belongs on a light surface; a "light logo" is the white version for the topbar. That inversion trips everyone up, so nothing in the code asks you to think about it — a caller says which surface it is drawing on and the right file is chosen.

Supply one file and everything falls back to it, laid out at its real proportions rather than stretched. The home-screen icon is the one refusal: it takes a square or the default, because a wordmark squashed into a launcher tile looks worse than no logo. The settings screen shows a live table of what will actually be used where, and tells you if a PNG's proportions do not match its slot — a note, not a rejection.

---

## New: Identity Packs

**Zorderz → Identity Pack** is the same thing from files instead of a form. A pack is a folder of `.yml` or `.json` describing a business; applying one turns a blank install into that business's app.

Preview shows the real before-and-after of every value that would change. Applying requires typing `APPLY`, takes a snapshot first, and is logged. Revert restores exactly what was there.

**A pack cannot create, rename or remove a role.** Role slugs belong to the platform and several are matched as literal strings by security checks, so a pack that could rename one could silently switch off a privacy boundary. A pack may relabel a role and choose which apps it opens with. That is the whole surface, and there is a test that tries to break it.

Two of the fifteen pack files are read by this version — `profile` and `org`. The other thirteen are recognised and reported, never silently ignored, so a pack written for a later version loses nothing by being loaded now. Anything a pack carries that this version cannot use is listed on the preview screen.

An example pack ships inside the theme. Apply it on a throwaway install to watch the whole interface change colour, then revert.

---

## Fixed

**The sidebar said `TS`.** With no logo uploaded, the nav button rendered one company's initials as the platform default — on every install anywhere. Now derived from the business name. It was too short a string for any name-based scan to catch; it took looking at a running install.

**The nav and login logos ignored the Business Profile.** They read only the old theme mods, so the artwork system was not actually wired to the two places a logo appears. Both now read the profile first, with the theme mods kept as an upgrade fallback.

**The login screen hardcoded one company's tagline.** Now reads the profile.

**Setting a palette did nothing.** The brand CSS printed *before* the stylesheet. Both target `:root`, so equal specificity means the last one wins — and the last one was `style.css`. Silently, with no error. Now attached to the stylesheet's own handle, which WordPress prints immediately after it.

**Reverting a pack appeared to do nothing.** Revert wrote the option directly rather than through the setter, so the request-level cache was never cleared and every read afterwards returned the profile that had just been replaced.

**The pack reader mangled quoted values followed by comments.** It stripped comments only from unquoted values, so `name: "Acme"   # note` came back as the entire line. A business applying a well-commented pack would have found itself literally named `"Acme"   # note`.

**The pack reader did not understand `{ all: true }`** — which is exactly how a pack says "this role gets every app". Inline maps were unsupported and the grant was dropped. It looked correct because the default for an owner is also *every app*; only a test that asserted on the preview rather than the outcome caught it.

**Nine employee first names were shipping in the public files.** They were worked examples in code comments — and one was in a *visible* admin string on the user-profile screen. The 1.0.0 gate scanned for the company name, the address and the phone numbers; it did not scan for the roster. All replaced with neutral placeholders, the visible string rewritten, and the gate widened.

**Four duplicate module files were shipping in the plugin.** The bundle renames each module's entry file to `app.php`, so a plain recursive copy dropped the original in beside it and the loader kept reading the stale one. Two copies of the same classes on disk is one careless include away from a fatal. Removed, and the packaging is now a script that does the rename explicitly rather than a hand-run copy.

**The static `manifest.json` was still shipping** with one company's name and colours, and icon paths pointing at a theme folder that no longer existed. Removed — the manifest is generated from the profile at `/zdz-manifest.json`.

---

## Changed

**Role app-grants can say "everything" explicitly.** Stored as a literal `true` rather than reusing `null`, so *not configured* and *configured to everything* can never be the same value.

**`ts-jobs` → `zdz-jobs` is now in the rename map**, ahead of the app itself. Grants naming it already exist; mapping it now means they survive the port instead of pointing at a dead id on the day it lands.

**Version renumbered from 2.37.1 to 1.0.1.** That 2.x number was the version lineage of the private app Zorderz was extracted from. Shipping it on a first public release overstates how long Zorderz has existed and hides that this is a new line. The update check compares the version string for inequality rather than ordering, so going backwards is mechanically safe — it fires the reload prompt once, which is correct. The visible consequence is that an install coming from the private app will show a smaller number than it did before.

---

## Still true, still not fixed

- **No chat app.** The Chat item in the nav is injected by the analytics plugin, which is the heaviest component to generalise and is not in this batch. `front-page.php` says so at the point where it would appear. Team (messaging) is present and working.
- **The login screen has a hardcoded blue palette** with no connection to the brand ramp. Cosmetic, but worth doing before showing that screen to anyone as "themed".
- **The messaging plugin still registers under `tsim/v1`.** Self-consistent — it registers and calls the same string, so nothing is broken — but the full rename should eventually pick it up.
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
| REST base agreement | asserted — browser base must match a registered namespace |
| Company or roster strings in either zip | 0 across 30 terms |

The harness is not WordPress. It proves nothing fatals, that data migrates, that apps register, that routes and tables get created, and that the pack lifecycle behaves. It does not prove the dashboard renders, that JavaScript behaves, or that uploads work — which is why installing it found the namespace bug and the harness did not.
