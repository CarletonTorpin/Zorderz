# Zorderz — Analytics (Chat)

The conversational analytics assistant: the **Chat** surface of the Zorderz
dashboard. Ask a question in plain language and the assistant answers from the
business's own systems of record.

Generalized from the internal analytics/chat plugin. App id kept as
**`sales-analytics`** (the theme grants and labels it, and the dashboard KPI tiles +
digest deep-link route to it). PHP prefix `zana` / `ZANA_` (from `tsa` / `TSA_`;
the legacy slug is recorded as a deprecated alias in the rename map). Text domain
`zorderz`. GPL-2.0-or-later.

## Architecture

The heavy mechanisms were extracted into three **theme Core services** so every
app shares them, not just this one:

- **`ZDZ_Answer_Authority`** — the confidence tier (confirmed › derived › inferred
  › unknown) that propagates through arithmetic, and the **single outbound gate**
  every channel (chat/email/push/digest/stream) calls before emitting. Enforces
  INV-12: state facts only; refusal is valid; never outcome language unless the
  system of record confirms it.
- **`ZDZ_Rule_Governance`** — rules as typed, parameterised objects in a registry;
  the prompt is a **rendering** of the rule set. A cited rule that does not exist
  fails loudly. The off-repo assistant's rule corpus is brought in-repo as neutral,
  placeholder-driven templates; safety-floor rules are non-overridable.
- **`ZDZ_Model_Registry`** — central per-task model slots (chat/planner/auditor/
  memory/transcription/vision/dedup/kiosk), replacing the ~12 hardcoded model
  names, with a capability-tier map, cross-vendor fallback and an idempotent
  retired-value remap. Poe stays the v1 gateway (`ZDZ_Core_Poe`); endpoint, key
  and model are all config.

This module contributes:

- `ZANA_Prompt_Builder` — the **single author** of the system prompt, assembled at
  runtime from the Business Profile, the Item Engine catalog, the Party roster, the
  permission matrix and the rendered rule set. No typed company/person/product
  name; the roster renders from `ZDZ_Party` (real users only), so a nonexistent name
  is structurally impossible.
- `ZANA_Chat` — one synchronous turn: classify → assemble → fence data → resolve
  model → call gateway → **gate** → persist. Fails loudly, never fabricates.
- `ZANA_REST` — routes under `ZDZ_REST_NS` (`zorderz/v1/analytics/*`), access-gated.
- `ZANA_Markers` — the one constants map for the chat protocol tokens.
- The **Chat** bottom-nav tab + sub-view (`assets/js/chat.js`).

## Ships empty

Activation creates the session/message schema **only**. No company facts, roster,
price list or supplier corpus. The source plugin's shipped company-facts JSON is
intentionally **omitted** — facts arrive only via a consented Knowledge pack. On a fresh install
the assistant is neutral and honest; with no data connector bound it will
caveat/refuse figure questions rather than invent numbers (correct behaviour).

## Extension points (bind a connector, degrade to neutral)

- `zdz_analytics_data_context` — supply the turn's fetched data + `verified_figures`
  + `sor_outcomes`. Ships empty.
- `zdz_system_of_record_label`, `zdz_crm_label`, `zdz_counting_component_label` —
  the labels the prompt/rules use. Neutral defaults; **no vendor named in code**.
- `zdz_rules` — tenants ADD or NARROW rules (safety floor protected).
- `zdz_model_slot_*` options / `zdz_model_for`, `zdz_model_retired_map`,
  `zdz_model_fallback` — model routing.
- `zdz_chat_markers` — cross-app marker verbs (owned by their own modules).

## Deferred surfaces (reported, not shipped half-built)

The source engine is ~18.5k lines. The following are **deferred** and wired as
documented extension points rather than shipped broken:

- the off-repo **async job queue + polling** and **token streaming** channels;
- the scheduled **daily/weekly digests**;
- the live **billing / CRM / web-analytics** data connectors — replaced by the
  neutral `zdz_analytics_data_context` filter;
- the **self-check auditor** second pass and the **memory extractor** (both need
  their own model slots + consent story);
- attachments / audio transcription / vision passes.

Each is a separate surface. Per the release discipline, a deferred surface is
absent-and-documented, never present-and-leaky.

## Tests

`php tests/test-core-services.php` — synthetic, standalone (WP stubs). Exercises
tier propagation, the outbound gate, loud rule citation, the safety-floor guard,
and the model registry's remap/fallback. All fixtures are synthetic.
