# Zorderz

**Open-source, self-hostable field service management software for WordPress.**

Connect the invoicing, CRM, scheduling and Ai your business already uses into one app your team will actually open, running on your own WordPress site, on your own domain, under a license nobody can revoke.

Zorderz is a field service management platform: a mobile-first dashboard, roles and permissions, a shared media store, a product/service catalog, and a set of apps for the day-to-day work of a service business. It ships as two WordPress artifacts, a theme and a plugin, and it starts completely blank. There is no company baked in. You supply your own identity, people, catalog and rules, either through an admin screen or by applying a portable configuration file.

---

## Integrate, don't replace

Most business software wants to *be* your invoicing, your CRM, your calendar. Zorderz does the opposite. It assumes you already pay for tools that do those jobs well, and its purpose is to put them behind one interface your team actually uses, on infrastructure you own.

- **It connects to what you already run.** Point Zorderz at your billing, CRM, scheduling and Ai providers through one Connections layer; register a provider once and the apps read and write through it. Zorderz is deliberately *not* a full CRM and *not* a comprehensive billing system: WordPress is the wrong place to store that much moving data, duplicating a CRM would only bloat your database and slow the apps, and you already pay for tools that do it well.
- **But you can start with nothing.** No billing system yet? Zorderz ships a basic built-in estimate and invoice generator, documents and payment tracking, so a business whose only tool is Zorderz can run from day one: build an estimate from your catalog, convert it to an invoice, and track payments through to paid. It is a floor, not a billing platform. Connect a real provider later and that becomes the system of record.
- **It models the business you already run.** Designate someone a chief; scope that authority to a service type, a product line, or the person in general. Each level of the hierarchy carries its own rules. If you can describe how your business works, you can configure it.
- **Bring your own intelligence.** The Ai gateway is provider-agnostic and the model slots are configurable, so you choose which model does which job, and you pay your provider directly. Nothing is hardwired to one vendor.
- **Ai-installable by design.** An Ai agent is an intended *user* of this repo, not just a subject of it. The install guide at [`docs/INSTALL-FOR-AI.md`](docs/INSTALL-FOR-AI.md) is written for an autonomous agent to follow start to finish.

---

## How it's built: two artifacts

An install is two files, in this order:

| Artifact | Zip | What it is |
|---|---|---|
| **Theme** | `zorderz-theme-1.4.3.zip` | The platform kernel and all Core services: the dashboard, roles, permissions, the shared media store, and the services below. This is the part that makes a WordPress site *be* an app. |
| **Apps** | `zorderz-apps-1.4.3.zip` | The 18 apps, bundled as one plugin. Each app lives in its own directory, keeps its own version and assets, and registers itself with the theme. |

