# Installing Zorderz: a guide for an Ai agent

This document is written for an autonomous Ai agent installing Zorderz onto a WordPress site start to finish. It is deliberately precise and ordered. Every step has an **action** and a **verification**; do not advance to the next step until the current step's verification passes. The procedure is idempotent where possible, so a re-run after a partial failure is safe.

Zorderz is an integration platform, not a replacement for a business's tools. Installing it means: placing two artifacts in the right order, enabling pretty permalinks, confirming the REST surface answers, and then handing the install its identity. It does **not** mean inventing any business data, nothing about a real business is seeded, and you must not fabricate any.

Throughout, `SITE` is the site's base URL (for example `https://example.test`). Replace it.

---

## 0. Conventions

- **Two execution paths are given for each step: WP-CLI and REST/HTTP.** Use WP-CLI if you have shell access to the host; it is the most reliable. Use REST/HTTP if you only have network access plus an administrator account. A browser-driving agent can perform the same actions through wp-admin; the UI locations are named.
- **Authenticated REST calls** use an [Application Password](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/): `curl -u "ADMIN_USER:APP_PASSWORD" ...`. Create one under **Users → Profile → Application Passwords**, or with `wp user application-password create ADMIN_USER zorderz-setup --porcelain`.
- **Never disable TLS verification** and never route around an OAuth step. Guarded actions (applying an Identity Pack) require a human-style typed confirmation by design and cannot be silently automated; that is intentional.
- **Fail loudly.** If a verification fails, stop and diagnose using the Failure Handling section, do not proceed and hope.

---

## 1. Preconditions

**Action:** Confirm the target environment meets the floor.

| Requirement | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 8.0 |
| Database | MySQL 5.7+ or MariaDB 10.3+ |
| Access | shell + WP-CLI, **or** an admin account + Application Password |

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

## 2. Obtain the two artifacts

**Action:** Download both release zips to the host (or note their local paths).

- `zorderz-theme-1.3.4.zip`: the theme (platform kernel + Core services)
- `zorderz-apps-1.3.4.zip`: the apps bundle (18 apps)

**Verify** each is an intact zip with the expected top-level folder:

```bash
unzip -Z1 zorderz-theme-1.3.4.zip | head -1     # -> zorderz/
unzip -Z1 zorderz-theme-1.3.4.zip | grep -m1 'zorderz/style.css'
unzip -Z1 zorderz-apps-1.3.4.zip  | head -1     # -> zorderz-apps/
unzip -Z1 zorderz-apps-1.3.4.zip  | grep -m1 'zorderz-apps/zorderz-apps.php'
```

Theme slug is `zorderz`; plugin slug is `zorderz-apps`. Use those below.

---

## 3. Set pretty permalinks to Post name

Do this **before** verifying any route. Under WordPress's default plain permalinks, `/wp-json/` returns the homepage as HTML and `/zdz-manifest.json` will not resolve.

**Action (WP-CLI):**

```bash
wp rewrite structure '/%postname%/' --hard
wp rewrite flush --hard
```

**Action (wp-admin):** Settings → Permalinks → **Post name** → Save.

**Verify:**

```bash
wp option get permalink_structure         # -> /%postname%/
```

---

## 4. Install and activate the theme: FIRST

The theme is the platform. It must be active before the plugin, because it defines the roles, the shared media store, the plugin-registration API and the `zorderz/v1` REST namespace the apps depend on. **This ordering is not enforced by WordPress; you must enforce it.**

**Action (WP-CLI):**

```bash
wp theme install /path/to/zorderz-theme-1.3.4.zip --activate
```

**Action (wp-admin):** Appearance → Themes → Add New → Upload Theme → choose the theme zip → Install → **Activate**.

**Verify:**

```bash
wp theme list --status=active --field=name        # -> includes Zorderz
wp theme get zorderz --field=version              # -> 1.3.4
```

Confirm the REST namespace now exists (it is registered by the theme):

