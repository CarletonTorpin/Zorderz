<?php
/**
 * Module: Zorderz - Leads
 * Description: Weekly sales-lead generation for the Zorderz dashboard. Cross-references
 *   the configured billing provider (paid invoices = past customers) and CRM, scores and
 *   de-duplicates candidates, and assigns each to a service-area owner. Ships with NO
 *   business data: the service-area/territory map is empty (allow-all) until an admin
 *   configures it, the roster resolves through ZDZ_Party, product vocabulary binds through
 *   a filter with a neutral fallback, and the outbound email voice is a neutral default.
 *   Consumes Business Profile, Party, Core settings and the theme geocoder.
 * Version:     2.7.0
 * Author:      Zorderz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 8.0
 *
 * This is a bundled app module (loaded by zorderz-apps.php), not a standalone plugin.
 * It registers with the theme through the `zdz_register_apps` filter on
 * after_setup_theme and declines cleanly when the theme is absent.
 *
 * CORE-SERVICE BINDINGS (services not yet built are bound via a documented filter with a
 * neutral fallback — no competing taxonomy is invented, and nothing is silent):
 *   - Service Area : `zl_territory_for_postal` resolves a postal code to a territory code.
 *                    The map ships EMPTY (allow-all); an unmatched lead is a LOGGED
 *                    disposition (do_action 'zdz_flow_disposition', never a silent drop).
 *                    The theme geocoder supplies place data.
 *   - Party        : the salesperson roster + owner resolution come from ZDZ_Party
 *                    (selectable_people), never a local roster constant/seed.
 *   - Item Engine  : product vocabulary (categories/aliases/humanize map) binds through
 *                    `zl_product_*` filters; neutral fallback ('the items we provided').
 *   - Mappings     : CRM pipeline/stage/tag/source names resolve through `zl_crm_*`
 *                    filters (tenant Mappings), never hardcoded product/pipeline strings.
 *   - Voice        : the check-in email voice binds through `zl_email_voice`; Core ships a
 *                    neutral default (the statistically-derived house voice is private).
 *
 * SAFETY FLOOR: lead scoring carries no trade-season assumption by default, and the
 * name-based gender demographic classifier is DISABLED by default (ethics/product review —
 * see zl_gender_demographic_enabled).
 *
 * v2.6.0 (interop L3 — shared Nutshell credentials). ZL_Nutshell now sources its
 *   Nutshell email/API-key from the theme's shared ZDZ_Core_Settings when not
 *   explicitly passed (the SAME single source the shared ZDZ_Core_Nutshell uses),
 *   so ZL no longer keeps an independent credential copy that could drift or
 *   revoke a sibling's token (CONTRACT §1.2). Explicitly-passed creds still win,
 *   so existing callers are unaffected; the constructor params now default to ''
 *   (also making the documented no-arg construction safe). The Nutshell transport
 *   (discovery + descriptive exceptions) is unchanged — this only unifies WHERE
 *   the credential comes from. The bridge already registers lead.lookup/find/create
 *   via zdz_register_capabilities (v2.5.1).
 *
 * v2.5.1: No nested scrolling at ANY width (widget.css only; pairs with theme
 *   v2.25.0). The plugin already eliminated internal scroll containers, but
 *   only under @media (max-width:480/768px) — on desktop the lead lists / panel
 *   bodies / "All Leads" still trapped a scrollbar. Those caps are now lifted at
 *   every width, so the widget grows to its natural height and the dashboard
 *   page scrolls as one document; "All Leads" expands in-flow and grows the
 *   widget downward. Only the transient build/progress console keeps an inner
 *   scroll (it's a live log, not dashboard content).
 *
 * ============================================================================
 * PROGRAMMER NOTES & ARCHITECTURE OVERVIEW
 * ============================================================================
 * ROLE: Main bootstrap file. Defines constants, handles DB schema creation/upgrades,
 * and loads all class files.
 * 
 * MODEL:
 * Leads are generated for the tenant's own salespeople. Each salesperson owns one or
 * more territory codes; a territory is a set of postal codes (admin-configured, empty by
 * default). Ownership resolves through ZDZ_Party — there is no built-in roster.
 *
 * PROVIDERS (bound through Core connections/settings, not hardcoded):
 * - Billing provider (OAuth 2.0): fetches paid invoices to find past customers.
 * - CRM (JSON-RPC): cross-references contacts, creates leads/notes.
 * - AI gateway: product filtering and description rewriting (model resolved from settings).
 * 
 * 8-STEP GENERATION PIPELINE (AJAX Orchestrated in dashboard.js):
 * 1. Start batch (DB record created)
 * 2. Fetch invoices (FreshBooks)
 * 2b. Expand filter (AI expansion of product keywords - v1.2.1 fix for 502s)
 * 3. Enrich chunks (FreshBooks + Nutshell + Cooldown check)
 * 4. Select leads (Service-area coverage match [logged disposition] -> Score -> Sort -> DB)
 * 4.5. AI validate (Strict product filter verification)
 * 5. AI refine (Rewrite descriptions to <101 chars for Nutshell limits)
 * 6. Create Nutshell (Creates Contacts, Leads, Notes)
 * 7. Finalize (AI batch summary, mark complete)
 * v1.5.0 — Full-parity inline widget, permission system (role + username gating).
 * v2.3.0 — Orchestrator interop (L1 read bridge). Adds ZL_TSA_Bridge so the
 *          cross-app operator bot (TSA/"Brain Bot") can look up a person's
 *          leads/pipeline status and find leads by filter, server-side, with
 *          tier/kiosk redaction enforced in the bridge (never by the model).
 *          Read-only; conforms to ORCHESTRATOR-INTEROP-CONTRACT-v1.md. Verbs are
 *          shaped as future zdz_register_capabilities callbacks and registered
 *          additively below (harmless until the Stage-1 resolver lands). See the
 *          bridge class header and INTEROP-ZL-sales-leads-v1.md for the full map.
 * v2.3.1 — Fix: check-in email body lost its line breaks in the mail client. The
 *          mailto: body is built with blank lines between sentences (the house
 *          voice's one-thought-per-line shape), but a lone LF (%0A) is dropped by
 *          many mail clients (incl. iOS/macOS Mail), collapsing the email into one
 *          run-on block. Now normalized to CRLF before encoding (widget.js). Copy
 *          unchanged — transport-encoding fix only.
 * v2.3.2 — Email BODY brought fully on-voice. The v2.3.x line had carried the OLD
 *          check-in body (throat-clearing openers "I was thinking about you" / "You
 *          crossed my mind this week", run-on middles, filler closers "let me know
 *          if there's anything I can do"). Ported the house-voice body from the
 *          parallel v2.2.2 work: bare-fact openers (no narrated intent — "our
 *          actions communicate our desires"), one substantive offer line naming a
 *          concrete deliverable (on-site look + same-day price), and NO closing
 *          filler line. Added the `installedThing` grammar normalizer so every
 *          humanizeProduct form reads correctly (verified: 162 opener×time×product
 *          combos, 0 issues). Intentional departures documented in-code: no ask
 *          (RULE 6) + name+phone signature (RULE 8) — do not "fix" back. The v2.3.1
 *          mailto CRLF fix and v2.3.0 orchestrator bridge are preserved; subject
 *          lines remain the on-voice v2.2.1 set.
 * v2.4.0 — Per-user lead assignment (Phase 1 of the per-user-leads program). Adds an
 *          explicit, authoritative per-lead owner (assigned_user_id/assigned_at/assigned_by
 *          on wp_zl_leads) so an app USER — not a fuzzy code — owns a lead, as the
 *          platform's own checkable data usable with NO CRM account. New ZL_Lead_Assignment:
 *          explicit assign()/unassign (admin/operator only, audited), a hardened code→user
 *          resolver (exact-meta first, fuzzy only as a backfill seed), an ownership GATE
 *          (current_user_can_act_on_lead — a salesperson may act ONLY on leads assigned to
 *          them; kiosk denied), and assigned-count helpers for the dashboard tile. The
 *          contact-status handler now enforces that gate (closes the prior "any app user
 *          could act on any lead" gap). Idempotent migration backfills ownership from each
 *          lead's batch salesperson code; unresolved rows stay NULL (admin-only). New AJAX:
 *          zl_assign_leads, zl_get_assignable_users, zl_my_leads_count. Nutshell stays
 *          DECOUPLED — assignment is TS-owned; the CRM is enrichment only. (Dashboard tile +
 *          rep-mode widget = Phase 2; WP↔Nutshell user mapping = Phase 3; rigorous
 *          stage-advance engine = Phase 4. See ZL-PER-USER-LEADS-AND-PIPELINE-PLAN-v1.md.)
 * v2.5.0 — Salesperson dashboard tile + rep-mode widget (Phase 2). The plugin SIDE:
 *          (a) ZL_Dashboard_Tile registers a "N leads to contact (M new today)" item
 *          into the theme's unified zdz_dashboard_action_items feed — server-authoritative,
 *          cached in a short transient with a safe-zero fallback, kiosk-excluded, and
 *          cache-busted on assign + status-change so counts stay truthful. (b) Rep mode in
 *          widget.js: when the server marks the user a rep (zlWidgetData.isRepMode), the
 *          widget hides the generate/batch machinery and shows ONLY their assigned leads,
 *          wires the server-rendered "new leads today" banner, honors the dashboard tile's
 *          { view:'my-leads' } launch option (via zdz_app_launch detail.options), and
 *          refreshes counts after each action through a single rep-mode branch in
 *          reloadBatchLeads. (c) New server handler ajax_my_leads returns the signed-in
 *          rep's ASSIGNED leads — hard-scoped to self (admins may view a rep's queue),
 *          permission-scrubbed, actionable-first ordering. The frontend tile itself lives
 *          in the theme (v2.21.3): renderLeadsTile() + #leads-tile + .leads-tile CSS,
 *          deep-linking here. Still Nutshell-DECOUPLED end to end. (Phase 3 = WP↔Nutshell
 *          user mapping; Phase 4 = stage-advance engine.)
 *
 * DATABASE SCHEMA (Created in zl_activate):
 * 1. wp_zl_batches: Tracks generation jobs and their settings/filters.
 * 2. wp_zl_leads: Individual leads generated, their scores, and CRM IDs.
 * 3. wp_zl_lead_history: De-duplication table (enforces 90-day default cooldown).
 * ============================================================================
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants for versioning and path resolution
define( 'ZL_VERSION', '2.7.0' );
define( 'ZL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Transient TTL for inter-step state (customers, candidates, options, etc.).
// 4 hours — enrichment of 9,000+ customers at ~6s per 10 can take 90+ minutes,
// plus fetch time (4-5 min) and slow-chunk retries can stretch the total runtime.
define( 'ZL_TRANSIENT_TTL', 14400 );

/**
 * v2.1.0 — Tunable enrichment concurrency caps (SPEED, no result change).
 *
 * Concurrency only affects how FAST the background pipeline runs; it never
 * changes which leads are selected, how they're scored, or what's written to
 * Nutshell. These caps were previously hardcoded (Nutshell=8, FreshBooks=4);
 * raising them modestly speeds up enrichment on healthy connections. Each is
 * overridable per-environment via a filter (and clamped to a safe ceiling by
 * the dispatcher, which also halves on HTTP 429). Set conservatively if your
 * APIs rate-limit aggressively.
 *
 *   zlg_nutshell_parallel  — bulk Nutshell email lookups   (was 8 → 10)
 *   zlg_fb_parallel        — FreshBooks client fetches      (was 4 → 6)
 *   zlg_poe_parallel       — AI classification (unchanged 4, 429-aware)
 */