The theme is the platform; the plugin is the apps that plug into it. **The theme must be active first**: it defines the roles, the shared media store, the plugin registration API and the `zorderz/v1` REST namespace that every app builds on. Install the plugin first and the apps load but have nowhere to appear (you'll get a plain admin notice telling you so).

---

## Core services (in the theme)

Everything below ships empty or neutral. A fresh install names no business anywhere. Defaults are inherited from WordPress itself (your site title, admin email, host, timezone) so the app is coherent out of the box rather than broken.

- **Business Profile**: the one place a business's names, brand, contact details, domains, outgoing mail identities, locale, logo artwork and colour palette live. Every field replaces something that used to be hardcoded. A blank field means *inherit*, and the screen shows you what it's currently inheriting.
- **Identity Packs**: a business as data: a folder of YAML or JSON you can preview, apply, snapshot and revert. Applying one turns a blank install into a specific business's app; exporting turns a running install back into reviewable files. A pack can't create, rename or remove a role; role slugs belong to the platform.
- **Item Engine**: the product/service catalog and the counts contract every app speaks. Two types only (**Product** or **Service**), with user-named subtypes, aliases, attributes, measurement units and reusable pricing schemes (flat, per-unit, per-hour, per-area, per-visit, tiered, formula, quote-only). It replaces the duplicated, hardcoded product taxonomies the apps used to each carry their own copy of. Ships with an empty catalog; an optional, clearly-marked fictional sample set can be applied by hand.
- **Party roster**: the single authoritative answer to "which person?" Any actor: a person, a partner with no account, a shared device, a system. Used by share pickers, assignees, participant lists and mentions, so no app rolls its own roster.
- **Connections (Token Service)**: one provider-agnostic place to register an external system: credentials, OAuth registration, endpoints, account ids, redirect URIs and refresh policy. Register a billing, CRM or Ai provider once instead of copying a credential into every app that needs it.
- **Answer Authority**: the single outbound gate that decides whether a figure may be stated and at what confidence tier (confirmed / derived / inferred). Chat, email, push, digest and stream all pass through it, so the assistant can't quietly state a number it isn't allowed to.
- **Rule Governance**: a business's operating rules as typed, parameterised objects. The assistant's prompt is a *rendering* of the rule set, not a hand-written wall of text. Tenants add and narrow rules but can never override the platform's safety floor.
- **Model Registry**: configurable model slots. You decide which Ai model handles which task, and swap models without touching code.
- **Compensation**: commission structures, piece rates and pay floors, as configuration. Never seeded, sampled or shipped with data.
- **Document Conventions**: how a business writes and numbers its paperwork (reference-code formats, line ordering, notation), applied on output rather than baked into any engine.

---

## The 18 apps

Bundled in `zorderz-apps-1.4.3.zip`. An app whose dependencies aren't present declines to register rather than failing, so a partial install degrades to fewer tiles, never a broken dashboard.

| App | What it does |
|---|---|
| **Camera** | Capture photos straight into the shared media store, tagged and place-stamped. |
| **Media** | Browse, search and manage the shared media library. |
| **Sketch Pad** | Freehand sketches and marked-up drawings. |
| **Team** | Internal channels and direct messages between team members. |
| **Quick-ID** | A shareable business identity card, built entirely from the Business Profile. |
| **Game** | A small built-in extra. |
| **Invoices** | Draft and send invoices through a connected billing provider (for example Stripe). For the no-provider path, see Estimates. |
| **Knowledge Base** | A searchable store of company facts the assistant is allowed to cite. Ships empty. |
| **Scheduler** | Appointments, plus per-user Connected Calendars authorized through Connections. |
| **Jobs** | Job handoffs and completion tracking, with a photo or recorded-attestation gate. |
| **Surveys** | Post-job satisfaction surveys and review routing. |
| **Stock** | Inventory and stock checks against the Item Engine catalog. (Optional / beta.) |
| **Leads** | Intake and qualification of new leads, routed by service area. |
| **Prep** | A fabrication and preparation queue driven by the Item Engine. |
| **Receipts** | Itemized receipts and document output. |
| **Estimates** | Build estimates from the catalog and price book. With no billing provider connected, it also generates basic invoices: estimates convert to trackable invoices and payments are recorded through to paid, and you can import an existing business's PDF estimates and invoices. Documents and payment tracking only. |
| **Commission** | Compensation and commission calculation. Ships no pay data. |
| **Chat** | The Ai assistant, gated by Answer Authority and grounded in the Business Profile, catalog, roster and rule set. |

---

## Requirements

- **WordPress 6.0 or newer** (tested up to 6.9)
- **PHP 8.0 or newer**
- **MySQL 5.7+ or MariaDB 10.3+** (WordPress's own database requirement)
- **Pretty permalinks** set to **Post name**, see the install steps below
- HTTPS strongly recommended (OAuth connections require it)

No build step is required to install a release: the zips are ready to upload. `node_modules/`, `/vendor/` and source maps are development-only and are not part of a release artifact.

---

## Install

The order matters and **is not enforced** by WordPress. Follow it.

### 1. Set pretty permalinks

**Settings → Permalinks → Post name**, and Save.

WordPress defaults to plain permalinks, under which `/wp-json/` returns your homepage as HTML instead of the REST API, and the generated app manifest at `/zdz-manifest.json` won't resolve. Nothing is technically broken under plain permalinks, but several things assume pretty ones. Set this first and you avoid a confusing class of "it's not working" that isn't actually a bug.

### 2. Install and activate the theme (first)

**Appearance → Themes → Add New → Upload Theme**, choose `zorderz-theme-1.4.3.zip`, install, and **Activate**.

This is the platform. Activating it registers the roles, the shared media store, the plugin API and the `zorderz/v1` REST namespace that the apps need.

### 3. Install and activate the apps plugin (second)

**Plugins → Add New → Upload Plugin**, choose `zorderz-apps-1.4.3.zip`, install, and **Activate**.

Activation runs each app's first-run work (tables, scheduling) and flushes the rewrite rules. If the theme isn't active yet, the plugin will tell you so with an admin notice instead of failing silently. Activate the theme, and the apps appear.

### 4. Configure the business

Out of the box the install is a coherent, nameless blank slate. Give it an identity one of two ways:

- **By form**: **Zorderz → Business Profile**. Fill in names, contacts, domains, mail senders, locale, logos and the colour palette. Blank fields inherit from WordPress and the screen shows you what they're inheriting.
- **By file**: **Zorderz → Identity Pack**. Drop a pack folder in `wp-content/zorderz-identity/<pack>/`, preview the exact before-and-after, type `APPLY`, and it's applied with a snapshot you can revert. An example pack ships inside the theme. Apply it on a throwaway install to watch the whole interface re-skin, then revert.

Then fill the catalog (**Zorderz → Item Engine**), the roster (Party), and the rules as you need them. Nothing about your business is seeded for you; you add what's yours.

### 5. Connect your providers

**Zorderz → Settings → App Authorizations** (Connections). Register a billing provider, a CRM, a scheduling calendar and an Ai gateway here. Credentials live in one place and the apps read them from it, so you never paste a key into an app.

### Bring your own intelligence

The model layer is provider-agnostic. The v1 Ai gateway is **Poe** (one key reaches most models), and the **Model Registry** lets you assign a specific model to each task. Cost is your provider's, billed to you directly; Zorderz adds no Ai markup. Additional gateways, and eventually local models via MCP, are on the roadmap. Nothing in the platform is locked to a single model or vendor.

---

## Verify the install

Quick checks, no tooling required:

1. **Appearance → Themes** shows Zorderz active.
2. **Plugins** shows the Zorderz apps active, with no error notice.
3. Visit **`/zdz-manifest.json`**: you should get a small JSON document, not your homepage. If you get HTML, permalinks aren't set to Post name (step 1).
4. Visit **`/wp-json/`**: the `namespaces` list should include **`zorderz/v1`**.

Signed in as an admin, you can also confirm the services are answering:

- `GET /wp-json/zorderz/v1/party/people`: the roster (empty-ish on a fresh install, but a valid response).
- `GET /wp-json/zorderz/v1/item-engine/catalog`: returns `{ "empty": true, "types": ["product","service"], ... }` before you've added anything.

For the full, ordered, machine-followable procedure, including the equivalent WP-CLI and REST commands and their expected output, see [`docs/INSTALL-FOR-AI.md`](docs/INSTALL-FOR-AI.md).

---

## Installing with an Ai

An Ai agent can install a WordPress site end to end, and Zorderz is built so one can install *itself*. [`docs/INSTALL-FOR-AI.md`](docs/INSTALL-FOR-AI.md) is the LLM-facing guide: precise, ordered, unambiguous steps to download and place both artifacts, set permalinks, open the Business Profile, optionally apply an Identity Pack, connect a provider, and verify a working site, with a check the agent can run after every step.

---

## Documentation

- [`docs/INSTALL-FOR-AI.md`](docs/INSTALL-FOR-AI.md): the autonomous-agent install guide.
- [`CHANGELOG.md`](CHANGELOG.md): what changed in each release.

---

## License

Zorderz is released under the **GNU General Public License v2.0 or later** (GPL-2.0-or-later). See [`LICENSE`](LICENSE). WordPress themes and plugins inherit WordPress's GPL; Zorderz is GPL because it should be, and because software you self-host should be software you can read, change and keep.

---

## Contributing

Zorderz is community-first. Issues, fixes and generalizations that help it fit more kinds of business are welcome. The one hard rule for any contribution: it names no specific company, person, customer or brand anywhere, in code, comments, prompts, schemas, examples or docs. What varies between businesses is *configuration*, and configuration lives in the Business Profile or an Identity Pack, never in the code.