```bash
curl -fsS "SITE/wp-json/" | grep -o '"zorderz/v1"'   # -> "zorderz/v1"
```

If `zorderz/v1` is absent here, the theme is not active, do not continue.

---

## 5. Install and activate the apps plugin: SECOND

**Action (WP-CLI):**

```bash
wp plugin install /path/to/zorderz-apps-1.3.4.zip --activate
```

**Action (wp-admin):** Plugins → Add New → Upload Plugin → choose the apps zip → Install → **Activate**.

Activation runs each app's first-run work (table creation, scheduling) and flushes rewrite rules. Each app registers itself with the theme on `after_setup_theme`; an app whose dependencies are missing declines to register rather than failing.

**Verify:**

```bash
wp plugin list --status=active --field=name       # -> includes zorderz-apps
wp plugin get zorderz-apps --field=version        # -> 1.3.4
```

There must be **no** admin notice reading "Zorderz Apps needs the Zorderz theme to be active." If you see it, the theme is not active, return to step 4.

---

## 6. Flush rewrite rules

Activation already flushed, but do it explicitly so the manifest and REST routes are certainly resolvable.

```bash
wp rewrite flush --hard
```

---

## 7. Verify the platform is answering

Run all of these. They are the core acceptance checks.

**Public (no auth):**

```bash
# 1. The generated web app manifest resolves as JSON (proves permalinks + theme).
curl -fsS -H 'Accept: application/manifest+json' "SITE/zdz-manifest.json" | head -c 200
#    Expect: a JSON object (name, colours, icons). If you get HTML, permalinks are wrong (step 3).

# 2. The REST index lists the Zorderz namespace.
curl -fsS "SITE/wp-json/" | grep -o '"zorderz/v1"'
#    Expect: "zorderz/v1"
```

**Authenticated (admin Application Password):**

```bash
# 3. The Party roster service answers.
curl -fsS -u "ADMIN_USER:APP_PASSWORD" "SITE/wp-json/zorderz/v1/party/people"
#    Expect: a JSON array (may be small on a fresh install). 200, not 404/401.

# 4. The Item Engine publishes its (empty) catalog and the fixed two-type model.
curl -fsS -u "ADMIN_USER:APP_PASSWORD" "SITE/wp-json/zorderz/v1/item-engine/catalog"
#    Expect: {"version":...,"empty":true,"types":["product","service"],"subtypes":[...],"items":[]}
```

If checks 1-4 all pass, Zorderz is installed and running. Steps 8-9 configure it.

---

## 8. Give the install an identity

A fresh install is a coherent but nameless blank slate: defaults are inherited from WordPress (site title, admin email, host, timezone) and no business is named anywhere. Configure it one of two ways. **Do not fabricate business data**: use values the operator supplied, or leave fields blank to inherit.

### Option A: Business Profile (by form)

**wp-admin:** Zorderz → Business Profile. Set names, contact details, domains, mail senders, locale, logo artwork and the colour palette. A blank field means *inherit*; the screen shows what it is inheriting.

### Option B: Identity Pack (by file): preferred for reproducible setup

An Identity Pack is a business as data: a folder of YAML or JSON. A full pack is fifteen files; this version reads `profile.yml` and `org.yml` and reports (never silently ignores) the rest.

1. Place the pack where it survives updates:

   ```
   wp-content/zorderz-identity/<pack-name>/profile.yml
   wp-content/zorderz-identity/<pack-name>/org.yml        # optional
   ```

   A pack of one file (`profile.yml`) is valid. Copy the shipped `identity-packs/example-business/` inside the theme as a template.

2. Apply it: **Zorderz → Identity Pack** → select the pack → review the previewed before-and-after of every value that would change → type **`APPLY`**.

   Applying takes a snapshot first, so **Revert** restores exactly what was there. **This confirmation is intentionally not scriptable through a silent API**: an agent applies a pack by driving this screen, which keeps a human-auditable trail. There is no endpoint that applies a pack without the preview and typed confirmation.

