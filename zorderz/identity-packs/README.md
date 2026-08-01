# Identity Packs

An Identity Pack is a business, as data.

Zorderz Core is deliberately blank. It knows how to run a field-service operation
— items, prices, people, permissions, workflow — but it does not know *whose*
operation. A pack is what tells it: names, brand, contact details, sending
identities, role labels, and eventually the catalog, the price book, the pay
plan and the house rules.

Applying a pack to a stock install turns a blank platform into one company's app.
Exporting a pack turns a running install back into reviewable files.

## Where packs live

```
wp-content/zorderz-identity/<pack>/      ← yours; survives theme updates. Preferred.
<theme>/identity-packs/<pack>/           ← shipped examples. Overwritten on update.
```

The first path wins if a pack of the same name exists in both. Put anything real
in `wp-content/zorderz-identity/`.

## The fifteen files

A full pack is fifteen files. **This version reads two of them.** The rest are
recognised, reported, and left completely alone — so a pack written today for a
later version loses nothing by being loaded now.

| File | What it carries | Read by v1 |
|---|---|---|
| `profile.yml` | Names, brand, contact, domains, senders, locale | **yes** |
| `org.yml` | Role labels, per-role app grants, departments, channels | **yes** (labels + grants) |
| `parties.yml` | The roster: people, partners, shared devices, system actors | no |
| `catalog.yml` | Products and services, subtypes, aliases, attributes | no |
| `pricing.yml` | Pricing schemes and the price book | no |
| `costs.yml` | Supplier costs and cost formulas | no |
| `compensation.yml` | Commission structures, piece rates, floors | no |
| `territories.yml` | Service areas and who owns them | no |
| `connections.yml` | External systems — **references to secrets, never secrets** | no |
| `mappings.yml` | How this business's words map to external systems' words | no |
| `document-conventions.yml` | How paperwork is written and numbered | no |
| `flows.yml` | Workflow stages, timings, and what closes a stage | no |
| `rules.yml` | The business's own operating rules, as data | no |
| `knowledge.yml` | Company facts the assistant may state | no |
| `voice.yml` | How the business writes | no |

## Two accepted schemas

Both are read; the loader detects which one it has and says so.

**The canonical schema** is the long-term one, and it is richer than what this
version stores: phones are an ordered list with one canonical entry, senders are
a list keyed by purpose that can inherit from one another, registrations repeat,
review destinations are typed. Write packs this way and they will still be
correct when the fuller consumers land. `example-business/` is written this way.

**The flat schema** mirrors the Business Profile's own field names exactly
(`identity.trading_name`, `contact.phone`, `web.app_domain`, `brand.ramp`). It is
the easy path if you are hand-writing a small pack for one install.

Anything the loader carries but cannot yet use is listed for you on the preview
screen. It is never dropped silently.

## Logo artwork

Four slots: two shapes, each in two ink colours.

```yaml
brand:
  logo:
    wide:                       # 2:1 PNG — the lockup
      dark: "https://…/logo-wide.png"        # normal colours, for light surfaces
      light: "https://…/logo-wide-white.png" # white/pale, for the topbar
    square:                     # 1:1 PNG — the mark alone
      dark: "https://…/mark.png"
      light: "https://…/mark-white.png"
    favicon: ""                 # optional; falls back to the square mark
```

**`dark` and `light` name the ink, not the background.** Dark ink goes on light
surfaces; light ink is the white version for a dark topbar. This trips everyone
up, so nothing in the code asks you to think about it — a caller asks for the
background it is drawing on and the right ink is chosen for it.

Supplying only one file is fine. The resolver falls back in a declared order —
right shape and ink, then right shape wrong ink, then the other shape, then the
business name as text — and reports what it actually found, so a square standing
in for a wide slot is laid out as a square and centred rather than stretched.
The one exception is the home-screen icon, which takes a square or nothing: a
wordmark squashed into a launcher tile looks worse than the default icon.

If your artwork is 3:1 or 4:5, use it anyway. The settings screen will tell you
the proportions do not match the slot, and the image gets fitted inside its space
with room left over. That is a note, not a rejection.

## Format

YAML or JSON. JSON is preferred when both exist for the same file, because it
parses exactly. The YAML reader handles the subset a pack needs — nested maps by
indentation, lists, quoted strings, inline `[a, b, c]` lists, booleans, null and
comments. It does **not** handle anchors, tags, or multi-line scalars. If you
need those, ship JSON.

## What a pack may not do

- **It may not create, rename or remove roles.** Role slugs belong to the
  platform; several are matched as literal strings by security checks, so a pack
  that could rename one could silently disable a privacy boundary. A pack may
  relabel a role and choose which apps it opens with. That is all.
- **It may not carry secrets.** `connections.yml` holds `secret://` references
  to credentials, never credential values. A pack is a file that gets emailed,
  committed and copied between machines; treat it accordingly.
- **It may not carry customer records or employee personal contact details.**
  Those are tenant database content, not identity. A pack describes the shape of
  a business, not its people's private information.
- **It may not apply itself.** Nothing writes without a preview, a typed
  confirmation, a snapshot, and a log entry.

## Applying one

**Zorderz → Identity Pack**. Preview shows the actual before-and-after of every
value that would change. Applying takes a snapshot first, so **Revert** restores
exactly what was there.

Editing a field by hand afterwards overrides the pack for that field. The pack
files are never modified by the platform.

## Writing your own

Copy `example-business/`, change the values, drop it in
`wp-content/zorderz-identity/`, and preview it. Start with `profile.yml` alone —
`org.yml` is optional, and the loader is content with a pack of one file.
