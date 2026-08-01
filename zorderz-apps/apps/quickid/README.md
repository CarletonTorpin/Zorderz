# Zorderz Quick-ID

A full-screen, photographable digital business card for the signed-in person.
Swipe in from the left edge of the homepage; another person photographs the
screen to keep the card.

This is an **output-only** module: no AJAX, no REST, no admin-post, no nonces in
the DOM. The card is rendered server-side and shows only the **current person's
own** identity (INV-1). The shared-device / kiosk tier never shows a personal
card (INV-10).

## Where the card gets its content

Nothing on the card is hardcoded. Everything company-wide comes from the theme's
Business Profile; the person's lines come from their own account plus optional
per-person overrides.

| Card element | Source |
|---|---|
| Logo (or a text wordmark fallback) | `ZDZ_Business_Profile::logo('wide','light')` / `::name()` |
| Main phone | `contact.phone` |
| Licence line | `identity.license` (rendered only when set) |
| "Formerly '…'" line | `identity.former_names` (rendered only when set) |
| Promo banner (two lines) | `brand.taglines.card_banner_top` / `.card_banner_big` |
| Card email | per-person override → `people.staff_email_pattern` (`{first}` etc.) → account email |
| Name | per-person override → "First L." from the account → display name |
| Title / cell | per-person overrides |
| Wave + accent colours | derived from the brand ramp (`--ref-brand-*`) |

Every business field ships **empty**; an element is simply omitted until the
business fills it in, so a fresh install renders a coherent blank-slate card
with no placeholder identity.

## Behaviour

- **Open:** swipe right from the left edge of the homepage (drag follows the
  finger). Desktop: triple-click an empty spot in the left sidebar region, or
  mouse-drag from the left edge.
- **Close:** swipe left anywhere on the card, tap the X, press Escape, or use
  the Back gesture/button.
- The screen is kept awake (wake lock) while the card is shown.
- The card scales to the largest 4:7 portrait rectangle that fits the screen.

## Who sees it

Any signed-in, non-kiosk person on the homepage. Narrow or widen with the
`zqid_should_load` filter; adjust final card fields with `zqid_card_data`.

## Per-person card fields

Set under **Users → Profile → Quick-ID Card**: card name override, title, cell,
and email override. Values from an install upgraded from the pre-rename lineage
(`tsqid_*` user meta) are read as a fallback and migrated to `zqid_*` on the next
profile save.

## Notes

- Prefix: `zqid`. Class: `ZQID_Card`. Text domain: `zorderz`.
- Typeface: bundled Source Sans 3 (SIL OFL, see `assets/fonts/LICENSE-OFL.txt`)
  with a system fallback.
- The card face intentionally ignores light/dark/sunlight modes — only the
  backdrop is themed.
