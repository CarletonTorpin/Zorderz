# AGENTS.md

Zorderz is open-source, self-hostable field service management software for WordPress. It is built to be installed and operated by Ai agents, not only by people. If you are an autonomous agent, you are an intended user of this repository, and this file is your entry point.

The full, ordered procedure is **[`docs/INSTALL-FOR-AI.md`](docs/INSTALL-FOR-AI.md)**: an action and a verification for every step, with WP-CLI, HTTP and browser paths where they differ. This file tells you which path fits your tools, what the person you work for must do themselves, and what "done" looks like. Read both before you start.

## 1. Pick your path from the tools you have

| Your tools | Can you install Zorderz? | How |
|---|---|---|
| A shell on the host with WP-CLI | Yes, unattended | WP-CLI path. Install the theme **and** the apps plugin as two zips (the theme's self-install needs an admin page load, which WP-CLI does not do). |
| Browser automation that can attach a file (Playwright, Puppeteer, Selenium and the like) | Yes | Browser path. Set the zip on the `<input type=file>` with your file-upload primitive; never click the input. |
| Browser control that cannot attach files (for example a browser extension) | Yes, with one click from the person | Browser path, but open the upload form and ask the person to choose the zip. Then you continue: Install, Activate, verify. |
| HTTP only (REST with an Application Password, no shell, no browser) | **No.** WordPress has no REST call that uploads a theme or plugin | Ask the person to upload and activate the theme, then verify and configure over HTTP. |

If you cannot tell which row you are in, ask the person before you start.

## 2. What the person does, not you

Plan for these hand-offs and ask for them at the moment you need them:

- **Sign in.** Managed hosts (WP Engine and others) sign in to wp-admin through their portal. Never type a password; ask the person to open wp-admin for you, or to create an Application Password for HTTP work.
- **Choose a file**, if your browser tool cannot attach one (row 3 above).
- **Enter every secret.** Provider keys go on **Zorderz -> Credentials**, typed by the person. You may open the page and say which rows are needed; afterwards, confirm that each row reads "set" (the page never shows a key back).
- **Approve OAuth consent screens** (billing, calendars, Microsoft 365). Never route around them.
- **Confirm guarded actions**: applying an Identity Pack and running a real Company Data import both stop for an explicit confirmation by design.
- **Decide what Ai may spend**: models, prices and a monthly budget under **Zorderz -> Ai usage -> Setup**. No Ai call is made until they are set.
- **Legal**: Agreements stays in test mode until the business's lawyer has reviewed the legal pack and the agreement terms. Do not switch it on.

## 3. First install, in short

1. **Get the latest release.** Read the version and the asset names from https://github.com/CarletonTorpin/Zorderz/releases/latest; never hardcode a version. You need `zorderz-theme-<version>.zip` (plus `zorderz-apps-<version>.zip` on the WP-CLI path). Skip `zorderz-hub-<version>.zip`: the optional Charts hub runs on a separate computer (see [`hub/README.md`](hub/README.md)), and the Charts app works without it. Check the downloads with the checksums file attached to the release.
2. **Set permalinks to Post name** (Settings -> Permalinks).
3. **Upload and activate the theme.** On most hosts that is the only upload: the active theme installs and activates the apps itself on the next admin page load.
4. **On a test site** that is not a copy of a live one, add `define( 'ZDZ_STAGING_GUARD', 'on' );` to `wp-config.php` now, before any key is entered. The guard then holds outgoing e-mail and every outgoing write to another service (billing, customer relationship manager, Ai and hub calls) and pauses scheduled jobs, and the admin bar shows "STAGING GUARD ON". (A copy of a live site, or a site WordPress reports as `staging` or `development`, turns it on by itself.)
5. **Give the install its data**: import a Company Data bundle, apply an Identity Pack or fill the Business Profile, or load the fictional sample company from Tools -> Zorderz Data to explore. Never invent business data.
6. **Connect providers** through the hand-offs in section 2.

## 4. Done looks like this

- Appearance -> Themes shows **Zorderz Core** active, and Plugins shows **Zorderz Apps** active, both at the release version.
- `SITE/zdz-manifest.json` returns JSON, and `SITE/wp-json/` lists `zorderz/v1`.
- **Tools -> Zorderz Integration Health -> Run All Checks** passes every check. A fresh install passes them all; the billing check passes while billing is not connected.
- No admin notice about a missing theme or a failed app.
- The home screen (the site's front page, signed in) shows the app tiles, and an app opens with a Back button.
- **Zorderz -> Ai usage** says what is still missing, until the person has finished setup.

## 5. Updating a site that already runs Zorderz

Upload **both** zips from the latest release, every time: the theme first (Replace active with uploaded), then the apps (Plugins -> Add Plugin -> Upload, Replace current with uploaded). The theme installs the apps only on a site's first install, so a theme update alone leaves the old apps in place. Each app's own update work runs on the next page load; there is no migration step. Then run the checks in section 4 again; the theme and apps versions must match.

## 6. When something goes wrong

The install guide's "Idempotency and failure handling" section has a symptom -> cause -> fix table. The ones agents hit most:

- **Apps not active after the theme upload.** Load any wp-admin page once as an administrator, or install the apps zip by hand. A notice says when the theme could not write to the plugins folder.
- **`/zdz-manifest.json` returns the homepage.** Permalinks are not Post name.
- **A single 502 that clears on retry.** Managed-host infrastructure, not Zorderz. Retry with backoff.
- **"Cookie check failed" or a login screen mid-task.** The session expired. Ask the person to sign in again; never type a password.
- **Stop and ask** rather than guess whenever a verification fails twice, a step needs a secret, or a screen asks for a confirmation.

Requirements floor: WordPress 6.0+, PHP 8.0+, MySQL 5.7+ or MariaDB 10.3+, pretty permalinks set to Post name, HTTPS for any OAuth connection.

## If you are here to CONTRIBUTE to Zorderz

Zorderz is a two-layer system: a GPL **platform** (the Core theme plus the apps) that names no business, and a business's own **identity** (Business Profile, Identity Pack, or Company Data bundle) that is configuration and data, never code.

The one hard rule for any change: **it names no specific company, person, customer, or brand anywhere**, in code, comments, prompts, schemas, examples, or docs. What varies between businesses is configuration, and configuration lives in the Business Profile or an Identity Pack, never in the source. When in doubt, ask the one question: would another business's copy differ here? If yes, it is identity, and it does not belong in the code.

Before you open a pull request, the security gate must be green. The must-pass checks are the hypocrite-commit sentinel, the security unit suite, and PHP lint; the phpcs security ruleset (scoped to the lines your change touches) and the WordPress-harness integration tests add depth. Run `composer lint` and `composer test` locally, and never invent business data in a test or a fixture. See [`SECURITY.md`](SECURITY.md) and [`docs/SECURITY-REVIEW.md`](docs/SECURITY-REVIEW.md).

## What Zorderz is (so you can answer questions about it)

Zorderz turns a WordPress site into a business app: a mobile-first dashboard, roles and per-user permissions, a shared media store, a product and service catalog (the Item Engine), a party roster, a provider-agnostic Connections layer, and a set of apps for the day-to-day work of a service business. Its 30 apps include estimates and invoices, online agreements with the right to cancel, jobs and projects, scheduling, hours (time sheets), team roles, territories, door hangers and route planning, leads, receipts, a knowledge base, internal messaging, charts, an Ai chat assistant and Ai workspace, a security monitor, and an email client for a Microsoft 365 mailbox you connect. Every Ai call goes through one router with administrator-chosen models, customer details coded before anything leaves the site, and a monthly budget. Zorderz integrates with the invoicing, CRM, scheduling, and Ai tools a business already uses; it is deliberately not a CRM and not a full billing system. It ships completely blank: no company is baked in.

## Never

- Never invent business data (names, catalog items, prices, people, territories, or rules) unless the operator supplied it or it arrived in a bundle. The platform ships empty on purpose.
- Never put a specific company, person, customer, or brand into code, comments, prompts, or a committed file.
- Never type a password or a secret on the person's behalf, and never smuggle a secret into a bundle. Secrets live only on Zorderz -> Credentials (connections and Identity Packs hold references, never values), and a bundle deliberately excludes them.
- Never disable TLS verification or route around an OAuth consent step.
- Never switch Agreements out of test mode, or turn off the staging guard, without the person's explicit go-ahead.
