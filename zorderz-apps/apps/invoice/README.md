# Invoice Creator (optional / beta)

A bundled Zorderz app for pay-by-card invoicing. An admin creates an invoice; the
customer opens a hosted pay page (`/pay/<token>`) and pays by card through a
Stripe account; a signed Stripe webhook marks the invoice paid and emails a
receipt. When a FreshBooks connection is configured, the pay link is appended to
the matching FreshBooks invoice. Refunds and a CSV export are included.

This module is **optional and beta**. It ships **empty**: no invoices, no
payments, no credentials, no seeds. Everything is configured by an admin under
**Invoices → Settings**.

## What it needs

- The Zorderz theme (provides the dashboard, roles, and the `zorderz/v1` REST
  namespace this app registers into).
- A Stripe account (secret key, publishable key, webhook secret).
- Optionally a Stripe **Connected Account** for Stripe Connect payouts, and a
  FreshBooks OAuth app for pay-link injection.

## Generalizations vs. the internal original

- **Platform fee is now a disclosed setting**, not a baked constant.
  *Invoices → Settings → Platform fee (%)*, **default 0 (off)**. It is charged as
  a Stripe Connect application fee **only** when a Connected Account ID is set;
  with no connected account the app makes a plain charge on the site's own Stripe
  account and no fee applies.
- **Thank-you / return URL is config**, not a hardcoded address. Blank shows the
  built-in on-site thank-you page; set a full URL to send customers elsewhere.
  No production hostname is compiled in anywhere.
- **Isolated FreshBooks connection.** This app keeps its own FreshBooks OAuth
  credentials and refreshes them on its own options, so it can never be clobbered
  by another connection's token refresh.
- Renamed to the `zic` prefix; REST routes live under the theme's single
  `ZDZ_REST_NS` constant; tables/options carry rename-map aliases so an existing
  install upgrades in place.

## Settings reference

| Setting | Default | Notes |
|---|---|---|
| Stripe publishable / secret / webhook secret | empty | required to take payment |
| Connected Account ID | empty | optional; enables Stripe Connect + platform fee |
| Platform fee (%) | `0` | disclosed; only applies with a connected account |
| Thank-you / return URL | empty | blank = built-in on-site thank-you page |
| FreshBooks Client ID / Secret / Account ID | empty | optional pay-link injection |
| Merchant email | admin email | payment notifications |

## Kill switch

Add to `wp-config.php` to load nothing from this module:

    define('ZIC_DISABLE', true);

## Data

Three tables ship empty and are created on activation (schema only, never
seeded): `wp_zic_invoices`, `wp_zic_payments`, `wp_zic_webhook_events`.

License: GPL-2.0-or-later. Text Domain: `zorderz`.
