# Installing Zorderz: a guide for an Ai agent

This document is written for an autonomous Ai agent installing Zorderz onto a WordPress site start to finish. It is deliberately precise and ordered. Every step has an **action** and a **verification**; do not advance until the current step's verification passes. The procedure is idempotent where possible, so a re-run after a partial failure is safe.

Zorderz is an integration platform, not a replacement for a business's tools. Installing it means: getting the platform onto the site, confirming the REST surface answers, and then giving the install its data, either by importing a Company Data bundle or by configuring it by hand. It does **not** mean inventing any business data; nothing about a real business is seeded, and you must not fabricate any.

Throughout, `SITE` is the site's base URL (for example `https://example.test`). Replace it.

---

## The shape of an install (read this first)

There are two independent choices. Make both before you start.

**How the platform gets on the site:**
- **One upload (default, v1.6.0+).** Upload and activate the theme only. The active theme installs and activates the apps bundle for you (it ships a copy inside itself). This is the fewest steps and the recommended path.
- **Two artifacts (fallback).** On a host where the theme cannot write to `wp-content/plugins`, or when you install by WP-CLI, install the theme and then the apps plugin as two separate zips. The auto-installer detects it cannot write and tells you to do this; nothing is lost.

**How the install gets its data:**
- **Import a Company Data bundle (fast, complete).** If you have a bundle exported from another Zorderz install, import it. It restores the entire business (settings, catalog, roster, estimates, orders, receipts, chats, knowledge base, internal messaging, media files) and the WordPress site settings in one step. This is the fastest way to a populated, ready-to-use install. See step 8A.
- **Configure by hand (fresh business).** No bundle? Give the install an identity through the Business Profile or an Identity Pack, then fill the catalog and roster yourself. See step 8B.

Either way, connection **secrets are never carried** and must be reconnected on the new install (step 9).

---

## 0. Conventions

