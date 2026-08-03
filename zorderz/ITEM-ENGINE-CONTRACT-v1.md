# Item Engine Contract (v1)

> **Status:** Stable (the catalog authority every module binds to)
> **Since:** Theme v1.1.0
> **Owner:** `ZDZ_Item_Engine` (`inc/class-zdz-item-engine.php`)
> **Admin:** Zorderz → Item Engine (`ZDZ_Item_Engine_Admin`)
> **Depends on:** `ZDZ_Business_Profile` (currency sign only), WordPress taxonomy engine
> **Depended on by (as they port):** Commission, Analytics/Chat, Estimate Creator, Prep (cut queue), Receipts, Stock, Leads, Surveys, Jobs

---

## Why this exists

Before the Item Engine, "what this business sells" was hardcoded in **eight** places: a
separate product taxonomy in every module, each drifting from the others. That taxonomy was
also the **wire format** between modules: one app counted units into a fixed seven-bucket enum
and taught every other app those seven English words. A business that did not sell those seven
things got empty numbers.

The Item Engine replaces all eight copies with one admin-defined catalog and makes the
cross-app count vocabulary **catalog-driven**. There are **no hardcoded product names** anywhere
in the engine.

## The model (fixed)

- **Two types only.** Every Item is a `product` (tangible) or a `service` (intangible). Fixed forever.
- **User-named subtypes** underneath, each `global` (offered on future items) or `one_off`.
  Subtypes are WordPress taxonomy terms (`zdz_item_subtype`): the platform's own taxonomy engine,
  not a parallel store. Scope/type/priority live in term meta.
- **Per-item Pricing Schemes**, reusable and cloneable: `flat`, `per_unit`, `per_hour`, `per_area`,
  `per_visit`, `tiered`, `formula` (e.g. `cost * markup`), `quote_only`.

## Ship-empty guarantee

Activation creates **schema only** (`wp_zdz_items`, `wp_zdz_pricing_schemes`, and the
taxonomy registration). **No item, subtype, price or SKU is ever seeded.** With an empty catalog
every resolver returns a neutral value (`null` / `''` / `[]`), so every consumer falls back to its
own neutral default and nothing breaks. An optional, clearly-marked **sample set** exists
(`ZDZ_Item_Engine::sample_pack()`) but is applied only by an explicit, typed-confirmation admin
action, never automatically. The sample set is fictional and names no real product, person, place,
or company.

---

## The COUNTS CONTRACT (INV-3)

The single authority for the count vocabulary is `ZDZ_Item_Engine`. Count categories are **Items**,
not a fixed enum. "How many of a given kind?" becomes a *grouping question*, not a schema question.

### Authority methods

```php
ZDZ_Item_Engine::count_categories() : array   // item_id => count meta (the vocabulary)
ZDZ_Item_Engine::kinds()            : array   // countable item ids
ZDZ_Item_Engine::classify( $text )  : ?string // free text -> item id (a "kind"), or null
ZDZ_Item_Engine::aliases()          : array   // alias => item id (for prompts / JS export)
ZDZ_Item_Engine::count_meta( $id )  : array   // meta for one id
ZDZ_Item_Engine::count_phrase($id,$n): string // "3 screens" using the item's own unit noun
ZDZ_Item_Engine::version()          : int     // content version / cache-buster
```

An Item is a count category when its `countable` flag is set. Empty catalog ⇒ all of these return
empty/null.

### The count payload shape: `item_keyed_v2`

Producers build a payload with `new_counts()` / `add_count()`; consumers **branch on the shape
discriminator**, never by probing for keys.

```php
$c = ZDZ_Item_Engine::new_counts( $requested_item_ids );
$c = ZDZ_Item_Engine::add_count( $c, $item_id, $n );

// resulting shape:
[
  'shape'              => 'item_keyed_v2',        // discriminator: branch on this
  'counts'             => [ item_id => int ],     // SCALAR ONLY at the top level
  'counts_meta'        => [ item_id => [
                             'type', 'subtype', 'display_name',
                             'unit_noun_singular', 'unit_noun_plural',
                             'parent_item_id', 'by_attribute',
                          ] ],
  'requested_item_ids' => [ item_id, ... ],       // absent != zero
]
```

Rules the shape enforces (validated by `ZDZ_Item_Engine::validate_counts()`):

