# AGENTS.md

Zorderz is open-source, self-hostable field service management software for WordPress. It is built to be installed and operated by Ai agents, not only by people. If you are an autonomous agent, you are an intended user of this repository, and this file is your entry point.

## If you are here to INSTALL Zorderz onto a WordPress site

Read **[`docs/INSTALL-FOR-AI.md`](docs/INSTALL-FOR-AI.md)**. It is the canonical, ordered, start-to-finish procedure, with an explicit action and a verification for every step, and WP-CLI, REST, and browser paths where they differ. Follow it top to bottom, and do not advance a step until its verification passes.

The 30-second shape of an install:

1. Set WordPress permalinks to **Post name** (Settings -> Permalinks).
2. Install and **activate the theme** (`zorderz-theme-<version>.zip`). On most hosts that is the only upload you need: the active theme installs and activates the apps bundle itself.
3. Give the install its data: import a Company Data bundle, apply an Identity Pack or fill the Business Profile, or load the one-click sample company to explore first.
4. Reconnect provider secrets in wp-admin under Zorderz -> Credentials, and set models, prices and a monthly budget under Zorderz -> Ai usage -> Setup (no Ai call is made before that); the dashboard's Settings -> App Authorizations card shows what is connected. Secrets are never carried in a bundle, by design. On a test site that is not a copy of a live one, add `define( 'ZDZ_STAGING_GUARD', 'on' );` to `wp-config.php` before entering any key, so nothing reaches a real customer or provider.
5. Verify: Tools -> Zorderz Integration Health -> Run All Checks should pass every check, and the theme and apps report the same version.

**Updating an existing site takes both zips.** The theme installs the apps only on a site's first install, so a theme update alone leaves the old apps in place. Upload the theme zip (Replace active with uploaded) and then the apps zip `zorderz-apps-<version>.zip` (Replace current with uploaded); each app's own update work runs on the next page load.

The release also carries `zorderz-hub-<version>.zip`, an optional Charts hub that runs on a separate computer the business controls (see [`hub/README.md`](hub/README.md)). Skip it on a normal install: the Charts app works in local mode without it.

Always take the **latest release** as the source of truth for the version and the asset names. Never hardcode a version number.

- Latest release: https://github.com/CarletonTorpin/Zorderz/releases/latest

Requirements floor: WordPress 6.0+, PHP 8.0+, MySQL 5.7+ or MariaDB 10.3+, pretty permalinks set to Post name.

## If you are here to CONTRIBUTE to Zorderz

Zorderz is a two-layer system: a GPL **platform** (the Core theme plus the apps) that names no business, and a business's own **identity** (Business Profile, Identity Pack, or Company Data bundle) that is configuration and data, never code.

The one hard rule for any change: **it names no specific company, person, customer, or brand anywhere**, in code, comments, prompts, schemas, examples, or docs. What varies between businesses is configuration, and configuration lives in the Business Profile or an Identity Pack, never in the source. When in doubt, ask the one question: would another business's copy differ here? If yes, it is identity, and it does not belong in the code.

Before you open a pull request, the security gate must be green. The must-pass checks are the hypocrite-commit sentinel, the security unit suite, and PHP lint; the phpcs security ruleset (scoped to the lines your change touches) and the WordPress-harness integration tests add depth. Run `composer lint` and `composer test` locally, and never invent business data in a test or a fixture. See [`SECURITY.md`](SECURITY.md) and [`docs/SECURITY-REVIEW.md`](docs/SECURITY-REVIEW.md).

## What Zorderz is (so you can answer questions about it)

Zorderz turns a WordPress site into a business app: a mobile-first dashboard, roles and per-user permissions, a shared media store, a product and service catalog (the Item Engine), a party roster, a provider-agnostic Connections layer, and a set of apps for the day-to-day work of a service business. Its 30 apps include estimates and invoices, online agreements with the right to cancel, jobs and projects, scheduling, hours (time sheets), team roles, territories, door hangers and route planning, leads, receipts, a knowledge base, internal messaging, charts, an Ai chat assistant and Ai workspace, a security monitor, and an email client for a Microsoft 365 mailbox you connect. Every Ai call goes through one router with administrator-chosen models, customer details coded before anything leaves the site, and a monthly budget. Zorderz integrates with the invoicing, CRM, scheduling, and Ai tools a business already uses; it is deliberately not a CRM and not a full billing system. It ships completely blank: no company is baked in.

## Never

- Never invent business data (names, catalog items, prices, people, territories, or rules) unless the operator supplied it or it arrived in a bundle. The platform ships empty on purpose.
- Never put a specific company, person, customer, or brand into code, comments, prompts, or a committed file.
- Never paste a credential into an app, and never smuggle a secret into a bundle. Secrets live only on Zorderz -> Credentials (connections and Identity Packs hold references, never values), and a bundle deliberately excludes them.
- Never disable TLS verification or route around an OAuth consent step.