- **Three execution paths are given where they differ: WP-CLI, REST/HTTP, and browser (wp-admin).** Use WP-CLI if you have shell access to the host; it is the most reliable. Use REST/HTTP if you only have network access plus an administrator account. Use the browser path if you are a browser-driving agent; the wp-admin UI locations are named. **The Company Data export/import (step 8A) are wp-admin features and have no WP-CLI command; drive them in the browser or through a logged-in session that carries their nonces.**
- **Authenticated REST calls** use an [Application Password](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/): `curl -u "ADMIN_USER:APP_PASSWORD" ...`. Create one under **Users -> Profile -> Application Passwords**, or with `wp user application-password create ADMIN_USER zorderz-setup --porcelain`.
- **Browser-driving agents, four things that will bite you if you do not know them:**
  1. **Do not click a file input.** It opens a native OS file picker you cannot see or drive. Set the file on the `<input type=file>` element directly (your framework's file-upload primitive), then submit.
  2. **If a submit button click seems to miss, submit the form programmatically** (`inputElement.form.submit()`). WordPress upload forms (theme, plugin, and the import form) are ordinary forms; this is reliable.
  3. **After an upload installs, follow the result screen's "Activate" / "Activate Plugin" link** rather than hunting for a button.
  4. **Managed hosts (for example WP Engine) log in to wp-admin by SSO** (no password to type), and may return **transient 502s that clear on retry**. A 502 on one request while others succeed, and that recovers on its own, is host infrastructure, not a Zorderz fault. Wrap requests in a bounded retry; do not treat a self-clearing 502 as a code failure.
- **Never disable TLS verification** and never route around an OAuth step. Guarded actions (applying an Identity Pack, running a real import) require a human-style confirmation or a nonce by design.
- **Fail loudly.** If a verification fails, stop and diagnose using the Failure Handling section; do not proceed and hope.

---

## 1. Preconditions

**Action:** Confirm the target environment meets the floor.

| Requirement | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 8.0 |
| Database | MySQL 5.7+ or MariaDB 10.3+ |
| Access | shell + WP-CLI, **or** an admin account (browser or Application Password) |

**Verify:**

```bash
wp core version          # -> 6.0 or higher
php -v                   # -> PHP 8.0.x or higher
wp db check              # -> database reachable
```

REST-only equivalent (WordPress does not expose its version unauthenticated by default, so confirm reachability and that the site is a REST-enabled WordPress):

```bash
curl -fsS "SITE/wp-json/" | head -c 200      # -> JSON, not HTML
```

If any check fails, stop: this environment cannot run Zorderz yet.

---

## 2. Obtain the artifact(s)

**Action:** Download the release zip(s) to the host (or note their local paths).

- `zorderz-theme-1.6.0.zip`: the theme (platform kernel + Core services). **On the default one-upload path this is the only file you need**, because the theme carries the apps bundle inside it.
- `zorderz-apps-1.6.0.zip`: the apps bundle (18 apps) on its own. You need this only for the two-artifact fallback (step 6) or to update the apps independently.

**Verify** each zip you have is intact with the expected top-level folder:

```bash
unzip -Z1 zorderz-theme-1.6.0.zip | head -1                     # -> zorderz/
unzip -Z1 zorderz-theme-1.6.0.zip | grep -m1 'zorderz/style.css'
unzip -Z1 zorderz-theme-1.6.0.zip | grep -m1 'zorderz/bundled/zorderz-apps/zorderz-apps.php'   # the vendored apps
unzip -Z1 zorderz-apps-1.6.0.zip  | grep -m1 'zorderz-apps/zorderz-apps.php'                   # only if using the fallback
```

Theme slug is `zorderz`; plugin slug is `zorderz-apps`.

> Building from source instead of a release? Run `./build.sh`. It reads the version from `style.css` and produces both zips, vendoring the apps into the theme. A raw source checkout has no vendored apps, so on a raw checkout the auto-installer will fall back to the two-artifact path (step 6); a built release zip will not.

---

## 3. Set pretty permalinks to Post name

Do this **before** verifying any route. Under WordPress's default plain permalinks, `/wp-json/` returns the homepage as HTML and `/zdz-manifest.json` will not resolve. (An import in step 8A will also set this for you, but set it first so your verifications work.)

**Action (WP-CLI):**

```bash
wp rewrite structure '/%postname%/' --hard
wp rewrite flush --hard
```

**Action (wp-admin):** Settings -> Permalinks -> **Post name** -> Save.

**Verify:**

```bash
wp option get permalink_structure         # -> /%postname%/
```

---

## 4. Install and activate the theme: FIRST (this also brings the apps)

The theme is the platform. It must be active before the plugin, because it defines the roles, the shared media store, the plugin-registration API and the `zorderz/v1` REST namespace the apps depend on. **This ordering is not enforced by WordPress.** On the one-upload path the ordering takes care of itself: the theme is active first (by definition) and then installs the apps.

**Action (WP-CLI):**

```bash
wp theme install /path/to/zorderz-theme-1.6.0.zip --activate
```

**Action (wp-admin / browser):** Appearance -> Themes -> Add New -> Upload Theme -> choose the theme zip -> Install -> **Activate**.

On activation, and again on the next admin page load, the theme's `ZDZ_Apps_AutoInstall` copies the vendored apps into `wp-content/plugins/zorderz-apps` and activates them. It acts **once**, never overwrites an apps copy that is already present, and never re-activates apps you later deactivate. An admin notice reports what happened.

**Verify** the theme is active and the REST namespace exists:

```bash
wp theme list --status=active --field=name        # -> includes zorderz
wp theme get zorderz --field=version              # -> 1.6.0
curl -fsS "SITE/wp-json/" | grep -o '"zorderz/v1"' # -> "zorderz/v1"
```

If `zorderz/v1` is absent, the theme is not active; do not continue.

**Then verify the apps came up automatically:**

```bash
wp plugin list --status=active --field=name       # -> includes zorderz-apps
wp plugin get zorderz-apps --field=version        # -> 1.6.0
```

- If `zorderz-apps` is active, **skip step 6** and go to step 7.
- If it is not active and you saw an admin notice that the theme could not write to the plugins folder (locked filesystem, WP-CLI theme install without a later admin load, or a raw source checkout), do step 6.

---

## 5. Nudge the auto-installer (browser installs only)

The auto-installer runs on `admin_init`. If you installed the theme by WP-CLI, load any wp-admin page once (for example `SITE/wp-admin/`) so it fires, then re-run the step-4 apps verification. If the apps are now active, skip step 6.

---

## 6. Install and activate the apps plugin by hand (fallback only)

Only if step 4 showed the apps did not auto-install.

**Action (WP-CLI):**

```bash
wp plugin install /path/to/zorderz-apps-1.6.0.zip --activate
```

**Action (wp-admin / browser):** Plugins -> Add New -> Upload Plugin -> choose the apps zip -> Install -> **Activate Plugin**.

Activation runs each app's first-run work (table creation, scheduling) and flushes rewrite rules. Each app registers with the theme on `after_setup_theme`; an app whose dependencies are missing declines to register rather than failing. There must be **no** admin notice reading "Zorderz Apps needs the Zorderz theme to be active"; if you see it, the theme is not active (step 4).

---

## 7. Flush rewrite rules and verify the platform is answering

```bash
wp rewrite flush --hard
```

Run all of these; they are the core acceptance checks.

**Public (no auth):**

```bash
# 1. The generated web app manifest resolves as JSON (proves permalinks + theme).
curl -fsS -H 'Accept: application/manifest+json' "SITE/zdz-manifest.json" | head -c 200
#    Expect a JSON object. If you get HTML, permalinks are wrong (step 3).

# 2. The REST index lists the Zorderz namespace.
curl -fsS "SITE/wp-json/" | grep -o '"zorderz/v1"'   # -> "zorderz/v1"
```

**Authenticated (admin Application Password):**

```bash
# 3. The Party roster service answers.
curl -fsS -u "ADMIN_USER:APP_PASSWORD" "SITE/wp-json/zorderz/v1/party/people"
#    Expect a JSON array (may be small on a fresh install). 200, not 404/401.

# 4. The Item Engine publishes its (empty) catalog and the fixed two-type model.
curl -fsS -u "ADMIN_USER:APP_PASSWORD" "SITE/wp-json/zorderz/v1/item-engine/catalog"
#    Expect {"version":...,"empty":true,"types":["product","service"],"subtypes":[...],"items":[]}
```

If checks 1-4 pass, Zorderz is installed and running. Now populate it: step 8A if you have a bundle, otherwise step 8B.

---

## 8A. Fast path: restore a Company Data bundle (migration / populate)

If you have a bundle exported from another Zorderz install (`zorderz-data-*.zip`, or `.json` when it carries no media), this one step lands the entire business, and the WordPress site settings, onto the fresh install. It is the fastest way to a working, populated site.

**Where:** wp-admin -> **Tools -> Zorderz Data** (`SITE/wp-admin/tools.php?page=zdz-data-portability`). This is a wp-admin feature; there is no WP-CLI command. The form posts to `admin-post.php` (`action=zdz_data_import`) with the file field `zdz_bundle`, a `dry_run` checkbox, and the `zdz_data_import` nonce.

**Action, in order:**

1. **Dry run first (writes nothing).** Attach the bundle to the file input, leave **Dry run** checked, submit. Read the "Would restore:" line. It reports counts for options, site settings, table rows, users, attachments, media files, post-type records, and taxonomy terms. Confirm they are what you expect. A dry run that errors here means the bundle is unreadable on this server; stop and diagnose before writing anything.
2. **Real import.** Attach the bundle again (the file input clears on reload), **uncheck** Dry run, submit. Wait for "Import complete." with the matching "Restored:" counts.

**Four things that are true of an import and will confuse you if you do not expect them:**

- **You stay logged in even though the import replaces the admin account.** A bundle restores its own users with their original ids, so the account you were using is replaced by the imported owner. Zorderz keeps your session alive across this (it re-issues your auth cookie as the acting user), so you do **not** get logged out. If you are driving a managed host by SSO and somehow do land on a login screen, just SSO back in; the import already finished.
- **WordPress site settings and media files apply automatically.** The site title, tagline, timezone, and permalink structure come across and are applied (rewrite rules are flushed), and the media files (originals and every thumbnail size) are extracted into `wp-content/uploads` so images resolve. You do not repeat step 3, and you do not copy media by hand.
- **Secrets do not come across, by design.** The Poe key and any FreshBooks / Nutshell / calendar credentials are never in a bundle. Every integration will read as not-connected until you reconnect it (step 9). This is the security promise, not a bug.
- **`.zip` vs `.json`.** A bundle with media is a `.zip` (JSON plus an `uploads/` tree); without media, or exported on a server without ZipArchive, it is a plain `.json`. The import accepts either.

**Browser-driving note:** set the bundle on the `zdz_bundle` file input directly (do not click it), toggle the Dry run checkbox by setting its `checked` property, and submit with `input.form.submit()` if a button click misses. Managed-host 502s during the import are transient; retry.

**Verify the import (counts should match the source):**

```bash
# The catalog is now populated (empty:false, real item count).
curl -fsS -u "ADMIN_USER:APP_PASSWORD" "SITE/wp-json/zorderz/v1/item-engine/catalog" | head -c 200
# The roster now returns the imported people.
curl -fsS -u "ADMIN_USER:APP_PASSWORD" "SITE/wp-json/zorderz/v1/party/people" | head -c 200
```

The "Restored:" line the tool prints is the authoritative per-area count; it should match the source install's export. A spot check that an imported image loads (`SITE/wp-content/uploads/<year>/<month>/<file>` returns 200 `image/*`) confirms media rehydrated. Then go to step 9; an imported install is not finished until its secrets are reconnected.

---

## 8B. Configure by hand (fresh business, no bundle)

A fresh install is a coherent but nameless blank slate: defaults are inherited from WordPress (site title, admin email, host, timezone) and no business is named anywhere. **Do not fabricate business data**: use values the operator supplied, or leave fields blank to inherit.

### Option A: Business Profile (by form)

**wp-admin:** Zorderz -> Business Profile. Set names, contact details, domains, mail senders, locale, logo artwork and the colour palette. A blank field means *inherit*; the screen shows what it is inheriting.

### Option B: Identity Pack (by file): preferred for reproducible setup

An Identity Pack is a business as data: a folder of YAML or JSON. A full pack is fifteen files; this version reads `profile.yml` and `org.yml` and reports (never silently ignores) the rest.

1. Place the pack where it survives updates:

   ```
   wp-content/zorderz-identity/<pack-name>/profile.yml
   wp-content/zorderz-identity/<pack-name>/org.yml        # optional
   ```

   A pack of one file (`profile.yml`) is valid. Copy the shipped `identity-packs/example-business/` inside the theme as a template.

2. Apply it: **Zorderz -> Identity Pack** -> select the pack -> review the previewed before-and-after of every value that would change -> type **`APPLY`**.

   Applying takes a snapshot first, so **Revert** restores exactly what was there. **This confirmation is intentionally not scriptable through a silent API**: an agent applies a pack by driving this screen, which keeps a human-auditable trail.

**Constraints a valid pack must respect** (a pack that violates these is rejected):
- It may not create, rename or remove a role; role slugs belong to the platform.
- It may not carry secrets. `connections.yml` holds `secret://` references, never credential values.
- It may not carry customer records or employees' personal contact details.

Then fill the catalog (Zorderz -> Item Engine) and roster as needed. **Verify** the identity took:

```bash
curl -fsS "SITE/zdz-manifest.json"       # name/short_name now reflect the applied profile
```

---

## 9. Connect providers and the Ai gateway (required after an import)

**wp-admin:** Zorderz -> Settings -> **App Authorizations** (Connections). This is the single place external systems are registered; apps read credentials from here rather than storing their own. **After a bundle import this step is mandatory**, because the bundle carried no secrets, so every integration starts blank exactly as on a fresh install.

- **Billing / CRM / scheduling:** register the provider. API-key providers can be set through the connection form; OAuth providers require completing the provider's consent flow in a browser; do not attempt to bypass it.
- **Ai gateway:** the v1 gateway is **Poe** (one key reaches most models). Enter the key here, then open the **Model Registry** and assign a model to each task slot. The gateway is provider-agnostic and the slots are configurable, so no model or vendor is hardwired.

Connections are optional for a first boot; the apps stand alone and hook into external systems when they exist. But the Chat assistant and the billing-backed apps (Invoices, Estimates, Receipts) need their respective providers to do useful work.

---

## 10. Final acceptance checklist

The install is **done** when all of the following hold:

- [ ] `wp theme get zorderz --field=version` -> `1.6.0`, and the theme is active.
- [ ] `wp plugin get zorderz-apps --field=version` -> `1.6.0`, and the plugin is active (auto-installed, or by step 6).
- [ ] No admin notice about a missing theme or a failed app load.
- [ ] `SITE/zdz-manifest.json` returns JSON (not HTML).
- [ ] `SITE/wp-json/` lists `zorderz/v1`.
- [ ] `GET zorderz/v1/party/people` returns 200.
- [ ] `GET zorderz/v1/item-engine/catalog` returns 200 with `types: ["product","service"]`.
- [ ] Data supplied: either a bundle was imported (step 8A) with matching "Restored:" counts, or an identity was configured (step 8B), if the operator provided one.
- [ ] Providers reconnected (step 9), if the operator supplied credentials, especially after an import.

When every box is checked, stop. The site is a working Zorderz install.

---

## Idempotency and failure handling

**Re-running is safe.** Updating in place:

```bash
wp theme install /path/to/zorderz-theme-1.6.0.zip --force
wp plugin install /path/to/zorderz-apps-1.6.0.zip --force   # only if you manage the apps separately
wp rewrite flush --hard
```

Uploading a newer bundle over an existing one does not fire WordPress's activation hook, so the code re-runs each app's first-run work on the next load when its stored version differs; no manual migration step is needed. The apps auto-installer records that it has acted, so a theme update does not re-copy or re-activate the apps, and it will not fight an operator who deactivated them.

**Symptom -> cause -> fix:**

| Symptom | Cause | Fix |
|---|---|---|
| A single request returns 502 while others succeed and it clears on retry | Transient origin hiccup on a managed host (not Zorderz) | Retry with backoff; do not treat as a code failure |
| Theme active but apps not installed, no notice | WP-CLI theme install, auto-installer has not run yet | Load any wp-admin page once (step 5), or do step 6 |
| Admin notice: theme could not write to the plugins folder | Locked filesystem / restrictive host | Do the two-artifact fallback (step 6); everything else is identical |
| `/zdz-manifest.json` returns your homepage as HTML | Permalinks not set to Post name | Step 3, then `wp rewrite flush --hard` |
| Every `zorderz/v1` call returns 404, but `/wp-json/` lists the namespace | Rewrite rules stale | `wp rewrite flush --hard` |
| `/wp-json/` does **not** list `zorderz/v1` | Theme not active | Step 4; the namespace is registered by the theme |
| Apps loaded but no tiles; "needs the Zorderz theme" notice | Plugin active before/without the theme | Activate the theme (step 4) |
| Import dry run errors before any write | Bundle unreadable on this server (no ZipArchive for a `.zip`, or corrupt file) | Fix the server (enable the PHP zip extension) or re-export; never proceed to a real import |
| Bounced to a login screen right after an import | Session edge case on a managed host after the owner swap | Log back in (SSO if available); the import already completed, verify with step 8A checks |
| Authenticated REST call returns 401 | Bad or missing Application Password | Recreate it; use `-u "USER:APP_PASSWORD"` |
| One app shows an error notice on load | That single app failed to load | It is isolated by design; the others keep working; read the notice, fix that app |

Do not disable TLS verification, do not unset the proxy, and do not retry an organization policy denial; surface it instead.

---

## What you must never do

- **Never invent business data.** No names, catalog items, prices, people, territories or rules unless the operator supplied them (or they arrived in a bundle). The platform ships empty on purpose.
- **Never put a specific company, person, customer or brand into code, comments, prompts or a committed file.** Business-specific values belong in the Business Profile, an Identity Pack, or a Company Data bundle, which are configuration and data, not code.
- **Never paste a credential into an app, and never try to smuggle secrets into a bundle.** Credentials live only in Connections, and a bundle deliberately excludes them; reconnect on the new install instead.
