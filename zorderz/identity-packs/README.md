# Identity Packs

An Identity Pack is a business, as data.

Zorderz Core is deliberately blank. It knows how to run a field-service operation
(items, prices, people, permissions, workflow), but it does not know *whose*
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

A full pack is fifteen files. **This version reads ten of them**, whole or in
part; the table says which keys. What a read file carries that nothing reads is
listed on the preview, and the other files are recognised, reported, and left
completely alone, so a pack written today for a later version loses nothing by
being loaded now.

| File | What it carries | Read by this version |
|---|---|---|
| `profile.yml` | Names, brand, contact, domains, senders, locale | **yes**, plus `email.work_domains` |
| `org.yml` | Role labels, per-role app grants, departments, channels | labels, tiers, app grants, `app_groups`, `alerts.role_category_grants` |
| `parties.yml` | The roster: people, partners, shared devices, system actors | `people[].team_roles` (Team Roles marks) |
| `catalog.yml` | Products and services, subtypes, aliases, attributes | `price_book`; `vocabulary.plain_words`, `vocabulary.model_words`, `vocabulary.things` |
| `pricing.yml` | Pricing schemes and the price book | `price_book`: version, price rules, rules, prices |
| `costs.yml` | Supplier costs and cost formulas | `price_book`: supplier costs (admin-only, never in the catalog) |
| `compensation.yml` | Commission structures, piece rates, floors | no |
| `territories.yml` | Service areas and who owns them | `zones`, `rules`, `territories` (Territories app); `place_names`, `jurisdiction` |
| `connections.yml` | External systems: **references to secrets, never secrets** | `ai.*`, `vulnerability_feed.key` |
| `mappings.yml` | How this business's words map to external systems' words | no |
| `document-conventions.yml` | How paperwork is written and numbered | no |
| `flows.yml` | Workflow stages, timings, and what closes a stage | no |
| `rules.yml` | The business's own operating rules, as data | `notifications`, `contacts`, `usage_records`, `media`, `ai_spend`, `security_monitor.public_registration` |
| `knowledge.yml` | Company facts the assistant may state | `privacy_view`: safe words and phrases; `names.soundalikes`; `addresses.street_first_words` |
| `voice.yml` | How the business writes | no |

Every value goes through the same check the setting's own screen uses. A value
that fails is **refused**: the preview lists it with the reason, and it is not
applied. Nothing is guessed or rounded into range, and a safety limit is never
widened: a pack may choose a stricter value than the platform's, never a looser
one. `example-business/` carries every key below with neutral example values.

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
    wide:                       # 2:1 PNG: the lockup
      dark: "https://…/logo-wide.png"        # normal colours, for light surfaces
      light: "https://…/logo-wide-white.png" # white/pale, for the topbar
    square:                     # 1:1 PNG: the mark alone
      dark: "https://…/mark.png"
      light: "https://…/mark-white.png"
    favicon: ""                 # optional; falls back to the square mark
```

**`dark` and `light` name the ink, not the background.** Dark ink goes on light
surfaces; light ink is the white version for a dark topbar. This trips everyone
up, so nothing in the code asks you to think about it; a caller asks for the
background it is drawing on and the right ink is chosen for it.

Supplying only one file is fine. The resolver falls back in a declared order:
right shape and ink, then right shape wrong ink, then the other shape, then the
business name as text, and reports what it actually found, so a square standing
in for a wide slot is laid out as a square and centred rather than stretched.
The one exception is the home-screen icon, which takes a square or nothing: a
wordmark squashed into a launcher tile looks worse than the default icon.

If your artwork is 3:1 or 4:5, use it anyway. The settings screen will tell you
the proportions do not match the slot, and the image gets fitted inside its space
with room left over. That is a note, not a rejection.

## The price book

The price book is a priced list of variants (each with a SKU, a size and the
options that pick it) for each product family. It is split across three files
so that what a thing is, what it costs the customer and what it costs you stay
apart:

```yaml
# catalog.yml
price_book:
  families:
    - key: example_doors          # 2-32 lower-case letters, digits, underscores
      label: "Example doors"
      brand: "Example"
      match: { ... }              # the words that read an estimate line (see ZDZ_Price_Book::match_line)
      prompt: "..."               # how a model should write a line for this family
  variants:
    - { sku: EX-PL-36-80, family: example_doors, kind: unit, label: "Plain door 36 x 80",
        dims: { max_width_in: 36, max_height_in: 80 }, attributes: { style: plain } }