1. **Scalar-only counts.** `counts` is `{ item_id => int }`. Brand/other splits go in
   `counts_meta[id]['by_attribute']`, never nested inside `counts`. (The old enum went heterogeneous
   once, a nested `*_by_brand` map, and broke its consumer. This is why.)
2. **Unit nouns per item.** Every prose builder reads `unit_noun_singular` / `unit_noun_plural`
   from `counts_meta` (or uses `count_phrase()`), so no module ever hardcodes "screen"/"door"/"unit".
3. **Composition stays visible.** A parent job line whose count comes from child lines carries
   `parent_item_id` in its meta, so regrouping never double-counts.
4. **A shape discriminator.** `shape: 'item_keyed_v2'`: consumers switch on it instead of guessing
   which shape they received.
5. **Absent ≠ zero.** `requested_item_ids` travels alongside `counts`, so "we didn't ask" stays
   distinguishable from "we sold none."

### Legacy vocabulary adapter

A tenant that still has a module speaking an old bucket word maps it through the catalog:

```php
add_filter( 'zdz_item_legacy_count_map', fn( $m ) => $m + [ 'legacy_word' => 'some-item-id' ] );
ZDZ_Item_Engine::legacy_count_map();  // legacy_key => item_id
```

Ships **empty**: the platform defines no legacy words.

---

## Consumer contract: the canonical resolver API

Future consumers (Commission, Estimate, Stock, the Prep/Receipts pair) bind to this. Prefer the static API
when class load order allows; otherwise use the mirrored filters.

### Static API

```php
ZDZ_Item_Engine::get( $item_id )          : ?array          // one item
ZDZ_Item_Engine::all( $filter = [] )      : array           // filter: type, subtype, sellable,
                                                            //   countable, active, parent_item_id,
                                                            //   attributes => [ key => value ]
ZDZ_Item_Engine::match( $text, $opts=[] ) : ?array          // longest-alias-wins -> full item
ZDZ_Item_Engine::match_all( $text )       : array           // one line may name several items
ZDZ_Item_Engine::aliases_flat()           : array           // alias => item_id
ZDZ_Item_Engine::pricing_scheme( $id )    : ?array
ZDZ_Item_Engine::resolve_price( $id, $ctx): array           // { amount, method, quote_only, explain }
ZDZ_Item_Engine::eval_formula( $expr, $vars ) : float       // safe, no eval()
```

Resolver semantics: aliases match **longest-first**, ties broken by `match_priority`. A `match_mode`
per alias (`substring` default / `word_boundary` / `exact`) and per-item `negative_aliases`
(tokens whose presence *disqualifies* the item) are honoured. An empty catalog returns `null`.

### Mirrored filters (no class dependency)

| Filter | Signature | Returns |
|---|---|---|
| `zdz_item_classify` | `($pre, $text)` | item id or null |
| `zdz_item_match` | `($pre, $text, $opts)` | item array or null |
| `zdz_item_aliases` | `($pre)` | alias => id |
| `zdz_item_kinds` | `($pre)` | countable ids |
| `zdz_item_count_categories` | `($pre)` | id => meta |
| `zdz_item_get` | `($pre, $id)` | item array |
| `zdz_pricing_resolve` | `($pre, $scheme_id, $ctx)` | price result |
| `zdz_item_engine_version` | `($pre)` | int |

Each passes through a non-empty `$pre`, so a plugin may short-circuit; otherwise the engine answers.

### Write API (admin + discovery approval)

```php
ZDZ_Item_Engine::save_item( $item )            : true|WP_Error
ZDZ_Item_Engine::delete_item( $id )            : bool
ZDZ_Item_Engine::save_scheme( $scheme )        : true|WP_Error
ZDZ_Item_Engine::clone_scheme( $id, $nid, $nm ): string|WP_Error
ZDZ_Item_Engine::ensure_subtype( $slug, $label, $scope, $type, $priority ) : string|WP_Error
```

### Discovery (hooks only, never tenant data)

```php
ZDZ_Item_Engine::discover( $sources = [] )  // returns a PROPOSAL; writes nothing
ZDZ_Item_Engine::approve_proposal( $items, $schemes )  // the only "approved becomes real" path
```

