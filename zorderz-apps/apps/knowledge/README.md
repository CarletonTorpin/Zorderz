# Zorderz: Knowledge

A company-wide document repository with AI indexing, bundled as an app module of
the Zorderz distribution (loaded by `zorderz-apps.php`). Upload documents; an
assistant extracts a title, category, synopsis, key facts and searchable content
chunks. Everything is searchable across the platform and can be fed into the
platform assistant's answer context.

This module ships **empty**: no company facts, no product corpus, no seeded
document categories. All identity comes from the theme's Core services at runtime.

## License

GPL-2.0-or-later. (The internal predecessor was proprietary; this distribution
build is GPL and carries no company, product, place or person names.)

## What it does

- **Upload / scan / paste** documents (PDF, DOC/DOCX, MD, TXT, images, and
  caption/transcript formats: SRT, VTT, ITT, …), up to 50 MB each.
- **AI indexing**: a background job extracts a short title, a category, a
  synopsis, key facts, entities and tags, and stores raw text in FULLTEXT
  content chunks so specific lookups (a price, a dimension, a part number) hit
  the actual document text, not just the summary.
- **Per-document visibility**: `all_employees`, `admin_only`, or
  `transcript_private` (fail-closed; a transcript is readable only by the WP
  users who are its named parties, everywhere at once, with no admin bypass).
- **Party-initiated sharing** of a transcript (whole-document or a materialized
  excerpt), view-only, revocable, optional expiry.
- **Pricing-authority documents**: a document placed in a designated pricing
  category and explicitly enabled becomes a quotable pricing source the
  assistant reads. The density-scoring retrieval that pulls pricing tables out of
  number-dense PDF grids is preserved.
- **Email-in (optional)**: staff forward mail to a mailbox the admin configures;
  a Microsoft Graph poller files each message into the vault. The site only ever
  calls **out**; there is no inbound webhook, REST route or `nopriv` AJAX.

## Security posture (uploads)

The **authenticated `/vault/{slug}` route is the primary access control.** It
enforces login, the app-access grant and the per-document visibility ACL before
streaming a byte. The physical store also carries a deny-all `.htaccess` (Apache)
and `web.config` (IIS) as **defence-in-depth**, but `.htaccess` is inert on
nginx and is never the guarantee. `zkv_vault_protection_report()` raises a loud
admin health warning when the web server cannot honour a file-level deny rule,
and recommends a server rule (or moving the directory outside the web root).

## Core services consumed

- **`ZDZ_Plugin_API`**: app registration + `user_can_access_app()`.
- **`ZDZ_Business_Profile`**: the business name / industry used to assemble
  indexer & classifier prompts at runtime (`zkv_business_descriptor()`); no
  company/product is typed into any prompt.
- **`ZDZ_Core_Poe` / `ZDZ_Core_Settings`**: the model call and the Poe API key.
- **`ZDZ_User_Roles`, `ZDZ_Share_Link`**: admin-role checks and response hygiene.

Where a Core service does not exist yet, the module binds through a documented
filter with a graceful, empty default (see **Filters** below).

## Settings-driven, ships empty

- **Document categories**: `defaults/categories.json` is `[]`. `seed_defaults()`
  runs once on first activation and inserts whatever that file holds: nothing by
  default. No category is ever re-inserted on upgrade; upgrades write schema only.
- **Product / brand keywords**: the pricing-query detector and the product-line
  coverage boost read `zkv_product_keywords()` (option `zkv_product_keywords`,
  empty by default). The business-specific product literals that used to be
  hardcoded are gone; the density-scoring mechanics are unchanged.
- **Login route**: `zkv_login_url()` reads the `zkv_login_slug` option / the
  `zkv_login_url` filter, else defers to `wp_login_url()` (which the theme already
  points at the tenant's login page). No route hardcodes a slug.

## Filters

| Filter | Purpose | Default |
|---|---|---|
| `zkv_product_keywords` | Product/brand tokens for pricing detection + coverage. Bind the Item Engine here when it lands. | `[]` |
| `zkv_pricing_category_slugs` | Category slugs whose documents may be pricing authorities. | `['pricing-documents']` |
| `zkv_login_url` | Override the login URL used by vault redirects. | derived from settings / `wp_login_url()` |

## REST API

All routes live under the single `ZDZ_REST_NS` namespace (`zorderz/v1`); the
module declines to register if the theme (owner of that constant) is absent.

| Route | Method | Purpose |
|---|---|---|
| `/{ZDZ_REST_NS}/vault/search?q=` | GET | Search index + content chunks (auth required) |
| `/{ZDZ_REST_NS}/vault/context?q=` | GET | Context block for assistant integration |
| `/{ZDZ_REST_NS}/vault/preview/{id}` | GET | Inline preview card |
| `/{ZDZ_REST_NS}/vault/pricing` | GET | Pricing-authority documents |
| `/{ZDZ_REST_NS}/vault/deep-search?q=` | GET | Matched content excerpts with scores |

## Data & tables

Tables (created on activation, `wp_` prefix): `zkv_documents`, `zkv_index`,
`zkv_chunks`, `zkv_categories`, `zkv_doc_parties`, `zkv_doc_shares`,
`zkv_transcript_lines`, `zkv_access_log`. A legacy install carrying the old
`tskv_*` names is migrated in place by the theme's `ZDZ_Rename_Migration` via the
`zdz_rename_map` this module declares (tables, options, cron). Deprecated class
aliases (`TSKV_TSA_Bridge`, `TSKV_Bridge`, `TSKV_Mailbox`, `TSKV_ACL`) are kept
transitionally so other components' cross-references keep working during the
rename.

## Registration

Registers with the theme through the `zdz_register_apps` filter on
`after_setup_theme` (Tier-2 inline widget where available, Tier-1 iframe tile as
fallback) and declines cleanly when the theme is absent. Activation /
deactivation run through the bundle manifest (`zkv_activate` / `zkv_deactivate`).