# pricing.yml
price_book:
  version: "example-1"
  families: [ { key: example_doors, price_rules: { "Plain door": "100 each" }, rules: [] } ]
  prices:   [ { sku: EX-PL-36-80, price: 100, double_price: 190 } ]
# costs.yml  (supplier costs: shown to administrators only, never written into the catalog)
price_book:
  costs:    [ { sku: EX-PL-36-80, cost: 50 } ]
```

A price or cost for a SKU the catalog does not have refuses the whole book, so a
typing slip never prices the wrong thing. A variant with no price is "quote by
hand". The matching block uses multi-line text, so write these files as JSON.

## Privacy View words

An account in Privacy View sees every capitalised word it does not know as a
name. Your product, trade and place words are known once your pack lists them:

```yaml
# knowledge.yml
privacy_view:
  safe_words:   [ trellis, pergola ]
  safe_phrases: [ "example point", "example bay" ]   # two words or more; safe only together
```

## Trade and street words

Zorderz knows generic building words (door, window, gate, garage, opening) and
the street words common across the United States that come before a street's
name (Via, Calle, Camino, Paseo, Avenida, Plaza). Your trade's words for things
and your region's own street words are added by the pack. A number beside one of
your trade's words is read as a count, a size or a price, never an access code;
an address written with one of your street words is hidden before anything is
sent to the Ai, and becomes a map link in chat answers.

```yaml
# catalog.yml
vocabulary:
  things: [ awning, skylight ]              # also the ask bar's words for things

# knowledge.yml
addresses:
  street_first_words: [ rue, strada ]       # a word or two of letters each
```

## Settings in rules.yml

```yaml
notifications:
  quiet_hours: { start: "21:00", end: "07:00" }   # 24-hour times; the same in both turns quiet hours off
contacts:
  reaching_results: [talked, replied]             # of talked, voicemail, no_answer, replied
usage_records:
  raw_days: 180                                   # whole days, at least 90
media:
  same_property_m: 120                            # metres, 10 to 1000
ai_spend:
  monthly_usd: { app: 25, all: 25, ceiling: 50 }  # app is required: with no line set, nothing is sent
security_monitor:
  public_registration: follow                     # follow (WordPress's setting) or open
```

`security_monitor.public_registration: closed` is refused from a pack (it lowers
how serious flaws that need a login are reported); choose it on Zorderz →
Security Monitor. The monitor's own components, its digest recipients and the
request time limit are set there too, never from a pack.

## Connections

`connections.yml` holds the non-secret half of a connection. Where a credential
is needed, the pack names its row on Zorderz → Credentials with a `secret://`
reference: `secret://anthropic_api_key` and `secret://zdz_core_anthropic_api_key`
name the same row. The preview says whether that row is filled; it never reads
the value and a pack never writes one. **A pack that carries a credential value
anywhere is refused whole**, and the preview says where, so it can be removed
(and the value treated as exposed).

```yaml
ai:
  models: { primary: example-model-large, quick: example-model-small, fallback: example-model-small }
  prices:                                  # USD per million tokens: in, cache write 5 min, cache write 1 h, cache read, out
    example-model-large: [3.00, 3.75, 6.00, 0.30, 15.00]
  anthropic:
    inference_geo: ""                      # a region word (2 to 12 letters), or empty
    geo_price_factor: 1.0                  # 1 to 3: a region never makes a call cheaper
    api_key: "secret://anthropic_api_key"  # checked, never read
  roles:
    reason: { model: example-model-large, effort: high, cap_usd: 2 }   # cap at most 5 a call
  external_callers: [example-reporting]    # outside tools whose spending counts toward the account only
vulnerability_feed:
  key: "secret://wordfence_feed_key"
```

Prices are kept only for models a slot names, and other models' prices stay. A
role's model must be one a slot names. Which provider receives a kind of call is
chosen on the site, never in a pack; so is the Anthropic workspace ID, which
lives next to its key on Zorderz → Credentials (a pack's `workspace_id` is only
compared with it). An app of this site can never be an "outside" caller. Not
read from a pack at all: OpenAI-compatible entries (where business data would be
sent, with retention terms a person must check), a networked geocoder, and
staging hosts (`ZDZ_STAGING_HOSTS` in wp-config.php).

## Territories and the people in a pack

A pack names a person by their **initials on this site** (User Management),
never by e-mail or user ID. Someone not on the team, the shared device, or
initials two people share is skipped and listed; nothing is guessed.