if ( ! defined( 'ZL_NUTSHELL_PARALLEL' ) ) { define( 'ZL_NUTSHELL_PARALLEL', 10 ); }
if ( ! defined( 'ZL_FB_PARALLEL' ) )       { define( 'ZL_FB_PARALLEL', 6 ); }

/**
 * Salesperson roster (Party binding).
 *
 * The roster is NEVER a code constant and is NEVER seeded on activation. It comes from the
 * admin-managed `zl_salespeople` option when present; otherwise it is derived from
 * ZDZ_Party (active parties with a valid email + salesperson initials), so a fresh install
 * reflects the real team without a hardcoded list. Each entry is { code, name, territories }
 * where `code` is the external/CRM label (the party's initials) and `territories` is a
 * comma-separated list of territory codes the party owns. Ships EMPTY.
 *
 * @return array<int,array{code:string,name:string,territories:string}>
 */
function zl_salespeople(): array {
	$raw  = get_option( 'zl_salespeople', '' );
	$list = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : array() );
	if ( is_array( $list ) && ! empty( $list ) ) {
		return array_values( $list );
	}
	// Derive a skeleton from the Party roster — no local seed, no invented names.
	$out = array();
	if ( class_exists( 'ZDZ_Party' ) && method_exists( 'ZDZ_Party', 'selectable_people' ) ) {
		foreach ( (array) ZDZ_Party::selectable_people() as $p ) {
			$code = isset( $p['initials'] ) ? strtoupper( trim( (string) $p['initials'] ) ) : '';
			if ( $code === '' ) {
				continue;
			}
			$out[] = array(
				'code'        => $code,
				'name'        => (string) ( $p['name'] ?? '' ),
				'territories' => $code,
			);
		}
	}
	return (array) apply_filters( 'zl_salespeople', $out );
}

/**
 * Product vocabulary (Item Engine binding).
 *
 * How raw invoice line-items map to scoring categories, and how a filter keyword expands to
 * synonyms/abbreviations, are trade-specific — so Core ships them EMPTY and binds them
 * through filters. The shared Item Engine will supply these when it lands; until then a
 * tenant populates them via the filters below without touching code. NO product name is
 * hardcoded, and an empty vocabulary simply means "no keyword expansion / no scoring bonus".
 */
function zl_product_categories(): array {
	return (array) apply_filters( 'zl_product_categories', array() );
}
function zl_product_aliases(): array {
	return (array) apply_filters( 'zl_product_aliases', array() );
}
function zl_single_word_product_terms(): array {
	return (array) apply_filters( 'zl_single_word_product_terms', array() );
}

/**
 * Service-area territory map (postal code → territory code).
 *
 * Replaces the old hardcoded zip table and its "Strict Territory Rule". Ships EMPTY, and an
 * empty map means ALLOW-ALL: with nothing configured, no lead is ever dropped for being out
 * of area. The map is admin/settings data (`zl_territories` option) and/or the
 * `zl_zip_territories` filter. A lead whose postal code is not in a NON-empty map is never
 * silently skipped — the caller records a logged disposition (see
 * ZL_Lead_Generator::filter_by_territory) via do_action( 'zdz_flow_disposition', ... ).
 *
 * @return array<string,string> postal_code => territory_code   (empty = allow-all)
 */
function zl_zip_territories(): array {
	$raw = get_option( 'zl_territories', '' );
	$map = ( is_string( $raw ) && $raw !== '' ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : array() );
	if ( ! is_array( $map ) ) {
		$map = array();
	}
	return (array) apply_filters( 'zl_zip_territories', $map );
}

/**
 * Resolve a postal code to a territory code, or '' when unresolved / allow-all.
 * The theme geocoder is the source of place data; this map is only the code assignment.
 */
function zl_territory_for_postal( $postal ): string {
	$postal = substr( preg_replace( '/[^0-9]/', '', (string) $postal ), 0, 5 );
	if ( $postal === '' ) {
		return '';
	}
	$map  = zl_zip_territories();
	$code = isset( $map[ $postal ] ) ? (string) $map[ $postal ] : '';
	return (string) apply_filters( 'zl_territory_for_postal', $code, $postal, $map );
}

/** Is a service-area coverage map configured?  An empty map == allow-all (no gating). */
function zl_coverage_configured(): bool {
	return ! empty( zl_zip_territories() );
}

