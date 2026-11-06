# Zorderz: Analytics (Chat)

The conversational assistant: the **Chat** surface of the Zorderz dashboard. Ask a
question in plain language and the assistant answers from the business's own systems
of record, through the platform's one Ai router.

App id **`sales-analytics`** (the theme grants and labels it, and the dashboard KPI
tiles and digest deep-link route to it). PHP prefix `zana` / `ZANA_`. Text domain
`zorderz`. GPL-2.0-or-later.

## Architecture

Theme Core services it uses:

- **`ZDZ_Core_LLM`** — every model call (`ZDZ_Core_LLM::ask( $role, … )`, caller
  `sales-analytics`). Customer details are coded by `ZDZ_Private` before anything
  leaves; with no coder nothing is sent. Models, prices and the monthly budget are
  the business's settings (Zorderz → Ai usage); nothing is sent until they are set.
- **`ZDZ_Capabilities`** — the verb registry. What the chat may ask an app to do is a
  verb there; reads are answered by the app, writes return `needs_confirm`.
- **`ZDZ_Answer_Authority`** — the single outbound gate every reply passes.
- **`ZDZ_Rule_Governance`**, **`ZDZ_Business_Profile`**, **`ZDZ_Item_Engine`**,
  **`ZDZ_Party`**, **`ZDZ_Price_Book`**, **`ZDZ_Data_Permissions`** — what the
  instructions are built from.
- **`ZDZ_Ask_Context`** — the platform's data fence (`FENCE_START` / `FENCE_END`) and
  the server-side check of a widget block shared into the chat.
- **`ZDZ_Privacy_View`** — the masked road for an account in Privacy View.

This module:

- `ZANA_Prompt_Builder` — the single author of the instructions, in three parts with
  the router's cache marks between them (fixed / per level / per person and turn).
  Neutral persona ("the assistant") unless the business's voice pack names it.
- `ZANA_LLM_Client` — the chat's road to the router: roles, cached prefix and level
  blocks, the customer-codes note, the web brief, the names check, decoding for the
  person who asked, words for every refusal.
- `ZANA_Allowance` — each person's daily Ai allowance (Core: none; the monthly budget
  is the floor).
- `ZANA_Chat` — one turn: shared device refused, Privacy View codes, widget blocks
  re-checked, classify, assemble, router, plain math, actions, gate, persist.
- `ZANA_Actions` — `[ZDZ_ACTION]{"verb":…,"args":…}[/ZDZ_ACTION]` from the model:
  provenance check, the registry's who-may, reads answered by the app, writes held for
  a Confirm press (stored per person and chat, 10 minutes, consumed once).
- `ZANA_Plain_Math` — LaTeX in an answer becomes plain text; money stays money.
- `ZANA_Referrals` (+ `assets/js/referrals.js`) — "Needs a referral" over the
  Territories app's cases (`territories.uncovered`, `zdz_referral_closed`); one web
  search, vetting, an email opened (never sent) from the panel.
- `ZANA_Pseudonym_Route`, `ZANA_Ajax` — Privacy View: a code is never searched for;
  the admin-ajax road with self-masking replies.
- `ZANA_Background`, `ZANA_REST` — the async turn queue and the routes under
  `ZDZ_REST_NS`.
- `ZANA_Markers` — the chat's protocol tokens in one map.

## Ships empty

Activation creates the tables only. No business facts, roster, price list, persona or
phrase pack. On a fresh install the assistant is neutral and honest; with no data
connector bound it will caveat or refuse figure questions rather than invent numbers.

## Extension points (bind a connector, degrade to neutral)

- `zdz_analytics_data_context` — the turn's fetched data + `verified_figures` +
  `sor_outcomes`. Ships empty.
- `zdz_system_of_record_label`, `zdz_crm_label`, `zdz_counting_component_label`,
  `zdz_service_area_label` — labels the instructions use.
- `zana_voice` option / `zdz_assistant_voice` filter — the voice pack (`name`, `lines`).
- `zana_ai_allowance` option / filter — the daily allowance (Zorderz → Settings).
- `zana_llm_primary_only` filter — pin the chat to the primary model.
- `zdz_chat_markers` — an app's own action marker for its verb (`verb`, `token`).
- `zana_zip_place_url` option / filter — a public postal-code service (`{zip}`) for
  place names; Core: none.
- `zdz_compose_handoff` — the Email app's compose hand-off for the referral email.
- `zdz_referral_email_draft` — the referral email's wording (a voice pack).
- `zdz_rules` — a business adds or narrows rules (safety floor protected).

## Not here (separate surfaces)

Token streaming; the scheduled digests; the web-analytics feeds; the self-check auditor
pass; the memory extractor; deep research; voice and picture input. Each is absent and
documented, never present and leaky.

## The stored-data ledger

A copy of the billing system's invoices, estimates and payments and the CRM's leads and
lead notes (`zana_ledger_*` tables), kept in step by an hourly, resumable, read-only sync
(`ZANA_Ledger`; never on a staging copy). What a business's paperwork means (lead-source
codes, note tags, the location line, brands, source groups, the first history year) is
data: the `zana_ledger_rules` option / filter (`ZANA_Ledger_Rules`), which an Identity Pack
sets through its "sales-ledger" consumer (`mappings` `lead_sources.*`, `catalog`
`ledger.product_codes` / `ledger.brands`, `document-conventions` `ledger.self_codes` /
`ledger.location_line_names` / `ledger.label_number_words`, `rules` `ledger.history_from_year`
/ `ledger.intake_minutes`; revert puts the option back). Core derives nothing a business did
not say. Every read goes through `ZANA_Ledger_Access` (deny by default).

- `ledger.home_figures` (`ZANA_Home_Figures`) — the home tiles' month: revenue (billed,
  collected, owed, received), estimates, CRM leads; the company's, or a salesperson's own
  counts. "Contacted" reads the CRM notes copy: a person's note after the intake window, or
  a contact an app recorded (`zana_crm_contact_note_patterns`); the contact log's own notes
  and the openings apps declare on `zana_crm_app_note_patterns` are not counted.
- `ask.quick` (`ZANA_Quick_Answers`) — the ask bar's answers from stored data; other routes
  join on `zana_quick_routes`.
- `ZANA_Ledger_Answers` — sales, estimates, money owed, invoices paid, customers by rule
  (`detect`, `resolve`, `answer`, `quick`).
- `ZANA_Lead_Sources`, `ZANA_Billing_Gaps` — leads by source; accepted estimates never
  invoiced.
- `ZANA_Ledger_Reports` — the chat's DATA LEDGER block on `zdz_analytics_data_context`.
- `ZANA_Invoice_Doc` — one invoice or estimate opened in the chat (REST
  `analytics/invoice-doc`, `analytics/invoice-link`).
- `ZANA_Ledger_Shadow` — would the ledger have given the same documents as a live read.
- Zorderz → Settings: the ledger's state and "Sync now".

## Tests

`tests/unit/AssistantChatTest.php`, `tests/unit/LedgerSyncTest.php` and
`tests/unit/LedgerAnswersTest.php` (the suite) and `php tests/test-core-services.php`
(standalone). Neutral data only.