```yaml
# territories.yml
place_names:
  multi_word: ["North Example", "Example Bay"]   # never read as a name; one-word towns are refused
  ask_visible: ["Springfield"]                   # left visible by "Ask chat about this"
jurisdiction:
  code_words: ["example state"]                  # "<word> code 2024" is not an access code
  postcode: { digits: '^\d{5}$', region: 'OR' }  # the whole shape, ^ to $, in single quotes
zones:
  - id: service_area
    label: "Service area"
    kind: service                                # service | area | nogo
    visible: staff                               # staff | admin
    zips: ["97477", "97478"]
  - label: "Kept out"
    kind: nogo                                   # never given work here
    people: [SE]
    zips: ["97479"]
rules:
  stub:
    - id: by_territory
      who: territory
    - fallback: true
      who: default
territories:
  - person: AE
    zips: ["97477", "97478"]

# parties.yml
people:
  - initials: AE
    name: "Alex (example)"                        # for the reader; not applied
    team_roles:
      items:
        ex-pump: { sell: can, install: learning } # can | learning | cannot
      abilities:
        roof-access: learning
```

Write ZIP codes in quotes so a leading zero survives. Zones replace the zone
list; each person named under `territories` gets exactly those ZIP codes, and
people not named keep theirs. **Every Territories rule a pack writes is in
shadow**, whatever the file says: the apps keep their own picks and the rules'
choices are recorded beside them until a person turns each rule live from the
go-live checklist. Team Roles marks keep each cell's note and date; cells the
pack does not name are left as they are. Inside a one-line `{ … }` map, keep
to plain values: the YAML reader splits it at every comma, so a list or a map
inside it goes on lines of its own, as above.

Revert puts back what the pack replaced in these apps only where nothing changed
since, and says what it left alone. Territories rules come back in shadow too,
like every revert in Territories; the ones that were live before are named.

## For apps: the consumer registry

Everything after `profile` and `org` is read through one registry,
`ZDZ_Identity_Pack::consumers()`. The platform's own settings are always in it;
a Core service or an app adds an entry on the `zdz_identity_pack_consumers`
filter (a built-in id cannot be taken):

```php
add_filter( 'zdz_identity_pack_consumers', function ( $c ) {
	$c['example-app'] = array(
		'label'    => 'Example app',
		'files'    => array( 'flows' ),                     // pack files it reads
		'paths'    => array( 'flows' => array( 'stages' ) ), // optional: the keys it reads; others are reported unused
		'preview'  => 'example_preview',   // ( $files ) => rows [file, path, from, to]; 'kind' => refused|note|unconsumed
		'apply'    => 'example_apply',     // ( $files, $slug ) => messages
		'snapshot' => 'example_snapshot',  // ( $files, $slug ) => what to keep; taken before anything is written
		'restore'  => 'example_restore',   // ( $kept ) => messages saying what could not be put back
	);
	return $c;
} );
```

`$files` is stem => parsed file. An app whose data lives in its own tables or
versioned lists keeps exactly what it needs in its snapshot and decides what
putting it back means. `ZDZ_Identity_Pack::person_id( $ref, $ids )` resolves a
pack's person reference the same way for every app.

## Format

YAML or JSON. JSON is preferred when both exist for the same file, because it
parses exactly. The YAML reader handles the subset a pack needs: nested maps by
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
  committed and copied between machines; treat it accordingly. A pack that
  carries a value is refused whole.
- **It may not loosen a safety limit.** Where a setting has a floor (usage
  records kept at least 90 days, a call's cost cap, the shared device never
  getting a notice), a pack's value goes through the same check and is refused
  if it would cross it.
- **It may not turn a rule live.** Rules a pack brings for other apps start in
  shadow; a person turns them live.
- **It may not carry customer records or employee personal contact details.**
  Those are tenant database content, not identity. A pack describes the shape of
  a business, not its people's private information.
- **It may not apply itself.** Nothing writes without a preview, a typed
  confirmation, a snapshot, and a log entry.

## Applying one

**Zorderz → Identity Pack**. Preview shows the actual before-and-after of every
value that would change, what is refused and why, and what nothing reads.
Applying records everything it is about to write first, so **Revert** restores
exactly what was there, "not set" included. Revert is one step deep: the
snapshot of the most recent apply, used once.

Editing a field by hand afterwards overrides the pack for that field. The pack
files are never modified by the platform.

## Writing your own

Copy `example-business/`, change the values, drop it in
`wp-content/zorderz-identity/`, and preview it. Start with `profile.yml` alone;
`org.yml` is optional, and the loader is content with a pack of one file.