/**
 * Record a lead-flow disposition. NOTHING is silent: every drop/skip/hold routes through
 * here so the funnel arithmetic balances. Fires the platform disposition action (a no-op
 * until a Flow ledger consumes it) and always leaves an error_log breadcrumb.
 *
 * @param string $disposition machine slug, e.g. 'territory_unmatched'
 * @param array  $context     structured detail (counts, ids, reason)
 */
function zl_log_disposition( string $disposition, array $context = array() ): void {
	$context = array_merge( array( 'app' => 'leads', 'disposition' => $disposition ), $context );
	do_action( 'zdz_flow_disposition', $disposition, $context );
	error_log( 'Zorderz Leads disposition: ' . $disposition . ' ' . wp_json_encode( $context ) );
}

/**
 * Outbound check-in email voice (Voice binding).
 *
 * Core ships a NEUTRAL, minimal default — plain, provider-agnostic, with no trade nouns and
 * no house-voice styling. The statistically-derived company house voice is private and is
 * NOT shipped; a tenant supplies its own by returning a populated structure from the
 * `zl_email_voice` filter. Tokens: {name} {product} {time} {signName} {phone}.
 *
 * @return array{subjects:array<string>,openers:array<string>,offers:array<string>,fallback_product:string}
 */
function zl_email_voice(): array {
	$default = array(
		'subjects'         => array( 'Checking in, {name}', 'Following up, {name}', 'A quick hello, {name}' ),
		'openers'          => array( 'It’s been {time} since we completed your order.' ),
		'offers'           => array( 'If there’s anything else we can help with, I’d be glad to take a look.' ),
		'fallback_product' => __( 'the items we provided', 'zorderz' ),
	);
	$voice = apply_filters( 'zl_email_voice', $default );
	return is_array( $voice ) ? array_merge( $default, $voice ) : $default;
}

/**
 * Whether the name-based gender demographic classifier may run.
 *
 * DEFAULT OFF (ethics/product review): classifying people by inferred gender from a first
 * name is opt-in only, gated behind this filter AND requiring an explicit non-'both'
 * demographic selection. See flags_for_orchestrator in the port notes.
 */
function zl_gender_demographic_enabled(): bool {
	return (bool) apply_filters( 'zl_gender_demographic_enabled', false );
}

// ── Activation ─────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'zl_activate' );

/**
 * Plugin activation hook.
 * Purpose: Creates necessary custom database tables for tracking batches, leads, and history.
 * Callers: WordPress core on plugin activation.
 * Side effects: Modifies database schema, sets default wp_options.
 */
function zl_activate() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();

    // Batches table: Stores metadata about each generation run (Step 1 of pipeline)
    // Includes v1.2.0 filters (city_zip_filter, demographic_filter, spend min/max)
    $t_batches = $wpdb->prefix . 'zl_batches';
    dbDelta( "CREATE TABLE {$t_batches} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        batch_tag varchar(255) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'generating',
        is_test tinyint(1) NOT NULL DEFAULT 0,
        total_leads int(11) unsigned NOT NULL DEFAULT 0,
        leads_contacted int(11) unsigned NOT NULL DEFAULT 0,
        assigned_to varchar(10) DEFAULT NULL,
        lookback_days int(11) unsigned NOT NULL DEFAULT 730,
        product_filter varchar(500) DEFAULT '',
        city_zip_filter varchar(500) DEFAULT '',
        demographic_filter varchar(20) DEFAULT '',
        spend_min decimal(10,2) DEFAULT NULL,
        spend_max decimal(10,2) DEFAULT NULL,
        ai_summary longtext DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_status (status),
        KEY idx_assigned (assigned_to)
    ) {$charset};" );

    // Leads table: Stores individual leads selected for CRM creation (Step 4 of pipeline)
    // purchase_summary is rewritten by AI in Step 5 to be <101 chars for Nutshell limits.
    $t_leads = $wpdb->prefix . 'zl_leads';
    dbDelta( "CREATE TABLE {$t_leads} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        batch_id bigint(20) unsigned NOT NULL,
        freshbooks_client_id varchar(50) DEFAULT NULL,
        nutshell_contact_id varchar(100) DEFAULT NULL,
        nutshell_lead_id varchar(100) DEFAULT NULL,
        first_name varchar(255) NOT NULL DEFAULT '',
        last_name varchar(255) NOT NULL DEFAULT '',
        email varchar(255) DEFAULT '',
        phone varchar(100) DEFAULT '',
        city varchar(255) DEFAULT '',
        organization varchar(255) DEFAULT '',
        territory varchar(50) DEFAULT '',
        purchase_summary text,
        purchase_history longtext,
        nutshell_interests text,
        nutshell_custom_fields longtext DEFAULT NULL,
        nutshell_status varchar(50) DEFAULT NULL,
        nutshell_synced_at datetime DEFAULT NULL,
        salesperson_notes longtext DEFAULT NULL,
        score decimal(5,2) DEFAULT 0.00,
        status varchar(50) NOT NULL DEFAULT 'pending',
        contact_status varchar(20) NOT NULL DEFAULT 'pending',
        contact_notes text DEFAULT NULL,
        contacted_at datetime DEFAULT NULL,
        assigned_user_id bigint(20) unsigned DEFAULT NULL,
        assigned_at datetime DEFAULT NULL,
        assigned_by bigint(20) unsigned DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_batch_id (batch_id),
        KEY idx_contact_status (contact_status),
        KEY idx_score (score),
        KEY idx_nutshell_status (nutshell_status),
        KEY idx_assigned_user (assigned_user_id, assigned_at)
    ) {$charset};" );

    // Lead history (de-duplication): Tracks when a client was last generated.
    // Enforces the cooldown period (default 90 days) so salespeople aren't spammed with the same leads.
    $t_history = $wpdb->prefix . 'zl_lead_history';
    dbDelta( "CREATE TABLE {$t_history} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        freshbooks_client_id varchar(50) NOT NULL,
        email varchar(255) DEFAULT NULL,
        last_generated_at datetime NOT NULL,
        last_batch_id bigint(20) unsigned NOT NULL,
        times_generated int(11) unsigned NOT NULL DEFAULT 1,
        last_contact_status varchar(20) DEFAULT 'pending',
        PRIMARY KEY  (id),
        UNIQUE KEY idx_fb_client (freshbooks_client_id),
        KEY idx_email (email),
        KEY idx_last_generated (last_generated_at)
    ) {$charset};" );

    // v1.8.2: Failsafe — only stamp version if all 3 tables were created successfully.
    $required_tables = array( $t_batches, $t_leads, $t_history );
    $all_exist = true;
    foreach ( $required_tables as $tbl ) {
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) {
            error_log( 'ZL ACTIVATE: Table ' . $tbl . ' missing after dbDelta — skipping version stamp.' );
            $all_exist = false;
        }
    }
    if ( $all_exist ) {
        update_option( 'zl_db_version', ZL_VERSION );
    }

    // Operational tuning defaults only (batch size / lookback / cooldown). NO business data
    // is seeded: the roster resolves through ZDZ_Party and the territory map ships empty.
    if ( ! get_option( 'zl_leads_per_batch' ) ) {
        update_option( 'zl_leads_per_batch', 50 );
    }
    if ( ! get_option( 'zl_lookback_days' ) ) {
        update_option( 'zl_lookback_days', 730 );
    }
    if ( ! get_option( 'zl_cooldown_days' ) ) {
        update_option( 'zl_cooldown_days', 90 );
    }
}

// ── Deactivation ───────────────────────────────────────────────────
register_deactivation_hook( __FILE__, 'zl_deactivate' );

/**
 * Plugin deactivation hook.
 * Currently does not drop tables to prevent accidental data loss.
 */
function zl_deactivate() {
    // Nothing to clean up for now
}

// ── DB Upgrade ─────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'zl_maybe_upgrade', 5 );