Connectors attach to `zdz_item_discovery_propose` (return `{items, schemes, notes}` named verbatim
from tenant data) and `zdz_item_discovery_sources`. `zdz_item_discovery_enabled` gates the flow.
Discovery never auto-applies and never editorialises about prices. The platform ships no connectors,
so out of the box the proposal is empty.

---

## Adapters: the shipped Jobs module resolves THROUGH the engine

The Jobs module (`zorderz-apps/apps/jobs`) already binds four filters with neutral fallbacks. The
Item Engine registers on each so Jobs becomes catalog-driven, while an **empty catalog leaves Jobs'
own `other` / `service` defaults untouched**.

| Jobs filter | Engine adapter | Empty-catalog behaviour |
|---|---|---|
| `zdz_default_job_component` (default kind, fallback `'other'`) | first countable kind → its subtype/id | returns the incoming `'other'` |
| `zdz_job_components` (kind ⇒ label map) | subtype (or item) ⇒ label map | returns the incoming neutral map |
| `zdz_job_classify_component` (`$pre, $text`) | `match()` → subtype/id, else null | returns `null` ⇒ Jobs' generic heuristic |
| `zdz_job_detect_brand` (`$brand, $text`) | matched item's `attributes.brand` | returns `''` |

This is the pattern every other consumer follows: **bind a filter, degrade to neutral, never invent
a taxonomy.** The Item Engine's contract *subsumes* the Jobs taxonomy filters: Jobs no longer owns
a component list; the catalog does.

---

## Pricing Schemes

A scheme is a reusable, cloneable object (`wp_zdz_pricing_schemes`), referenced by an Item's
`pricing_scheme_id`. `resolve_price( $scheme_id, $ctx )` returns
`{ amount, method, quote_only, explain }`.

| Method | Context keys | Result |
|---|---|---|
| `flat` | none | `params.amount` |
| `per_unit` | `qty` | `rate × qty` |
| `per_hour` | `hours` | `rate × hours` |
| `per_area` | `area` or `width_in`+`height_in` | `rate × area`, floored at `params.min_charge` |
| `per_visit` | none | `rate` (a minimum/visit charge) |
| `tiered` | `params.axis` (default `qty`) | bracket over `params.tiers[{up_to,amount}]` |
| `formula` | any names in the expression | `eval_formula(expression, params+ctx)` |
| `quote_only` | none | `amount = null` (a declared "no price" state) |

`per_hour` ships even though the demo trade has no hourly rate: the proof the method set is CORE,
not tenant. The formula evaluator is a closed shunting-yard parser (`+ - * /`, parens, named vars;
unknown var ⇒ 0; divide-by-zero ⇒ 0) with **no `eval()`**.

---

## Storage & versioning

| Table | Key | Holds |
|---|---|---|
| `wp_zdz_items` | `id VARCHAR(80)` | the catalog (id-keyed so ids can be meaningful and old ids survive as aliases) |
| `wp_zdz_pricing_schemes` | `id VARCHAR(80)` | reusable pricing schemes |
| taxonomy `zdz_item_subtype` | term slug | subtypes + scope/type/priority in term meta |

`ZDZ_Item_Engine::version()` is bumped on every write. Consumers fold it into their own
classification-cache keys so editing the catalog self-invalidates downstream caches with zero
backfill.

---

## REST (publishes the vocabulary to JS)

All under `ZDZ_REST_NS` (`zorderz/v1`), logged-in only:

- `GET /item-engine/catalog`: version, empty flag, types, subtypes, items
- `GET /item-engine/count-categories`: shape, kinds, count categories
- `GET /item-engine/classify?text=…`: `{ kind, item }`

---

## What you must NOT do

1. **Do not hardcode a product name, alias, brand, or count bucket** in a consumer. Read it from the
   catalog. A name absent from the catalog must degrade to neutral, not to a baked-in guess.
2. **Do not put a non-scalar inside `counts`.** Splits belong in `counts_meta[id].by_attribute`.
3. **Do not seed catalog rows on activation.** Schema only. Use the sample set (confirmed) or
   discovery (approved) instead.
4. **Do not branch on count payload shape by probing for keys.** Switch on `shape`.
5. **Do not couple a consumer to the Jobs filters for product taxonomy**: bind the canonical
   `zdz_item_*` filters or the static API.

---

*End of Item Engine Contract v1*