**Constraints a valid pack must respect** (a pack that violates these is rejected):
- It may not create, rename or remove a role, role slugs belong to the platform.
- It may not carry secrets. `connections.yml` holds `secret://` references, never credential values.
- It may not carry customer records or employees' personal contact details.

**Verify** the identity took (the manifest reflects the configured name):

```bash
curl -fsS "SITE/zdz-manifest.json"       # name/short_name now reflect the applied profile
```

---

## 9. Connect providers and the Ai gateway

**wp-admin:** Zorderz → Settings → **App Authorizations** (Connections). This is the single place external systems are registered; apps read credentials from here rather than storing their own.

- **Billing / CRM / scheduling:** register the provider. API-key providers can be set through the connection form; OAuth providers require completing the provider's consent flow in a browser, do not attempt to bypass it.
- **Ai gateway:** the v1 gateway is **Poe** (one key reaches most models). Enter the key here, then open the **Model Registry** and assign a model to each task slot. The gateway is provider-agnostic and the slots are configurable, so no model or vendor is hardwired. Additional gateways and local models via MCP are on the roadmap.

Connections are optional for a first boot, the apps stand alone and hook into external systems when they exist, but the Chat assistant and the billing-backed apps (Invoices, Estimates, Receipts) need their respective providers to do useful work.

---

## 10. Final acceptance checklist

The install is **done** when all of the following hold:

- [ ] `wp theme get zorderz --field=version` → `1.3.4`, and the theme is active.
- [ ] `wp plugin get zorderz-apps --field=version` → `1.3.4`, and the plugin is active.
- [ ] No admin notice about a missing theme or a failed app load.
- [ ] `SITE/zdz-manifest.json` returns JSON (not HTML).
- [ ] `SITE/wp-json/` lists `zorderz/v1`.
- [ ] `GET zorderz/v1/party/people` returns 200.
- [ ] `GET zorderz/v1/item-engine/catalog` returns 200 with `types: ["product","service"]`.
- [ ] Identity supplied (Business Profile filled or an Identity Pack applied), if the operator provided one.

When every box is checked, stop. The site is a working Zorderz install.

---

## Idempotency and failure handling

**Re-running is safe.** Updating in place:

```bash
wp theme install /path/to/zorderz-theme-1.3.4.zip --force
wp plugin install /path/to/zorderz-apps-1.3.4.zip --force
wp rewrite flush --hard
```

Uploading a newer bundle over an existing one does not fire WordPress's activation hook, so the bundle re-runs each app's first-run work on the next load when its stored version differs, no manual migration step is needed.

**Symptom → cause → fix:**

| Symptom | Cause | Fix |
|---|---|---|
| `/zdz-manifest.json` returns your homepage as HTML | Permalinks not set to Post name | Step 3, then `wp rewrite flush --hard` |
| Every `zorderz/v1` call returns 404, but `/wp-json/` lists the namespace | Rewrite rules stale | `wp rewrite flush --hard` |
| `/wp-json/` does **not** list `zorderz/v1` | Theme not active | Step 4, the namespace is registered by the theme |
| Apps loaded but no tiles appear; "needs the Zorderz theme" notice | Plugin activated before/without the theme | Activate the theme (step 4); the ordering is not enforced by WordPress |
| Authenticated REST call returns 401 | Bad or missing Application Password | Recreate it; use `-u "USER:APP_PASSWORD"` |
| One app shows an error notice on load | That single app failed to load | It is isolated by design, the others and the dashboard keep working; read the notice, fix that app, the rest are unaffected |

Do not disable TLS verification, do not unset the proxy, and do not retry an organization policy denial, surface it instead.

---

## What you must never do

- **Never invent business data.** No names, catalog items, prices, people, territories or rules unless the operator supplied them. The platform ships empty on purpose.
- **Never put a specific company, person, customer or brand into code, comments, prompts or a committed file.** Business-specific values belong in the Business Profile or an Identity Pack, which are configuration, not code.
- **Never paste a credential into an app.** Credentials live only in Connections.