/**
 * Handles database schema sync and version-gated migrations.
 * Runs on every page load (plugins_loaded), checks version, and applies patches.
 *
 * v1.8.2: Replaced zl_activate() call with proper dbDelta schema sync + table failsafe.
 * Old pattern called the full activation hook on every version mismatch, which:
 *   1. Stamps db_version inside zl_activate() before migrations run
 *   2. Re-runs default option initialization unnecessarily
 * New pattern: dbDelta for schema sync, version-gated migrations, table failsafe.
 */
function zl_maybe_upgrade() {
    $db_ver = get_option( 'zl_db_version', '0' );
    if ( version_compare( $db_ver, ZL_VERSION, '>=' ) ) {
        return;
    }

    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();

    // ── Schema sync via dbDelta (idempotent — safe to re-run every upgrade) ──
    // Handles column additions across versions without individual ALTER TABLE statements.

    $t_batches = $wpdb->prefix . 'zl_batches';
    dbDelta( "CREATE TABLE {$t_batches} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        batch_tag varchar(255) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'generating',
        is_test tinyint(1) NOT NULL DEFAULT 0,
        total_leads int(11) unsigned NOT NULL DEFAULT 0,
        leads_contacted int(11) unsigned NOT NULL DEFAULT 0,
        assigned_to varchar(10) DEFAULT NULL,
        lookback_days int(11) unsigned NOT NULL DEFAULT 730,
        product_filter varchar(500) DEFAULT '',
        city_zip_filter varchar(500) DEFAULT '',
        demographic_filter varchar(20) DEFAULT '',
        spend_min decimal(10,2) DEFAULT NULL,
        spend_max decimal(10,2) DEFAULT NULL,
        ai_summary longtext DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_status (status),
        KEY idx_assigned (assigned_to)
    ) {$charset};" );

    $t_leads = $wpdb->prefix . 'zl_leads';
    dbDelta( "CREATE TABLE {$t_leads} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        batch_id bigint(20) unsigned NOT NULL,
        freshbooks_client_id varchar(50) DEFAULT NULL,
        nutshell_contact_id varchar(100) DEFAULT NULL,
        nutshell_lead_id varchar(100) DEFAULT NULL,
        first_name varchar(255) NOT NULL DEFAULT '',
        last_name varchar(255) NOT NULL DEFAULT '',
        email varchar(255) DEFAULT '',
        phone varchar(100) DEFAULT '',
        city varchar(255) DEFAULT '',
        organization varchar(255) DEFAULT '',
        territory varchar(50) DEFAULT '',
        purchase_summary text,
        purchase_history longtext,
        nutshell_interests text,
        nutshell_custom_fields longtext DEFAULT NULL,
        nutshell_status varchar(50) DEFAULT NULL,
        nutshell_synced_at datetime DEFAULT NULL,
        salesperson_notes longtext DEFAULT NULL,
        score decimal(5,2) DEFAULT 0.00,
        status varchar(50) NOT NULL DEFAULT 'pending',
        contact_status varchar(20) NOT NULL DEFAULT 'pending',
        contact_notes text DEFAULT NULL,
        contacted_at datetime DEFAULT NULL,
        assigned_user_id bigint(20) unsigned DEFAULT NULL,
        assigned_at datetime DEFAULT NULL,
        assigned_by bigint(20) unsigned DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_batch_id (batch_id),
        KEY idx_contact_status (contact_status),
        KEY idx_score (score),
        KEY idx_nutshell_status (nutshell_status),
        KEY idx_assigned_user (assigned_user_id, assigned_at)
    ) {$charset};" );

    $t_history = $wpdb->prefix . 'zl_lead_history';
    dbDelta( "CREATE TABLE {$t_history} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        freshbooks_client_id varchar(50) NOT NULL,
        email varchar(255) DEFAULT NULL,
        last_generated_at datetime NOT NULL,
        last_batch_id bigint(20) unsigned NOT NULL,
        times_generated int(11) unsigned NOT NULL DEFAULT 1,
        last_contact_status varchar(20) DEFAULT 'pending',
        PRIMARY KEY  (id),
        UNIQUE KEY idx_fb_client (freshbooks_client_id),
        KEY idx_email (email),
        KEY idx_last_generated (last_generated_at)
    ) {$charset};" );

    // ── Version-gated migrations ─────────────────────────────────────

    // v1.6.0 migration — Theme v2.3.0 alignment: zdz_owner + zdz_sales roles
    if ( version_compare( $db_ver, '1.6.0', '<' ) ) {
        // Update the permissions config to include zdz_owner and zdz_sales roles
        $perm_config = get_option( 'zl_permissions' );
        if ( is_array( $perm_config ) && isset( $perm_config['roles'] ) ) {
            $changed = false;
            // Add zdz_owner with full access if not present
            if ( ! isset( $perm_config['roles']['zdz_owner'] ) ) {
                $perm_config['roles']['zdz_owner'] = array( 'all' );
                $changed = true;
            }
            // Add zdz_sales with same permissions as zdz_operator if not present
            if ( ! isset( $perm_config['roles']['zdz_sales'] ) ) {
                $perm_config['roles']['zdz_sales'] = isset( $perm_config['roles']['zdz_operator'] )
                    ? $perm_config['roles']['zdz_operator']
                    : array(
                        'can_generate_test',
                        'can_edit_filters',
                        'can_sync_nutshell',
                        'can_mark_contacted',
                        'view_batch_history',
                        'view_lead_details',
                        'view_contact_info',
                        'view_pricing',
                    );
                $changed = true;
            }
            if ( $changed ) {
                update_option( 'zl_permissions', $perm_config, false );
            }
        }
        // Grant zdz_access_app to zdz_sales role (same as zdz_operator)
        $sales_role = get_role( 'zdz_sales' );
        if ( $sales_role && ! $sales_role->has_cap( 'zdz_access_app' ) ) {
            $sales_role->add_cap( 'zdz_access_app' );
        }
        error_log( 'ZL v1.6.0 migration: Added zdz_owner and zdz_sales roles, theme v2.3.0 alignment.' );
    }

    // v1.7.0 migration — Sales view: strip generation permissions from zdz_sales role
    if ( version_compare( $db_ver, '1.7.0', '<' ) ) {
        $perm_config = get_option( 'zl_permissions' );
        if ( is_array( $perm_config ) && isset( $perm_config['roles']['zdz_sales'] ) ) {
            $sales_features = $perm_config['roles']['zdz_sales'];
            $remove = array( 'can_generate_test', 'can_generate_full', 'can_edit_filters' );
            $perm_config['roles']['zdz_sales'] = array_values(
                array_diff( $sales_features, $remove )
            );
            update_option( 'zl_permissions', $perm_config, false );
        }
        error_log( 'ZL v1.7.0 migration: Stripped generation permissions from zdz_sales role for read-only sales view.' );
    }

    // v2.0.0 migration — Lead interaction model, forward-to-team, new permission features
    if ( version_compare( $db_ver, '2.0.0', '<' ) ) {
        require_once ZL_PLUGIN_DIR . 'db/migrate-2.0.0.php';
        zl_migrate_200();

        // Update zdz_sales permissions to include new interaction features
        $perm_config = get_option( 'zl_permissions' );
        if ( is_array( $perm_config ) && isset( $perm_config['roles'] ) ) {
            $perm_config['roles']['zdz_sales'] = array(
                'can_generate_test',
                'can_generate_full',
                'can_sync_nutshell',
                'can_mark_contacted',
                'can_update_status',
                'can_forward_note',
                'can_view_notes',
                'view_batch_history',
                'view_lead_details',
                'view_contact_info',
                'view_pricing',
            );
            // Update zdz_operator with new features too
            if ( isset( $perm_config['roles']['zdz_operator'] ) ) {
                $perm_config['roles']['zdz_operator'] = array(
                    'can_generate_test',
                    'can_edit_filters',
                    'can_choose_salesperson',
                    'can_sync_nutshell',
                    'can_mark_contacted',
                    'can_update_status',
                    'can_forward_note',
                    'can_view_notes',
                    'view_batch_history',
                    'view_lead_details',
                    'view_contact_info',
                    'view_pricing',
                );
            }
            update_option( 'zl_permissions', $perm_config, false );
        }

        // Grant zdz_access_app to zdz_sales role (in case it was removed)
        $sales_role = get_role( 'zdz_sales' );
        if ( $sales_role && ! $sales_role->has_cap( 'zdz_access_app' ) ) {
            $sales_role->add_cap( 'zdz_access_app' );
        }

        error_log( 'ZL v2.0.0 migration: Lead interaction model enabled, forward-to-team table created, permissions updated.' );
    }

    // v1.5.0 migration — Full-parity widget + permission system
    if ( version_compare( $db_ver, '1.5.0', '<' ) ) {
        // Initialize default permission configuration if not set
        if ( ! get_option( 'zl_permissions' ) ) {
            update_option( 'zl_permissions', array(
                'roles' => array(
                    'zdz_owner'    => array( 'all' ),
                    'zdz_admin'    => array( 'all' ),
                    'zdz_sales'    => array(
                        'can_generate_test',
                        'can_edit_filters',
                        'can_sync_nutshell',
                        'can_mark_contacted',
                        'view_batch_history',
                        'view_lead_details',
                        'view_contact_info',
                        'view_pricing',
                    ),
                    'zdz_operator' => array(
                        'can_generate_test',
                        'can_edit_filters',
                        'can_sync_nutshell',
                        'can_mark_contacted',
                        'view_batch_history',
                        'view_lead_details',
                        'view_contact_info',
                        'view_pricing',
                    ),
                ),
                'users' => array(),
            ), false );
        }
        error_log( 'ZL v1.5.0 migration: Permission system initialized, full-parity widget enabled.' );
    }

    // v1.4.0 migration — the app compatibility (Administrator + Operator roles)
    if ( version_compare( $db_ver, '1.4.0', '<' ) ) {
        // Grant zdz_operate_app capability to operator role so they can use the AJAX endpoints.
        // zdz_admin already has manage_options, so no changes needed for admins.
        $operator_role = get_role( 'zdz_operator' );
        if ( $operator_role && ! $operator_role->has_cap( 'zdz_access_app' ) ) {
            $operator_role->add_cap( 'zdz_access_app' );
            error_log( 'ZL v1.4.0 migration: Granted zdz_access_app capability to zdz_operator role.' );
        }
        error_log( 'ZL v1.4.0 migration: the app role compatibility applied (zdz_admin + zdz_operator).' );
    }

    // v1.2.0 migration — extend lookback option and add new filter columns
    if ( version_compare( $db_ver, '1.2.0', '<' ) ) {
        // Extend lookback to support up to "Since 2000"
        $current_lookback = (int) get_option( 'zl_lookback_days', 730 );
        // No forced change — just ensure new options are available via UI
        error_log( 'ZL v1.2.0 migration: Schema updated with new filter columns, extended lookback support.' );
    }

    // NOTE: the legacy v1.1.2 migration is intentionally omitted. It hardcoded a specific
    // territory-code alias and force-overwrote the admin's stored AI model. Both are
    // anti-patterns here: a territory alias is now DECLARED tenant data (the territory map /
    // zl_zip_territories filter), and the AI model is a tenant choice the platform never
    // rewrites (a retired model is forward-resolved at read time via a settings alias table,
    // never mutated in place).

    // ── Failsafe: Verify all 3 required tables exist before stamping version ──
    $required_tables = array( $t_batches, $t_leads, $t_history );
    foreach ( $required_tables as $tbl ) {
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) {
            error_log( 'ZL FAILSAFE: Table ' . $tbl . ' missing after dbDelta — skipping version stamp.' );
            return;
        }
    }

    // v2.4.0 migration — Per-user lead assignment (Phase 1).
    // The new assigned_user_id/assigned_at/assigned_by columns were added by the
    // dbDelta above. Here we BACKFILL ownership for existing rows by resolving
    // each lead's batch salesperson CODE (zl_batches.assigned_to, e.g. "NW")
    // to a TS WP user via the explicit map in ZL_Lead_Assignment. This is a
    // one-time seed only: it runs solely for rows still NULL, never overwrites an
    // explicit assignment, and leaves rows NULL when the code can't be resolved
    // (NULL = unassigned, visible to admins only). Fuzzy resolution is acceptable
    // for a backfill seed; it is NOT used for authoritative assignment going
    // forward (admins assign explicitly).
    if ( version_compare( $db_ver, '2.4.0', '<' ) ) {
        // Only attempt if the helper is loaded (it lives in includes/, loaded on
        // plugins_loaded; on the upgrade tick it may not be yet, so guard).
        if ( class_exists( 'ZL_Lead_Assignment' ) && method_exists( 'ZL_Lead_Assignment', 'backfill_from_batches' ) ) {
            $seeded = ZL_Lead_Assignment::backfill_from_batches();
            error_log( 'ZL v2.4.0 migration: per-lead assignment backfill seeded ' . (int) $seeded . ' lead(s) from batch codes.' );
        } else {
            // Defer: stamp a marker so a later request (with the class loaded) can
            // run it. We DON'T block the version stamp on the backfill — the
            // columns exist and new assignments work regardless; the seed is a
            // convenience for pre-existing rows.
            update_option( 'zl_assignment_backfill_pending', 1, false );
            error_log( 'ZL v2.4.0 migration: assignment columns ready; backfill deferred (helper not yet loaded).' );
        }
    }

    update_option( 'zl_db_version', ZL_VERSION );
}

// ── Load all class files ───────────────────────────────────────────
add_action( 'plugins_loaded', 'zl_load_includes' );

/**
 * Autoloader for plugin classes.
 * Loads all PHP files in the /includes/ directory.
 * Order of loading does not matter as classes are instantiated via hooks.
 */
function zl_load_includes() {
    $dir = ZL_PLUGIN_DIR . 'includes/';
    foreach ( glob( $dir . '*.php' ) as $file ) {
        require_once $file;
    }

    // v2.0.0: Initialize lead interaction class (registers AJAX + cron)
    if ( class_exists( 'ZL_Lead_Interaction' ) ) {
        ZL_Lead_Interaction::get_instance();
    }

    // v2.5.0: Dashboard tile — register the salesperson's "leads to contact"
    // item into the theme's unified action-items queue (and rep-mode helpers).
    if ( class_exists( 'ZL_Dashboard_Tile' ) ) {
        ZL_Dashboard_Tile::init();
    }
}

/**
 * ────────────────────────────────────────────────────────
 * ZORDERZ THEME INTEGRATION
 * ────────────────────────────────────────────────────────
 *
 * IMPORTANT: This MUST run inside `after_setup_theme` because WordPress
 * loads plugins BEFORE themes. The Zorderz theme's interfaces
 * (\Zorderz\Widget_App_Interface, \Zorderz\App_Interface) are
 * defined in the theme's functions.php, which hasn't loaded yet when
 * this plugin file first executes. Deferring to `after_setup_theme`
 * guarantees the interfaces exist before we check for them.
 */
add_action( 'after_setup_theme', function() {

    // ── TIER 2: Full inline widget (theme v2.0+) ──
    if ( interface_exists( '\Zorderz\Widget_App_Interface' ) ) {

        class ZL_App implements \Zorderz\Widget_App_Interface {

            public function get_config(): array {
                return [
                    'id'          => 'leads',
                    'nm'          => 'Leads',
                    'icon'        => 'target',
                    'cat'         => 'Sales',
                    'cc'          => '#10B981',
                    'desc'        => 'Weekly sales lead generation.',
                    'roles'       => (array) apply_filters( 'zl_app_roles', [ 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales' ] ),
                    'bridge_type' => 'inline_widget',
                    'admin_url'   => admin_url( 'admin.php?page=zl-dashboard' ),
                ];
            }

            public function render_mobile_view( int $user_id ): void {
                echo '<iframe src="' . esc_url(
                    admin_url( 'admin.php?page=zl-dashboard&zdz_mobile=1' )
                ) . '" style="width:100%;height:100%;border:none;"></iframe>';
            }

            public function render_dashboard_widget( int $user_id ): ?string {
                // Enqueue widget assets
                wp_enqueue_style( 'zl-widget-css',
                    ZL_PLUGIN_URL . 'assets/css/widget.css', [], ZL_VERSION );
                wp_enqueue_script( 'zl-widget-js',
                    ZL_PLUGIN_URL . 'assets/js/widget.js', [], ZL_VERSION, true );

                // Build permission-aware config for JavaScript
                $user_features = ZL_Permissions::get_user_features( $user_id );

                // Salespeople list for the dropdown — resolved through ZDZ_Party (never a
                // local roster constant). Ships empty until the team is set up.
                $salespeople = zl_salespeople();

                // Lookback options
                $lookback_options = array(
                    array( 'value' => 180,  'label' => '6 Months' ),
                    array( 'value' => 365,  'label' => '1 Year' ),
                    array( 'value' => 730,  'label' => '2 Years' ),
                    array( 'value' => 1095, 'label' => '3 Years' ),
                    array( 'value' => 1825, 'label' => '5 Years' ),
                    array( 'value' => 3650, 'label' => '10 Years' ),
                    array( 'value' => 5475, 'label' => '15 Years' ),
                    array( 'value' => 9500, 'label' => 'Since 2000 (~26 years)' ),
                );

                // v1.7.0 — Determine user role for conditional UI (sales view vs admin view)
                $current_user  = wp_get_current_user();
                $is_admin_user = current_user_can( 'manage_options' )
                    || in_array( 'zdz_admin', (array) $current_user->roles, true )
                    || in_array( 'zdz_owner', (array) $current_user->roles, true );
                $is_sales_user = in_array( 'zdz_sales', (array) $current_user->roles, true )
                    && ! $is_admin_user; // Admin overrides sales

                // v1.7.0 — Resolve primary role for JS-side view switching
                $user_role = 'operator'; // default
                if ( $is_admin_user ) {
                    $user_role = 'admin';
                } elseif ( $is_sales_user ) {
                    $user_role = 'sales';
                }

                // v2.0.0: Resolve current user's salesperson code for auto-assignment
                $resolved_sp_code = '';
                $territory_zips   = array();
                if ( class_exists( 'ZL_Lead_Interaction' ) ) {
                    $resolved_sp_code = ZL_Lead_Interaction::resolve_salesperson_code( $user_id );
                    if ( ! empty( $resolved_sp_code ) ) {
                        $territory_zips = ZL_Lead_Interaction::get_territory_zips( $resolved_sp_code );
                    }
                }

                // v2.1.0: Personalized email sign-off + phone for the email builder.
                // Identity comes ONLY from the theme's ZDZ_Core_Settings / Business Profile
                // (which cascade personal → company); nothing is hardcoded. Any value the
                // profile doesn't supply stays EMPTY, and the email builder omits that line
                // rather than inventing a name or a company phone number.
                $email_sign_name = '';
                $user_phone      = '';
                $company_phone   = '';
                if ( class_exists( 'ZDZ_Core_Settings' ) ) {
                    if ( method_exists( 'ZDZ_Core_Settings', 'get_user_email_name' ) ) {
                        $email_sign_name = (string) ZDZ_Core_Settings::get_user_email_name( $user_id );
                    }
                    if ( method_exists( 'ZDZ_Core_Settings', 'get_company_phone' ) ) {
                        $company_phone = (string) ZDZ_Core_Settings::get_company_phone();
                    }
                    if ( method_exists( 'ZDZ_Core_Settings', 'get_user_phone' ) ) {
                        // Personal phone → company phone (theme cascade).
                        $user_phone = (string) ZDZ_Core_Settings::get_user_phone( $user_id );
                    }
                }
                // Fallback for the sign-off name only: the signed-in user's display name.
                if ( empty( $email_sign_name ) ) {
                    $u = get_userdata( $user_id );
                    $email_sign_name = $u ? $u->display_name : '';
                }
                // Phone falls back personal → company; if the profile has neither, it stays
                // empty and the signature simply omits the phone line.
                if ( empty( $user_phone ) ) {
                    $user_phone = $company_phone;
                }

                // Outbound email voice (Voice binding) + product humanization map (Item
                // Engine binding). Core ships a NEUTRAL default voice and an EMPTY product
                // map (the statistically-derived house voice is private). A tenant overrides
                // via the zl_email_voice / zl_product_humanize_map filters.
                $email_voice   = zl_email_voice();
                $product_human = (array) apply_filters( 'zl_product_humanize_map', array() );
                // Name-based gender demographic classifier is OFF by default (ethics review).
                $gender_demographic = zl_gender_demographic_enabled();

                // v2.5.0 — Rep mode + assigned-lead counts (Phase 2).
                // Rep mode = a salesperson who sees ONLY their assigned leads and
                // NOT the generate controls. Determined server-side (authoritative).
                // Counts come from the Phase-1 assignment data, rendered into the
                // banner HTML below so it appears INSTANTLY with no layout shift
                // (no fetch-then-pop). The numbers also ride in zlWidgetData so the
                // JS can refresh them after the rep acts on a lead.
                $is_rep_mode  = class_exists( 'ZL_Dashboard_Tile' )
                    ? ZL_Dashboard_Tile::is_rep_mode( $user_id )
                    : ( $is_sales_user );
                $lead_counts  = class_exists( 'ZL_Dashboard_Tile' )
                    ? ZL_Dashboard_Tile::get_counts( $user_id )
                    : array( 'new_today' => 0, 'open_pending' => 0, 'total' => 0 );

                wp_localize_script( 'zl-widget-js', 'zlWidgetData', [
                    'ajaxurl'        => admin_url( 'admin-ajax.php' ),
                    'nonce'          => wp_create_nonce( 'zl_nonce' ),
                    'version'        => ZL_VERSION,
                    'features'       => $user_features,
                    'salespeople'    => $salespeople,
                    'lookback'       => $lookback_options,
                    'adminUrl'       => admin_url( 'admin.php?page=zl-dashboard' ),
                    'userRole'       => $user_role,
                    'isSales'        => $is_sales_user,
                    'isAdmin'        => $is_admin_user,
                    'spCode'         => $resolved_sp_code,
                    'territoryZips'  => $territory_zips,
                    // v2.1.0 email personalization
                    'userEmailName'  => $email_sign_name,
                    'userPhone'      => $user_phone,
                    'companyPhone'   => $company_phone,
                    // Voice profile (neutral default) + product humanization map (empty) +
                    // gender-demographic flag (off) — all Core-neutral, tenant-overridable.
                    'emailVoice'     => $email_voice,
                    'productHumanize' => $product_human,
                    'genderDemographic' => (bool) $gender_demographic,
                    // v2.5.0 rep-mode + assigned-lead counts
                    'isRepMode'      => (bool) $is_rep_mode,
                    'myLeadsOnly'    => (bool) $is_rep_mode, // reps see only their leads
                    'myLeadCounts'   => $lead_counts,
                ] );

                ob_start();
                ?>
                <div class="zl-w" id="zl-widget-wrap" data-role="<?php echo esc_attr( $user_role ); ?>"<?php echo $is_rep_mode ? ' data-rep-mode="1"' : ''; ?>>

                    <?php
                    // v2.5.0 — "New leads today" banner (rep mode only). Rendered
                    // server-side with the real count so it's instant + shift-free.
                    // Clickable → switches the widget to "my leads" and loads them.
                    if ( $is_rep_mode ) :
                        $new_today = (int) $lead_counts['new_today'];
                        $open_pend = (int) $lead_counts['open_pending'];
                        // Show the banner whenever there's actionable work; keep a
                        // calm "all caught up" state otherwise (no false urgency).
                        $banner_has_work = ( $open_pend > 0 );
                        ?>
                        <button type="button"
                                class="zl-w-leadbanner<?php echo $banner_has_work ? ' zl-w-leadbanner--active' : ' zl-w-leadbanner--clear'; ?>"
                                id="zl-w-leadbanner"
                                aria-label="<?php echo esc_attr(
                                    $banner_has_work
                                        ? sprintf( 'You have %d leads to contact. Open your leads.', $open_pend )
                                        : 'You are all caught up on leads.'
                                ); ?>">
                            <span class="zl-w-leadbanner-icon" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>
                            </span>
                            <span class="zl-w-leadbanner-text">
                                <?php if ( $banner_has_work ) : ?>
                                    <span class="zl-w-leadbanner-count" id="zl-w-leadbanner-count"><?php echo esc_html( $open_pend ); ?></span>
                                    <span class="zl-w-leadbanner-label">
                                        <?php
                                        echo esc_html(
                                            $new_today > 0
                                                ? sprintf(
                                                    /* translators: %d new today */
                                                    _n( 'lead to contact · %d new today', 'leads to contact · %d new today', $open_pend, 'zorderz' ),
                                                    $new_today
                                                )
                                                : _n( 'lead to contact', 'leads to contact', $open_pend, 'zorderz' )
                                        );
                                        ?>
                                    </span>
                                <?php else : ?>
                                    <span class="zl-w-leadbanner-label zl-w-leadbanner-clear-label">You’re all caught up — no leads waiting</span>
                                <?php endif; ?>
                            </span>
                            <?php if ( $banner_has_work ) : ?>
                                <span class="zl-w-leadbanner-cta" aria-hidden="true">View →</span>
                            <?php endif; ?>
                        </button>
                    <?php endif; ?>

                    <!-- Stats Bar (always visible, compact) -->
                    <!-- v2.1.0: action-oriented metrics. "Ready to Email" is the
                         primary call-to-action; tapping it filters the list below
                         to just those leads (wired in widget.js). -->
                    <div class="zl-w-stats">
                        <div class="zl-w-stat zl-w-stat-primary zl-w-stat-clickable" id="zl-stat-tile-ready" role="button" tabindex="0" aria-label="Show leads that are ready to email">
                            <span class="zl-w-stat-icon"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></span>
                            <span class="zl-w-stat-val" id="zl-stat-ready">--</span>
                            <span class="zl-w-stat-label">Ready to Email</span>
                        </div>
                        <div class="zl-w-stat">
                            <span class="zl-w-stat-icon"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></span>
                            <span class="zl-w-stat-val" id="zl-stat-untouched">--</span>
                            <span class="zl-w-stat-label">Not Yet Contacted</span>
                        </div>
                        <div class="zl-w-stat">
                            <span class="zl-w-stat-icon"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4"/><path d="m16.2 7.8 2.9-2.9"/><path d="M18 12h4"/><path d="m16.2 16.2 2.9 2.9"/><path d="M12 18v4"/><path d="m4.9 19.1 2.9-2.9"/><path d="M2 12h4"/><path d="m4.9 4.9 2.9 2.9"/></svg></span>
                            <span class="zl-w-stat-val" id="zl-stat-newweek">--</span>
                            <span class="zl-w-stat-label">New This Week</span>
                        </div>
                        <div class="zl-w-stat">
                            <span class="zl-w-stat-icon"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></span>
                            <span class="zl-w-stat-val" id="zl-stat-contactedweek">--</span>
                            <span class="zl-w-stat-label">Contacted This Week</span>
                        </div>
                    </div>

                    <!-- ═══ PANEL 1: GENERATE ═══ -->
                    <div class="zl-w-panel <?php echo $is_sales_user ? 'collapsed' : 'expanded'; ?>" id="zl-panel-generate">
                        <div class="zl-w-panel-header" id="zl-panel-generate-header">
                            <span class="zl-w-panel-title">⚡ Generate Leads</span>
                            <span class="zl-w-panel-toggle" id="zl-panel-generate-toggle">▼</span>
                        </div>
                        <div class="zl-w-panel-body" id="zl-panel-generate-body">
                            <!-- Generation Summary (shown DURING generation, replaces form) -->
                            <div class="zl-w-gen-summary" id="zl-gen-summary" style="display:none;"></div>

                            <!-- Filters (permission-gated via PHP + JS) -->
                            <div class="zl-w-filters" id="zl-filter-panel">
                                <?php if ( ! $is_sales_user ) : ?>
                                <div class="zl-w-filter-row" id="zl-row-salesperson">
                                    <label for="zl-filter-salesperson">Salesperson</label>
                                    <select id="zl-filter-salesperson"></select>
                                    <!-- v2.6.0: the roster is Nutshell-linked reps only, by design -->
                                    <p class="zl-w-field-hint">Only reps with linked Nutshell accounts are listed.</p>
                                    <p class="zl-w-field-hint zl-w-field-hint-warn" id="zl-sp-unlinked-hint" hidden>Your Nutshell account isn’t linked — ask an admin to add your code on the Leads settings page.</p>
                                </div>
                                <?php endif; ?>

                                <div class="zl-w-filter-row">
                                    <label for="zl-filter-lookback">Lookback</label>
                                    <select id="zl-filter-lookback"></select>
                                </div>
                                <div class="zl-w-filter-row">
                                    <label for="zl-filter-product">Product Filter</label>
                                    <input type="text" id="zl-filter-product" placeholder="e.g. a product or service keyword" />
                                </div>
                                <div class="zl-w-filter-row">
                                    <label for="zl-filter-city-zip">City / Zip</label>
                                    <input type="text" id="zl-filter-city-zip" placeholder="e.g. city or ZIP" />
                                </div>

                                <?php if ( ! $is_sales_user ) : ?>
                                <div class="zl-w-filter-row zl-w-filter-row-pair" id="zl-row-spend">
                                    <label>Spend Range</label>
                                    <div class="zl-w-spend-inputs">
                                        <span>$</span><input type="number" id="zl-filter-spend-min" min="0" step="50" placeholder="Min" />
                                        <span>to $</span><input type="number" id="zl-filter-spend-max" min="0" step="50" placeholder="Max" />
                                    </div>
                                </div>
                                <?php if ( $gender_demographic ) : /* OFF by default — ethics/product review (zl_gender_demographic_enabled) */ ?>
                                <div class="zl-w-filter-row" id="zl-row-demographic">
                                    <label for="zl-filter-demographic">Demographic</label>
                                    <select id="zl-filter-demographic">
                                        <option value="both" selected>Both</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <!-- Action Buttons -->
                            <div class="zl-w-actions" id="zl-w-actions">
                                <button id="zl-btn-test" class="zl-w-btn zl-w-btn-secondary" type="button">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                                    3 Test Leads
                                </button>
                                <button id="zl-btn-full" class="zl-w-btn zl-w-btn-primary" type="button">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m16 12-4-4-4 4"/><path d="M12 16V8"/></svg>
                                    Full Batch
                                </button>
                                <button id="zl-btn-sync" class="zl-w-btn zl-w-btn-outline" type="button">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                                    Sync CRM
                                </button>
                                <button id="zl-btn-refresh" class="zl-w-btn zl-w-btn-outline" type="button">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10"/><path d="M20.49 15a9 9 0 0 1-14.85 3.36L1 14"/></svg>
                                    Refresh
                                </button>
                            </div>

                            <!-- Progress Panel (hidden) -->
                            <div id="zl-progress-panel" class="zl-w-progress" style="display:none;">
                                <div class="zl-w-progress-header">
                                    <span id="zl-progress-label">Generating...</span>
                                    <span id="zl-progress-pct">0%</span>
                                </div>
                                <div class="zl-w-progress-bar">
                                    <div class="zl-w-progress-fill" id="zl-progress-bar"></div>
                                </div>
                                <div class="zl-w-progress-log" id="zl-log"></div>
                                <button id="zl-btn-clear-log" class="zl-w-btn zl-w-btn-outline zl-w-btn-sm" type="button" style="margin-top:6px;">Clear Log</button>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ PANEL 2: MY LEADS (primary surface) ═══ -->
                    <div class="zl-w-panel <?php echo $is_sales_user ? 'expanded' : 'expanded'; ?>" id="zl-panel-leads">
                        <div class="zl-w-panel-header" id="zl-panel-leads-header">
                            <span class="zl-w-panel-title" id="zl-batch-section-title"><?php echo $is_sales_user ? 'My Leads' : 'All Leads'; ?></span>
                            <span class="zl-w-panel-toggle" id="zl-panel-leads-toggle">▼</span>
                        </div>
                        <div class="zl-w-panel-body" id="zl-panel-leads-body">
                            <div id="zl-batch-list" class="zl-w-batches-list">
                                <div class="zl-w-loading">Loading batches...</div>
                            </div>
                        </div>
                    </div>

                    <!-- Full Dashboard Link (admin only) -->
                    <?php if ( $is_admin_user ) : ?>
                    <a id="zl-admin-link" href="<?php echo esc_url( admin_url( 'admin.php?page=zl-dashboard' ) ); ?>"
                       class="zl-w-link" target="_blank">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                        Open Full Dashboard
                    </a>
                    <?php endif; ?>
                </div>
                <?php
                return ob_get_clean();
            }
        }

        add_filter( 'zdz_register_apps', function( $apps ) {
            $apps['leads'] = new ZL_App();
            return $apps;
        } );

    // ── TIER 1 FALLBACK: Standard iframe tile (theme v1.x) ──
    } elseif ( interface_exists( '\Zorderz\App_Interface' ) ) {

        class ZL_App implements \Zorderz\App_Interface {

            public function get_config(): array {
                return [
                    'id'          => 'leads',
                    'nm'          => 'Leads',
                    'icon'        => 'target',
                    'cat'         => 'Sales',
                    'cc'          => '#10B981',
                    'desc'        => 'Weekly sales lead generation.',
                    'roles'       => (array) apply_filters( 'zl_app_roles', [ 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales' ] ),
                    'bridge_type' => 'iframe',
                    'admin_url'   => admin_url( 'admin.php?page=zl-dashboard' ),
                ];
            }

            public function render_mobile_view( int $user_id ): void {
                echo '<iframe src="' . esc_url(
                    admin_url( 'admin.php?page=zl-dashboard&zdz_mobile=1' )
                ) . '" style="width:100%;height:100%;border:none;"></iframe>';
            }
        }

        add_filter( 'zdz_register_apps', function( $apps ) {
            $apps['leads'] = new ZL_App();
            return $apps;
        } );
    }

} );

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * v2.3.0 — Capability registry registration (registry-ready; additive)
 * ─────────────────────────────────────────────────────────────────────────────
 * Per ORCHESTRATOR-INTEROP-CONTRACT-v1.md §2.3 / §6 and the staging plan in
 * INTEROP-LAYER-ARCHITECTURE-v1.md §7 (Stage 1), each app declares its verbs via
 * the `zdz_register_capabilities` filter. The Stage-1 resolver
 * (`ZDZ_Capabilities::invoke`) will then enforce tier/kiosk/side_effect centrally
 * and call these same bridge methods unchanged.
 *
 * This registration is intentionally SAFE TO SHIP NOW: until the resolver exists,
 * the filter simply adds rows nobody reads. When the resolver lands, ZL is
 * already L4-native with zero further code. We declare the security posture
 * honestly here (kiosk + side_effect) so the central gate is correct from day one.
 *
 * NOTE: the orchestrator HOST side — the `[ZL_LOOKUP]` marker handler in TSA's
 * engine and the bot's two-signal intent rule — is owned by the TSA maintainer
 * (CONTRACT §2.2). It is documented as a coordination hand-off in
 * INTEROP-ZL-sales-leads-v1.md; this plugin ships everything that belongs to ZL.
 */
add_filter( 'zdz_register_capabilities', function( $caps ) {
    if ( ! class_exists( 'ZL_TSA_Bridge' ) ) {
        return $caps;
    }

    // READ: a specific person's leads / pipeline status. Bounded on kiosk
    // (name + city + stage + work-type; no contact/money) → kiosk_bounded.
    $caps['lead.lookup'] = array(
        'provider'      => 'leads',
        'tier'          => 'sales',                                 // minimum tier
        'callback'      => array( 'ZL_TSA_Bridge', 'lookup_for_tsa' ),
        'kiosk'         => false,                                   // forbidden on kiosk…
        'kiosk_bounded' => true,                                    // …except the redacted variant
        'side_effect'   => false,                                   // read-only → composes freely
    );

    // READ: leads by filter (territory/zip, status, age, salesperson).
    $caps['lead.find'] = array(
        'provider'      => 'leads',
        'tier'          => 'sales',
        'callback'      => array( 'ZL_TSA_Bridge', 'find_leads_for_tsa' ),
        'kiosk'         => false,
        'kiosk_bounded' => true,
        'side_effect'   => false,
    );

    // SIDE-EFFECT (optional, currently a guarded stub): create a lead from chat.
    // Declared honestly as side-effecting + kiosk-forbidden so the orchestrator
    // requires preview-and-confirm and the central gate blocks it on kiosk.
    $caps['lead.create'] = array(
        'provider'      => 'leads',
        'tier'          => 'sales',
        'callback'      => array( 'ZL_TSA_Bridge', 'create_lead_from_tsa' ),
        'kiosk'         => false,
        'kiosk_bounded' => false,
        'side_effect'   => true,                                    // → preview-and-confirm
    );

    return $caps;
} );

/**
 * Declare this module's legacy → current rename map to the platform migration. Plugins
 * DECLARE; the theme's ZDZ_Rename_Migration performs the table/option/meta/cron renames in
 * ONE place (real dbDelta/RENAME with data preserved), guarded by its own version option.
 * A fresh Zorderz install has no legacy rows, so every entry no-ops. Data is never seeded —
 * only renamed if the old name is present. This is how the business's own `wp_tsl_*` install
 * upgrades cleanly to the `wp_zl_*` tables.
 */
add_filter( 'zdz_rename_map', function ( $map ) {
    $map['tables'] = array_merge( $map['tables'] ?? array(), array(
        'tsl_batches'       => 'zl_batches',
        'tsl_leads'         => 'zl_leads',
        'tsl_lead_history'  => 'zl_lead_history',
        'tsl_lead_forwards' => 'zl_lead_forwards',
    ) );
    $map['options'] = array_merge( $map['options'] ?? array(), array(
        'tsl_db_version'                 => 'zl_db_version',
        'tsl_salespeople'                => 'zl_salespeople',
        'tsl_territories'                => 'zl_territories',
        'tsl_leads_per_batch'            => 'zl_leads_per_batch',
        'tsl_lookback_days'              => 'zl_lookback_days',
        'tsl_cooldown_days'              => 'zl_cooldown_days',
        'tsl_excluded_companies'         => 'zl_excluded_companies',
        'tsl_permissions'                => 'zl_permissions',
        'tsl_ai_model'                   => 'zl_ai_model',
        'tsl_fb_client_id'               => 'zl_fb_client_id',
        'tsl_fb_client_secret'           => 'zl_fb_client_secret',
        'tsl_fb_account_id'              => 'zl_fb_account_id',
        'tsl_assignment_backfill_pending' => 'zl_assignment_backfill_pending',
    ) );
    $map['user_meta'] = array_merge( $map['user_meta'] ?? array(), array(
        'tsl_salesperson_code' => 'zl_salesperson_code',
    ) );
    $map['cron'] = array_merge( $map['cron'] ?? array(), array(
        'tsl_cleanup_stale_batches' => 'zl_cleanup_stale_batches',
    ) );
    $map['app_ids'] = array_merge( $map['app_ids'] ?? array(), array(
        'ts-sales-leads' => 'leads',
        'leads' => 'leads',
    ) );
    return $map;
} );