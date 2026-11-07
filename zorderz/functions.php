<?php

/**
 * THE ONE version floor. Keep in lock-step with style.css.
 *
 * Zorderz versions start at 1.0.0. The update check compares this string for
 * INEQUALITY, not ordering, so a lower number than an install last saw is
 * mechanically safe: it fires the reload prompt once.
 *
 * Why one constant: wp_get_theme()->get('Version') can return '' when a host
 * runs early callbacks before the theme header is read, so several places need
 * an explicit fallback (the asset version, the boot version the page reports,
 * and the footer update ping). When those were separate hand-kept copies, one
 * release bumped two of them and missed the third; the page then reported one
 * version against the ping's other and showed a permanent "App update ready"
 * toast. One constant now feeds all of them.
 */
if ( ! defined( 'ZDZ_THEME_VER_FLOOR' ) ) {
	define( 'ZDZ_THEME_VER_FLOOR', '2.0.1' );
}
/**
 * The REST namespace, in exactly one place.
 *
 * WHY A CONSTANT AND NOT A LITERAL
 * Every register_rest_route() call and every rest_url() call that hands the base
 * URL to the browser must agree. If one rest_url() is missed when the namespace
 * changes, nothing errors server-side: the front end simply gets a 404 on every
 * call, which looks like "the photo upload is broken" or "KPI fetch failed"
 * rather than like a namespace mismatch. A literal that has to agree with itself
 * in two dozen files will eventually disagree; a constant cannot.
 */
if ( ! defined( 'ZDZ_REST_NS' ) ) {
	define( 'ZDZ_REST_NS', 'zorderz/v1' );
}

/**
 * Zorderz Theme Functions
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Silence the WooCommerce "translation triggered too early" notice.
 *
 * WordPress 6.7 added a _doing_it_wrong notice when any textdomain is loaded
 * before the `init` hook. WooCommerce core (and several WC-aware plugins) register
 * `woocommerce`-domain strings at plugin-load time, so this notice fires on EVERY
 * request — it flooded debug.log with ~3,700 identical entries in a single day.
 * It is benign (a WooCommerce-ecosystem timing quirk, not our code or a real bug)
 * and there is no clean app-side hook to defer WooCommerce's own early load.
 *
 * This filter suppresses ONLY that one notice and ONLY for the `woocommerce`
 * textdomain — every other _doing_it_wrong notice (including for our own domains)
 * still logs normally, so we don't lose real signal. Scoped as narrowly as possible.
 */
add_filter(
	'doing_it_wrong_trigger_error',
	function ( $trigger, $function_name, $message ) {
		if ( '_load_textdomain_just_in_time' === $function_name
			&& is_string( $message )
			&& false !== strpos( $message, 'woocommerce' ) ) {
			return false; // do not emit this specific notice
		}
		return $trigger;
	},
	10,
	3
);

// Force display_errors off for AJAX requests.
// PHP Warnings/Notices output to the response body corrupt JSON and
// silently break frontend AJAX chains (discovered in Surveys
// batch creation failure — a single "Undefined array key" Warning
// killed the entire multi-step batch process). Errors still go to
// debug.log via WP_DEBUG_LOG; this only prevents screen output.
if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
	@ini_set( 'display_errors', '0' );
}

// Load all inc/ class files explicitly
require_once get_template_directory() . '/inc/interface-zdz-app.php';
require_once get_template_directory() . '/inc/class-zdz-salesperson-code.php'; // ZDZ_Salesperson_Code — one person's salesperson code (the profile's, else the party code)
require_once get_template_directory() . '/inc/class-zdz-core-settings.php';
// v1.1.1 fix: instantiate Core Settings NOW so its parent admin menu (zdz-core-settings)
// hooks admin_menu before the Business Profile / Item Engine submenu classes (loaded just
// below) do. Otherwise those submenus register against a not-yet-existent parent, become
// orphaned, and WordPress denies direct access ("Sorry, you are not allowed to access this
// page.") even for administrators. get_instance() is a singleton, so the later call in the
// init block is a harmless no-op.
ZDZ_Core_Settings::get_instance();
require_once get_template_directory() . '/inc/class-zdz-business-profile.php'; // v1.0.0: the business's own identity (names, brand, contact, senders, locale)
require_once get_template_directory() . '/inc/class-zdz-identity-pack.php';    // v1.0.0: import a business as data — preview, confirm, revert
require_once get_template_directory() . '/inc/class-zdz-business-profile-admin.php'; // v1.0.0: the screens for both of the above

require_once get_template_directory() . '/inc/class-zdz-item-engine.php';       // v1.1.0: Item Engine — the shared admin-defined catalog (Products/Services, subtypes, aliases, pricing schemes) and the single authority for the cross-app COUNTS CONTRACT. Ships EMPTY. Self-boots (schema only); jobs taxonomy filters resolve through it.
require_once get_template_directory() . '/inc/class-zdz-item-engine-admin.php'; // v1.1.0: the Item Engine admin screen (catalog, subtypes, pricing schemes, counts-contract status, optional sample set)
require_once get_template_directory() . '/inc/class-zdz-doc-conventions.php'; // v1.1.0: Document Conventions (ZDZ_Doc_Conventions) — the business's house style applied ON OUTPUT. Ships neutral; self-boots.
require_once get_template_directory() . '/inc/class-zdz-compensation.php'; // v1.1.0: Compensation Core service (ZDZ_Compensation) — commission structures/tiers, split policies, piece rates, product minimums, ledger kinds, card-fee handling, pay calendar, payability gate, attribution precedence. Ships EMPTY. Self-boots.
require_once get_template_directory() . '/inc/class-zdz-answer-authority.php'; // Answer Authority — confidence tier (confirmed>derived>inferred>unknown, propagates through arithmetic) + the SINGLE outbound gate (chat/email/push/digest/stream): no answer leaves stronger than its evidence. Self-boots.
require_once get_template_directory() . '/inc/class-zdz-rule-governance.php'; // v1.1.0: Rule Governance — rules as typed parameterised objects; the prompt is a rendering of the rule set. A cited rule must exist (fails loudly); the safety floor is non-overridable. Self-boots.
require_once get_template_directory() . '/inc/class-zdz-doc-preservation.php'; // Zdz_Doc_Preservation — shared estimate+invoice preservation lock (arm-from-typed-rules, restore-preserving-internal-fields). Stateless; no self-boot.
require_once get_template_directory() . '/inc/class-zdz-doc-guard.php';        // Zdz_Doc_Guard — identity-blind, fail-open zero-regression value floor (protects document totals on update). Stateless; no self-boot.
require_once get_template_directory() . '/inc/class-zdz-model-registry.php'; // v1.1.0: Model Registry — per-task model slots replacing hardcoded model names; capability/fallback/retired maps ship EMPTY; base model read from ZDZ_Core_Settings; Poe stays the v1 gateway. Self-boots.
require_once get_template_directory() . '/inc/class-zdz-request-guard.php';   // Zdz_Request_Guard — wall-clock request budget (reserve-next-call), per-cURL-handle timeout clamp, crash-safe global cron lock. Ends worker-exhaustion 502s. Self-boots.
require_once get_template_directory() . '/inc/class-zdz-service-breaker.php'; // Zdz_Service_Breaker — general per-service circuit breaker (3 fails → 15-min pause) + auth-wall. Self-boots.
require_once get_template_directory() . '/inc/class-zdz-sweep.php';           // Zdz_Sweep — budgeted wall-clock background sweep composing guard+breaker (cursor, lock, starved-pass logging). The shared sweep that background jobs (schedule inference, the Jobs project and geo sweeps) build on. Self-boots.

require_once get_template_directory() . '/inc/class-zdz-core-poe.php';
require_once get_template_directory() . '/inc/class-zdz-core-freshbooks.php';
require_once get_template_directory() . '/inc/class-zdz-token-service.php'; // v1.1.0: Connections credential authority — provider-agnostic single-flight OAuth refresher (ZDZ_Token_Service). Self-boots; registers the bundled FreshBooks provider via zdz_token_providers. Consumed by ZDZ_Core_FreshBooks::refresh_token().
require_once get_template_directory() . '/inc/class-zdz-core-nutshell.php';
require_once get_template_directory() . '/inc/class-zdz-core-review-bridge.php';
require_once get_template_directory() . '/inc/class-zdz-name-match.php'; // ZDZ_Name_Match — shared soundalike/homophone name matcher (Core). No name list ships: the curated groups live in the private option zdz_name_homophones (+ the zdz_name_homophones_map filter).
// An older build kept the curated list as a file beside the class; move it into the private option and
// delete it. Only a file_exists() runs once the move is done, so the database is not asked on every request.
add_action( 'init', function () {
	if ( class_exists( 'ZDZ_Name_Match' ) && method_exists( 'ZDZ_Name_Match', 'migrate_legacy_file' )
		&& is_file( get_template_directory() . '/inc/' . ZDZ_Name_Match::LEGACY_FILE ) ) {
		ZDZ_Name_Match::migrate_legacy_file( get_template_directory() . '/inc' );
	}
}, 1 );
require_once get_template_directory() . '/inc/class-zdz-contact-bridge.php'; // orchestrator contact-lookup capability ([ZDZ_CONTACT])
require_once get_template_directory() . '/inc/class-zdz-orchestrator.php'; // deterministic Poe-free dashboard intent classifier (/zorderz/v1/orchestrate)
require_once get_template_directory() . '/inc/class-zdz-plugin-api.php';
require_once get_template_directory() . '/inc/class-zdz-share-link.php'; // reusable secret share-link primitives (ZDZ_Share_Link)
require_once get_template_directory() . '/inc/class-zdz-maps.php'; // ZDZ_Maps — map-provider seam (zdz_maps_providers filter, Core default) + client config emitter for the shared zdzMapsUrl() helper. Self-boots.
require_once get_template_directory() . '/inc/class-zdz-rest-api.php';
require_once get_template_directory() . '/inc/class-zdz-user-roles.php';

// ── Rename migration ─────────────────────────────────────────────────
// Applies the identifier renames that apps declare on `zdz_rename_map` (options,
// user meta, roles, capabilities, tables, cron, app ids). Idempotent and guarded
// by a stored version; a no-op on a fresh install. Must load before anything
// that reads a renamed option or resolves a role.
require_once get_template_directory() . '/inc/class-zdz-rename-migration.php';
// Runs at the earliest point a theme can act. NOTE: WordPress loads plugins
// BEFORE themes, so a plugin's own schema code may already have created a
// new-named table by now. The migration handles that case by moving rows
// rather than skipping — see migrate_tables().
add_action( 'after_setup_theme', [ 'ZDZ_Rename_Migration', 'maybe_run' ], 0 );

require_once get_template_directory() . '/inc/class-zdz-admin-ui.php';
require_once get_template_directory() . '/inc/class-zdz-bug-tracker.php';
require_once get_template_directory() . '/inc/class-zdz-admin-dashboard.php';
require_once get_template_directory() . '/inc/class-zdz-view-as.php';
require_once get_template_directory() . '/inc/class-zdz-kiosk-demo.php'; // PIN-gated kiosk/demo identity switch
require_once get_template_directory() . '/inc/class-zdz-kpi-metrics.php';
require_once get_template_directory() . '/inc/class-zdz-data-permissions.php'; // Cross-plugin data permission resolution
require_once get_template_directory() . '/inc/class-zdz-hierarchy.php'; // Crew Lead hierarchy (ZDZ_Hierarchy)
require_once get_template_directory() . '/inc/class-zdz-report-spec.php';    // report/chart spec validator — the security boundary (pure; allow-lists source/entity/filter/window). Self-contained.
require_once get_template_directory() . '/inc/class-zdz-report-sources.php'; // report event-source registry + money-entitlement gate + Flow-outbox reader. Ships EMPTY. Self-boots.
require_once get_template_directory() . '/inc/class-zdz-party.php'; // v1.1.0: authoritative "selectable people" roster (ZDZ_Party) — first shape of the Party core service
require_once get_template_directory() . '/inc/class-zdz-inference.php'; // Zdz_Inference — CRON-only, side-effect-free advisory schedule inference (Connections-in → Identity parser → TIER_INFERRED → non-autoloaded cache → read-only consumers). Self-boots its cron; ships no parser (infers nothing until configured).
require_once get_template_directory() . '/inc/class-zdz-integration-tests.php'; // 7B: Integration health check panel

// ── Kernel: verbs, people, app groups, consent, credential formats ──────
require_once get_template_directory() . '/inc/class-zdz-capabilities.php';  // ZDZ_Capabilities — one list of every app's verbs; can()/invoke() with a fail-closed write rule; GET /zorderz/v1/capabilities (admins)
require_once get_template_directory() . '/inc/class-zdz-person-color.php';  // ZDZ_Person_Color — one tone and one role shape per person (initials only reach the browser)
require_once get_template_directory() . '/inc/class-zdz-app-groups.php';    // ZDZ_App_Groups — five app groups with fixed accessible colours; arrangement is the business's (org.yml app_groups)
require_once get_template_directory() . '/inc/class-zdz-consent.php';       // ZDZ_Consent — one usage-records consent; card off until switched on; notice from the Business Profile
require_once get_template_directory() . '/inc/class-zdz-core-crypto.php';   // ZDZ_Core_Crypto — exact round-trip reader for the three at-rest credential formats
require_once get_template_directory() . '/inc/class-zdz-salt-watch.php';   // ZDZ_Salt_Watch — notices (and Site Health) when wp-config.php's salts change, naming what breaks
require_once get_template_directory() . '/inc/class-zdz-cred-expiry.php';   // ZDZ_Cred_Expiry — an expiry DATE per provider credential, with a notice from 30 days before

// ── Credentials: one key per service, one billing credential, the staging guard ──
// The staging guard decides first: on a copy of the live site it holds mail, provider
// writes and background jobs from this request on.
require_once get_template_directory() . '/inc/class-zdz-staging-guard.php';      // ZDZ_Staging_Guard — a copy of the live site never reaches customers (fail-safe live-site pin)
ZDZ_Staging_Guard::boot();
// The billing views are registered now (before any request-time read of an app's billing
// rows), then the credentials layer, which re-encodes their client secret per reader
// (priority 20 on the same filters) and adds its write guards before any settings save.
require_once get_template_directory() . '/inc/class-zdz-fb-credentials.php';     // ZDZ_Core_FB_Credentials — one billing credential, served to each app family in the format it reads
require_once get_template_directory() . '/inc/class-zdz-core-credentials.php';   // ZDZ_Core_Credentials — Zorderz → Credentials: one key per service, every copy in its reader's format
ZDZ_Core_FB_Credentials::init();
ZDZ_Core_Credentials::init();
// The business's own Google reviews (read-only): its OAuth client is a Credentials entry, registered before the
// credentials layer arms its write guards (after_setup_theme).
require_once get_template_directory() . '/inc/class-zdz-core-google-business.php'; // ZDZ_Core_Google_Business — Connect, the location, the total and average for the home tile
ZDZ_Core_Google_Business::init();
// One-time election of the billing app, on an admin request only (housekeeping, never a front-end request).
add_action( 'admin_init', array( 'ZDZ_Core_FB_Credentials', 'consolidate' ) );

require_once get_template_directory() . '/inc/class-zdz-quiet-hours.php';   // ZDZ_Quiet_Hours — when a phone notification waits: the person's window, the business's, or Core's overnight one
require_once get_template_directory() . '/inc/class-zdz-push-setup.php';    // ZDZ_Push_Setup — GET /zorderz/v1/push/setup asks every <app>.push_setup verb (Settings -> Notifications)
require_once get_template_directory() . '/inc/class-zdz-contacts.php';      // ZDZ_Contacts — the contact log behind "Contacted": log() / reached() / counts(), owed CRM notes, contact.log / contact.counts
ZDZ_Capabilities::init();
ZDZ_Consent::boot();
ZDZ_Quiet_Hours::boot();
ZDZ_Push_Setup::init();
ZDZ_Contacts::boot();
ZDZ_Salt_Watch::boot();
ZDZ_Cred_Expiry::boot();

// ── Kernel: request and outbound safety (BEGIN) ─────────────────────────
// Helpers the apps call before the server sends to an address a person or an administrator
// gave it, or records who asked. No hooks of their own.
require_once get_template_directory() . '/inc/class-zdz-push-endpoint.php'; // ZDZ_Push_Endpoint — a Web Push endpoint is stored and sent to only on a known push service (zdz_push_endpoint_hosts adds hosts)
require_once get_template_directory() . '/inc/class-zdz-client-ip.php';     // ZDZ_Client_IP — the address a record keeps: REMOTE_ADDR, or a trusted proxy's value on zdz_client_ip; never a forwarded header
require_once get_template_directory() . '/inc/class-zdz-outbound.php';      // ZDZ_Outbound — admin-configured addresses through wp_safe_remote_request(), redirects capped; private only where a service sits there by design
// ── Kernel: request and outbound safety (END) ───────────────────────────

// ── Kernel: privacy and usage records ────────────────────────────────
require_once get_template_directory() . '/inc/class-zdz-sealed.php';       // ZDZ_Sealed — the vault for gate, lockbox, alarm, door and keypad codes; POST /zorderz/v1/sealed/<id>/open
require_once get_template_directory() . '/inc/class-zdz-private.php';      // ZDZ_Private — codes customer details before words reach a model or an app's own tables (ZDZ_Private::for( 'assistant' ))
require_once get_template_directory() . '/inc/class-zdz-sealed-admin.php'; // Zorderz → Gate code key: the codes' key state, printed recovery code, ZDZ_SEALED_KEY
require_once get_template_directory() . '/inc/class-zdz-ask-context.php';  // ZDZ_Ask_Context — "Ask chat about this": a widget's text, privacy-checked, POST /zorderz/v1/ask-context
require_once get_template_directory() . '/inc/class-zdz-usage.php';        // ZDZ_Usage — the Usage view (GET /zorderz/v1/admin/usage), each person's own (GET /zorderz/v1/usage/mine), record()
ZDZ_Sealed::boot();
ZDZ_Sealed_Admin::boot();
ZDZ_Ask_Context::init();
ZDZ_Usage::boot();
// ── Kernel: the Ai router ───────────────────────────────────────────────
// One router for every app's Ai call: roles (not model names) resolved through the
// Model Registry's llm_* slots, customer details coded before anything leaves, a
// zero-data-retention guard, spend booked before a call is sent, one ledger row per
// call (numbers only). Nothing is sent until an admin sets the models, their prices
// and a monthly budget (Zorderz → Ai usage → Setup).
require_once get_template_directory() . '/inc/llm/class-zdz-llm-provider.php';           // ZDZ_LLM_Provider (the seam) + ZDZ_LLM_Wire
require_once get_template_directory() . '/inc/class-zdz-llm-guard.php';                  // ZDZ_LLM_Guard — refuses anything outside zero data retention
require_once get_template_directory() . '/inc/llm/class-zdz-llm-anthropic.php';          // the native Messages API adapter
require_once get_template_directory() . '/inc/llm/class-zdz-llm-openai-compatible.php'; // any OpenAI-style provider entry
require_once get_template_directory() . '/inc/llm/class-zdz-llm-poe.php';                // an admin's choice to point a role at the Poe gateway
if ( defined( 'ZDZ_LLM_FAKE' ) && ZDZ_LLM_FAKE ) {
	require_once get_template_directory() . '/inc/llm/class-zdz-llm-fake.php';           // scripted answers, for test runs only
}
require_once get_template_directory() . '/inc/class-zdz-llm-ledger.php';                 // ZDZ_LLM_Ledger — one row per call + the spend holds
require_once get_template_directory() . '/inc/class-zdz-core-llm.php';                   // ZDZ_Core_LLM::ask( $role, $request, $ctx )
require_once get_template_directory() . '/inc/class-zdz-llm-admin.php';                  // Zorderz → Ai usage (with Setup) and Ai checks
require_once get_template_directory() . '/db/migrate-llm-ledger.php';                    // the ledger and holds tables, once per schema version
ZDZ_Core_LLM::boot();
ZDZ_LLM_Ledger::boot();
ZDZ_LLM_Admin::boot();

// ── Kernel: Privacy View and the price book ─────────────────────────────
// ZDZ_Privacy_View boots here, before after_setup_theme: its gate runs first of
// all on that hook, before any plugin's init, for an account an administrator
// has put in Privacy View.
require_once get_template_directory() . '/inc/class-zdz-privacy-view.php';  // ZDZ_Privacy_View — deny-by-default surfaces and pseudonyms for one account; Zorderz → Privacy View look-up
require_once get_template_directory() . '/inc/class-zdz-price-book.php';    // ZDZ_Price_Book — the priced catalog over the Item Engine (grid schemes); costs admin-only; filled by the Identity Pack
require_once get_template_directory() . '/inc/class-zdz-price-book-admin.php'; // Zorderz → Price Book
ZDZ_Privacy_View::boot();
ZDZ_Price_Book::boot();
ZDZ_Price_Book_Admin::init();
// ── end Privacy View and the price book ─────────────────────────────────

// ── The assistant's kernel: ask-bar phrases and actions, each person's apps (BEGIN) ──
// The ask bar's rules are data (ZDZ_Ask_Phrases, ZDZ_Ask_Actions), sent to the page so the page and the server run the
// same rules; each person's home apps, at work and after hours, come from ZDZ_For_You. Both reach the page on
// zdzData (askPhrases, forYou), added after the main data block so this stays one self-contained piece.
require_once get_template_directory() . '/inc/class-zdz-ask-phrases.php'; // ZDZ_Ask_Phrases — launch rows, the commission and stock rules (zdz_ask_launch_rows, zdz_ask_trade_words)
require_once get_template_directory() . '/inc/class-zdz-ask-actions.php'; // ZDZ_Ask_Actions — the work people do, said or typed, opens the app that does it (zdz_ask_actions)
require_once get_template_directory() . '/inc/class-zdz-for-you.php';     // ZDZ_For_You — each person's apps by work hours and the hour; POST /zorderz/v1/for-you/reset
ZDZ_For_You::init();
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_user_logged_in() || ! function_exists( 'wp_script_is' ) || ! wp_script_is( 'zdz-app-js', 'enqueued' ) ) {
		return;
	}
	$uid  = (int) get_current_user_id();
	$user = wp_get_current_user();
	$role = $user && ! empty( $user->roles ) ? (string) reset( $user->roles ) : '';
	if ( class_exists( 'ZDZ_View_As' ) && ZDZ_View_As::is_emulating() && ZDZ_View_As::get_emulated_role() ) {
		$role = (string) ZDZ_View_As::get_emulated_role();   // the row the page draws is the viewed role's
	}
	$add = array(
		'forYou'     => ZDZ_For_You::for_js( $uid, $role ),
		'askPhrases' => ZDZ_Ask_Phrases::for_js( $uid ),
	);
	wp_add_inline_script( 'zdz-app-js', 'window.zdzData=Object.assign(window.zdzData||{},' . wp_json_encode( $add ) . ');', 'before' );
}, 30 );
// ── The assistant's kernel (END) ──

// ── Backend infrastructure (no frontend changes) ──────────────
// Adds ZDZ_User_Goals + ZDZ_Personal_Records classes and runs their
// one-time DB migration. Purely additive — does NOT modify KPI metric
// collection, cache keys, or any existing render path.
require_once get_template_directory() . '/inc/class-zdz-user-goals.php';
require_once get_template_directory() . '/inc/class-zdz-personal-records.php';
require_once get_template_directory() . '/inc/class-zdz-user-media.php'; // Shared media management for sketches, photos, documents
require_once get_template_directory() . '/inc/class-zdz-media-share.php';  // ZDZ_Media_Share — per-recipient media share grants under the tokened media links
require_once get_template_directory() . '/inc/class-zdz-media-marks.php';  // ZDZ_Media_Marks — drawings on a photo, kept apart from it: per-person layers, sharing, notes; /media/{id}/marks
require_once get_template_directory() . '/inc/class-zdz-media-geocoder.php';   // EXIF inspector: offline reverse geocoder (privacy-first)
require_once get_template_directory() . '/inc/class-zdz-media-exif.php';       // EXIF inspector: report builder
require_once get_template_directory() . '/inc/class-zdz-media-exif-rest.php';  // EXIF inspector: GET /zorderz/v1/media/{id}/exif
require_once get_template_directory() . '/inc/class-zdz-geocoder.php';         // ZDZ_Geocoder — forward geocoder + shared address cache-key contract (networked-off by default). Server-side only; coordinates never cross a client boundary.
require_once get_template_directory() . '/inc/class-zdz-media-location.php';   // ZDZ_Media_Location — media-set location classifier (CATEGORICAL STATUS ONLY; closes the raw-centroid leak).
require_once get_template_directory() . '/inc/class-zdz-magic-link-bridge.php'; // PWA magic login bridge
require_once get_template_directory() . '/inc/class-zdz-login-next.php';         // ZDZ_Login_Next — signing in returns to the in-app page that asked for it (same-site, allow-listed paths only)
require_once get_template_directory() . '/inc/class-zdz-alert-router.php'; // Cross-plugin alert routing + notification delivery
require_once get_template_directory() . '/inc/class-zdz-data-portability.php'; // v1.4.0: Company Data Export / Import (portability, backup, migration) under Tools -> Zorderz Data
require_once get_template_directory() . '/inc/class-zdz-apps-autoinstall.php'; // v1.6.0: one-upload onboarding — the active theme installs + activates the bundled apps plugin once, so a first install is a single upload
require_once get_template_directory() . '/db/migrate-2.13.0.php';
require_once get_template_directory() . '/db/migrate-2.17.1.php'; // zdz_user_media table
require_once get_template_directory() . '/db/migrate-alert-router.php'; // zdz_notifications table
require_once get_template_directory() . '/db/migrate-bell.php';         // the bell's first day: alerts older than 30 days marked read
require_once get_template_directory() . '/db/migrate-places.php';       // zdz_places: the geocoder's permanent cache
require_once get_template_directory() . '/db/migrate-2.20.3.php'; // clean deprecated zdz_pr_* user meta
require_once get_template_directory() . '/db/migrate-2.20.4.php'; // zdz_user_media EXIF columns (captured_at, gps_lat, gps_lng)

// Initialize PWA Magic Link Bridge (registers REST routes + login_redirect filter)
ZDZ_Magic_Link_Bridge::get_instance();

// Initialize View-As role switcher (registers hooks before 'init' fires)
ZDZ_View_As::init();

// v1.4.0: Company Data Export / Import (admin_menu + admin_post handlers)
ZDZ_Data_Portability::init();

// v1.6.0: one-upload onboarding — install + activate the bundled apps plugin once.
ZDZ_Apps_AutoInstall::init();

// Initialize Singleton Classes
add_action( 'init', function() {
	ZDZ_Core_Settings::get_instance();
	ZDZ_Plugin_API::get_instance();
	ZDZ_Rest_API::get_instance();
	ZDZ_Admin_Dashboard::get_instance();
	ZDZ_KPI_Metrics::get_instance();
	ZDZ_Alert_Router::get_instance(); // Alert routing + notification delivery
} );

// Theme Setup
add_action( 'after_setup_theme', function() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', [ 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ] );
	add_theme_support( 'woocommerce' );
	add_theme_support( 'custom-logo' );

	register_nav_menus( [
		'primary' => __( 'Primary Menu', 'zorderz' ),
	] );
} );

/* ── Strip WordPress core bloat ──
 * This SPA doesn't use the block editor, emoji detection, oEmbed, or RSS
 * feeds. Each of these adds render-blocking or parser-blocking resources
 * to <head> via wp_head(). Removing them cuts 3-5 HTTP requests and ~60KB
 * of CSS/JS the browser would otherwise download and parse before the
 * skeleton can paint.
 */

// Remove emoji detection script + inline CSS (~15KB JS + inline CSS)
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
remove_action( 'admin_print_styles', 'print_emoji_styles' );

// Remove block editor CSS — this is a custom SPA, no Gutenberg blocks
// Also remove WooCommerce frontend CSS — the SPA doesn't render WC
// elements (cart, product pages) on the front page. WC styles are only
// needed on the register page (handled by page-register.php separately).
add_action( 'wp_enqueue_scripts', function() {
	wp_dequeue_style( 'wp-block-library' );
	wp_dequeue_style( 'wp-block-library-theme' );
	wp_dequeue_style( 'global-styles' );       // WP 5.9+ global styles
	wp_dequeue_style( 'classic-theme-styles' ); // WP 6.1+ classic theme compat
	// WooCommerce frontend CSS (~30KB combined, all render-blocking)
	if ( ! is_page( 'register' ) ) {
		wp_dequeue_style( 'woocommerce-general' );
		wp_dequeue_style( 'woocommerce-layout' );
		wp_dequeue_style( 'woocommerce-smallscreen' );
		wp_dequeue_style( 'wc-blocks-style' );
		wp_dequeue_style( 'wc-blocks-vendors-style' );
	}
}, 100 ); // Priority 100 = runs after core + plugin enqueues

// Remove oEmbed discovery links + scripts (not used in this SPA)
remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
remove_action( 'wp_head', 'wp_oembed_add_host_js' );

// Remove RSS feed links from <head> (SPA has no feed)
remove_action( 'wp_head', 'feed_links', 2 );
remove_action( 'wp_head', 'feed_links_extra', 3 );

// Remove WP version meta tag (security + no value for SPA)
remove_action( 'wp_head', 'wp_generator' );

// Remove wlwmanifest + RSD links (XML-RPC era relics)
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'rsd_link' );

// Remove REST API discovery link (SPA already knows its API URL via zdzData)
remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );

// Remove shortlink
remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );

// Enqueue Scripts and Styles
add_action( 'wp_enqueue_scripts', function() {
	// ── FIX: $version was resolving to EMPTY on the live site. ──
	// Live evidence: every theme asset shipped as `?ver=.1781587569` — note the
	// LEADING DOT with no version before it — and assets passing the bare
	// $version (lucide, dashboard-personalization) shipped as `?ver=7.0`, i.e.
	// WordPress had substituted the WP-core fallback because the value was empty.
	// wp_get_theme()->get('Version') can return '' this early/under some stacking
	// (object cache / theme-root timing on WP Engine), which (a) made the ?ver=
	// malformed and (b) — combined with the year-long max-age on these assets —
	// froze every browser onto one cache key that never changes, so a deployed
	// fix could never reach an already-cached visitor. Pin an explicit constant
	// and harden the fallbacks so ?ver= is ALWAYS well-formed and content-keyed.
	$version = wp_get_theme()->get( 'Version' );
	if ( ! is_string( $version ) || $version === '' ) {
		$version = ZDZ_THEME_VER_FLOOR; // single source — see the constant's docblock
	}

	// ── (hardened): Content-based cache-busting for the theme's
	// OWN assets. Versioning by the theme version string alone lets a CDN /
	// NitroPack / PWA service-worker keep serving an OLD app.js after a deploy if
	// the ?ver= didn't change. Append the file's modification time so the ?ver=
	// changes the instant a file's content changes — no manual bump required, and
	// every cache layer is forced to re-fetch. If filemtime() fails, fall back to
	// the version string (never to empty, which is what caused the frozen cache).
	// Applies only to local theme files (skips vendored/CDN assets).
	$asset_ver = function( $rel_path ) use ( $version ) {
		$abs   = get_template_directory() . $rel_path;
		$mtime = @filemtime( $abs );
		return $mtime ? ( $version . '.' . $mtime ) : $version;
	};

	// ── Performance — self-host Inter fonts and Lucide icons.
	// Eliminates three external CDN dependencies (fonts.googleapis.com,
	// fonts.gstatic.com, cdn.jsdelivr.net) that were render-blocking and
	// added 1-8 seconds on slow cellular connections. Font files now live
	// in assets/fonts/, Lucide UMD bundle in assets/js/vendor/.
	// The old preconnect hints are removed — no external CDNs needed.
	wp_enqueue_style( 'zdz-fonts', get_template_directory_uri() . '/assets/css/fonts.css', [], $asset_ver( '/assets/css/fonts.css' ) );
	wp_enqueue_script( 'zdz-lucide', get_template_directory_uri() . '/assets/js/vendor/lucide.min.js', [], $version, true );

	wp_enqueue_style( 'zdz-style', get_stylesheet_uri(), [ 'zdz-fonts' ], $asset_ver( '/style.css' ) );
	wp_enqueue_style( 'zdz-app-css', get_template_directory_uri() . '/assets/css/app.css', [ 'zdz-fonts' ], $asset_ver( '/assets/css/app.css' ) );
	wp_enqueue_style( 'zdz-bug-reporter-css', get_template_directory_uri() . '/assets/css/bug-reporter.css', [ 'zdz-app-css' ], $asset_ver( '/assets/css/bug-reporter.css' ) );
	if ( class_exists( 'ZDZ_App_Groups' ) ) {
		wp_add_inline_style( 'zdz-app-css', ZDZ_App_Groups::css_vars() ); // the five group colours + the reserved "needs you" colour
	}

	// ── Resource preloading is handled by NitroPack ──
	// NitroPack analyzes real page loads and generates optimal preload
	// hints automatically. Manual preloads would duplicate its work.

	wp_enqueue_script( 'zdz-overlay-escape-js', get_template_directory_uri() . '/assets/js/overlay-escape.js', [], $asset_ver( '/assets/js/overlay-escape.js' ), true );
	wp_enqueue_script( 'zdz-app-js', get_template_directory_uri() . '/assets/js/app.js', ['zdz-lucide', 'zdz-overlay-escape-js'], $asset_ver( '/assets/js/app.js' ), true );
	wp_enqueue_script( 'zdz-bridge-js', get_template_directory_uri() . '/assets/js/bridge.js', ['zdz-app-js'], $asset_ver( '/assets/js/bridge.js' ), true );
	// The kit of parts (ZDZ.ui): one loader, toast, sheet, request helper, person badge and paper for every app.
	wp_enqueue_style( 'zdz-kit-css', get_template_directory_uri() . '/assets/css/zdz-kit.css', [ 'zdz-app-css' ], $asset_ver( '/assets/css/zdz-kit.css' ) );
	wp_enqueue_script( 'zdz-kit-js', get_template_directory_uri() . '/assets/js/zdz-kit.js', [ 'zdz-app-js' ], $asset_ver( '/assets/js/zdz-kit.js' ), true );
	// The usage-records card (does nothing while the card is off).
	wp_enqueue_style( 'zdz-consent-css', get_template_directory_uri() . '/assets/css/consent.css', [ 'zdz-kit-css' ], $asset_ver( '/assets/css/consent.css' ) );
	wp_enqueue_script( 'zdz-consent-js', get_template_directory_uri() . '/assets/js/zdz-consent.js', [ 'zdz-kit-js' ], $asset_ver( '/assets/js/zdz-consent.js' ), true );
	// Settings -> Notifications: one switch for phone notifications on this device (ZDZPush), the self-heal on each
	// start, and the routine an app's own bell runs. Before the bell, which asks it.
	wp_enqueue_style( 'zdz-push-css', get_template_directory_uri() . '/assets/css/push.css', [ 'zdz-kit-css' ], $asset_ver( '/assets/css/push.css' ) );
	wp_enqueue_script( 'zdz-push-js', get_template_directory_uri() . '/assets/js/zdz-push.js', [ 'zdz-kit-js' ], $asset_ver( '/assets/js/zdz-push.js' ), true );
	// The bell: ZDZ_Alert_Router's in-app alerts, on the home screen and the bottom navigation.
	wp_enqueue_style( 'zdz-bell-css', get_template_directory_uri() . '/assets/css/bell.css', [ 'zdz-kit-css' ], $asset_ver( '/assets/css/bell.css' ) );
	wp_enqueue_script( 'zdz-bell-js', get_template_directory_uri() . '/assets/js/zdz-bell.js', [ 'zdz-kit-js', 'zdz-push-js' ], $asset_ver( '/assets/js/zdz-bell.js' ), true );
	// Contacts made from the page: marked Call / Text / E-mail taps, and "How did the call go?".
	wp_enqueue_script( 'zdz-contacts-js', get_template_directory_uri() . '/assets/js/zdz-contacts.js', [ 'zdz-kit-js' ], $asset_ver( '/assets/js/zdz-contacts.js' ), true );
	// Settings → Your app use: this person's own last 30 days (draws nothing for the shared device or in Demo Mode).
	wp_enqueue_script( 'zdz-usage-js', get_template_directory_uri() . '/assets/js/zdz-usage.js', [ 'zdz-kit-js' ], $asset_ver( '/assets/js/zdz-usage.js' ), true );
	// "Ask chat about this" on every widget: the server (ZDZ_Ask_Context) removes private details before the preview.
	wp_enqueue_style( 'zdz-ask-chat-css', get_template_directory_uri() . '/assets/css/ask-chat.css', [ 'zdz-app-css' ], $asset_ver( '/assets/css/ask-chat.css' ) );
	wp_enqueue_script( 'zdz-ask-chat-js', get_template_directory_uri() . '/assets/js/ask-chat.js', [ 'zdz-bridge-js' ], $asset_ver( '/assets/js/ask-chat.js' ), true );
	wp_enqueue_script( 'zdz-bug-reporter-js', get_template_directory_uri() . '/assets/js/bug-reporter.js', ['zdz-app-js'], $asset_ver( '/assets/js/bug-reporter.js' ), true );

	// EXIF inspector: on-demand photo metadata panel. CSS depends on
	// zdz-app-css for the shared design tokens; JS depends on zdz-app-js so the
	// localized `zdzData` (carrying the wp_rest nonce the panel sends as
	// X-WP-Nonce) is defined before the panel runs. The REST route self-registers.
	wp_enqueue_style(
		'zdz-exif-panel-css',
		get_template_directory_uri() . '/assets/css/exif-panel.css',
		[ 'zdz-app-css' ],
		$asset_ver( '/assets/css/exif-panel.css' )
	);
	wp_enqueue_script(
		'zdz-exif-panel-js',
		get_template_directory_uri() . '/assets/js/exif-panel.js',
		[ 'zdz-app-js' ],   // dependency is REQUIRED — guarantees window.zdzData (wp_rest nonce) first
		$asset_ver( '/assets/js/exif-panel.js' ),
		true
	);

	// optional personalization layer — drag-reorder KPI cards
	// and Personal Records strip. Self-contained; fails closed via its
	// own sessionStorage kill switch if anything goes wrong.
	wp_enqueue_style(
		'zdz-dashboard-personalization-css',
		get_template_directory_uri() . '/assets/css/dashboard-personalization.css',
		[ 'zdz-app-css' ],
		$version
	);
	wp_enqueue_script(
		'zdz-dashboard-personalization-js',
		get_template_directory_uri() . '/assets/js/dashboard-personalization.js',
		[ 'zdz-app-js' ],
		$version,
		true
	);

	$user_id = get_current_user_id();
	$user = wp_get_current_user();
	$roles = (array) $user->roles;
	$role = ! empty( $roles ) ? $roles[0] : '';
	$display_name = $user->display_name;
	$initial = ! empty( $display_name ) ? substr( $display_name, 0, 1 ) : '';

	$user_initials = (string) get_user_meta( $user_id, 'zdz_user_initials', true );
	$user_notes    = (string) get_user_meta( $user_id, 'zdz_user_notes', true );

	$first_name = $user->first_name;
	if ( empty( $first_name ) ) {
		$first_name = explode( ' ', $display_name )[0];
	}

	// View-As: override effective role for frontend rendering
	$effective_role = $role;
	if ( class_exists( 'ZDZ_View_As' ) && ZDZ_View_As::is_emulating() ) {
		$effective_role = ZDZ_View_As::get_emulated_role();
	}

	// ── Shared-kiosk greeting alias (zdz_general) ───────────────────────────
	// Never greet the workshop iPad by the literal shared login name
	// ("General"). Because the account is touched by everyone in the shop, the
	// header should read the company name (or a neutral "there") instead.
	// We override $first_name at the PHP layer so the alias is server-
	// controlled and renderGreeting() in app.js — which prints
	// state.user.firstName (i.e. zdzData.userFirstName) — needs no special
	// case. Keyed on the EFFECTIVE role so an admin using View-As to preview
	// the kiosk sees the alias too. Filterable for easy tuning.
	if ( 'zdz_general' === $effective_role ) {
		$first_name = apply_filters(
			'zdz_general_greeting_name',
			get_bloginfo( 'name' ) ?: 'there'   // → "Good afternoon, Zorderz" or "… there"
		);
	}

	$is_admin = ZDZ_User_Roles::is_admin_role( $effective_role );

	// Human-readable role label for display
	$role_labels  = ZDZ_User_Roles::get_role_labels();
	$role_label   = $role_labels[ $effective_role ] ?? ucfirst( str_replace( [ 'zdz_', '_' ], [ '', ' ' ], $effective_role ) );

	$custom_logo_id  = get_theme_mod( 'custom_logo' );
	$custom_logo_url = $custom_logo_id ? wp_get_attachment_image_url( $custom_logo_id, 'full' ) : '';

	$logo_light = get_theme_mod( 'zdz_logo_light', '' );
	$logo_dark  = get_theme_mod( 'zdz_logo_dark', '' );
	$logo_vertical = get_theme_mod( 'zdz_logo_vertical', '' );

	// Logo variant fallbacks — if only one variant is uploaded,
	// use it for both so the logo never goes blank on theme switch.
	if ( empty( $logo_dark ) && ! empty( $logo_light ) ) {
		$logo_dark = $logo_light;
	}
	if ( empty( $logo_light ) && ! empty( $logo_dark ) ) {
		$logo_light = $logo_dark;
	}

	$zdz_data = [
		'nonce'          => wp_create_nonce( 'wp_rest' ),
		'apiUrl'         => esc_url_raw( rest_url( ZDZ_REST_NS . '/' ) ),
		'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
		'userId'         => $user_id,
		'userRole'       => $effective_role,
		'userRoleLabel'  => $role_label,
		'isAdmin'        => $is_admin,
		'userName'       => $display_name,
		'userInitial'    => $initial,
		'userInitials'   => $user_initials,
		'userNotes'      => $user_notes,
		'apps'           => ZDZ_Plugin_API::get_instance()->get_user_app_configs( $user_id ),
		'appOrder'       => get_user_meta( $user_id, 'zdz_app_order', true ) ?: [],
		'widgetOrder'    => get_user_meta( $user_id, 'zdz_dash_widget_order', true ) ?: [],
		// ── Home screen: the focus view, pins, each person's apps and the ask bar's rules ──
		// focusView: tapping an app opens it as its own page, unless the person turned it off in Settings (a site
		// changes the default with the 'zdz_focus_view_default' filter). homePins: the widgets this person keeps on
		// home. forYou: this person's apps ranked for work hours and after hours, with their hours and the site's
		// zone (null for the shared device, or when that service is not installed). askPhrases: the ask bar's
		// phrase rules, the one copy the page and ZDZ_Orchestrator both run (null when not installed: the page
		// uses its own written-out rules). askHint: the business's own trade words that also mark a typed question
		// for the quick answers ('' when it lists none; ZDZ_Orchestrator::quick_hint_pattern()).
		'focusView'      => ZDZ_Rest_API::focus_view_for( $user_id ),
		'homePins'       => ZDZ_Rest_API::home_pins_for( $user_id ),
		'forYou'         => ( class_exists( 'ZDZ_For_You' ) && method_exists( 'ZDZ_For_You', 'for_js' ) ) ? ZDZ_For_You::for_js( $user_id, $effective_role ) : null,
		'askPhrases'     => ( is_user_logged_in() && class_exists( 'ZDZ_Ask_Phrases' ) && method_exists( 'ZDZ_Ask_Phrases', 'for_js' ) ) ? ZDZ_Ask_Phrases::for_js( (int) $user_id ) : null,
		'askHint'        => ZDZ_Orchestrator::quick_hint_pattern(),
		// ── end home screen ──
		'themeVersion'   => $version,
		'logoutUrl'      => wp_logout_url( home_url( '/login/' ) ),
		'userFirstName'  => $first_name,
		'customLogoUrl'  => $custom_logo_url,
		'logoLight'      => $logo_light,
		'logoDark'       => $logo_dark,
		'logoVertical'   => $logo_vertical,
		'reviewBridge'   => ( new ZDZ_Core_ReviewBridge() )->is_configured(), //
		'gameAvailable'  => defined( 'ZG_PLUGIN_URL' ), // true when the Game app is loaded (the constant its entry file defines) — read this flag instead of sniffing the DOM for the game
		'dataPermissions' => class_exists( 'ZDZ_Data_Permissions' ) ? ZDZ_Data_Permissions::resolve( $user_id ) : [], // Cross-plugin data permissions for frontend gating
		// Kiosk / Demo mode flags. These are computed against the
		// REAL (cookie-authenticated) administrator — NOT the switched runtime
		// user — so the SPA shows the right control in each state:
		//   • kioskDemoActive = true  → currently running as the General
		//     account via demo mode; show the PIN-protected "Exit Demo" control.
		//   • canEnterKiosk   = true  → a genuine admin who is NOT yet in demo
		//     mode; show the "Enter Demo / Kiosk Mode" button in Settings.
		// During an active demo, the runtime user is the kiosk account (so
		// isAdmin is false and the WP-Admin link is hidden), but kioskDemoActive
		// stays true so the exit affordance remains available.
		'kioskDemoActive' => class_exists( 'ZDZ_Kiosk_Demo' ) && ZDZ_Kiosk_Demo::is_active(),
		'canEnterKiosk'   => class_exists( 'ZDZ_Kiosk_Demo' )
			&& ! ZDZ_Kiosk_Demo::is_active()
			&& user_can( ZDZ_Kiosk_Demo::get_real_user_id_public(), 'manage_options' )
			&& ZDZ_Kiosk_Demo::get_target_user_id() > 0,
		'appGroups'       => class_exists( 'ZDZ_App_Groups' ) ? ZDZ_App_Groups::for_js() : [],      // the five groups in launcher order + the "needs you" colour
		'people'          => class_exists( 'ZDZ_Person_Color' ) ? ZDZ_Person_Color::for_js() : [],  // initials, tone and shape per person — no names, no e-mails
		'consent'         => class_exists( 'ZDZ_Consent' ) ? ZDZ_Consent::for_page( $user_id ) : [ 'on' => false ],
		// The bell is drawn for a signed-in person, never the shared device and never in Demo Mode (the server
		// refuses them too). siteOffsetMin turns the alerts' site-local times into "5 min ago".
		'bellOn'          => is_user_logged_in() && ! ( class_exists( 'ZDZ_Kiosk_Demo' ) && ZDZ_Kiosk_Demo::is_active() )
			&& ! ( class_exists( 'ZDZ_Alert_Router' ) && ZDZ_Alert_Router::is_kiosk_user( $user_id ) ),
		'siteOffsetMin'   => (int) round( (float) get_option( 'gmt_offset', 0 ) * 60 ),
		// Settings -> Notifications is drawn on the same terms as the bell (GET /zorderz/v1/push/setup refuses the rest).
		'pushOn'          => is_user_logged_in() && class_exists( 'ZDZ_Push_Setup' ) && ZDZ_Push_Setup::allowed( $user_id ),
		// The name on the Home Screen icon, for the iPhone steps in Settings -> Notifications.
		'appName'         => class_exists( 'ZDZ_Business_Profile' ) ? (string) ZDZ_Business_Profile::get( 'brand.pwa.short_name', ZDZ_Business_Profile::name() ) : get_bloginfo( 'name' ),
		// The activity beacon's settings: beat = the one heartbeat rhythm; rec = may this page's person be recorded at
		// all (ZDZ_Consent::may_record), so a "no" sends nothing; mine = Settings → Your app use is drawn (never the
		// shared device, never Demo Mode).
		'track'           => [
			'beat' => ZDZ_Admin_Dashboard::HEARTBEAT_S,
			'rec'  => is_user_logged_in() && ZDZ_Admin_Dashboard::may_record( $user_id ),
			'mine' => is_user_logged_in() && class_exists( 'ZDZ_Usage' ) && ! ZDZ_Usage::is_shared( $user_id ) && ! ( class_exists( 'ZDZ_Kiosk_Demo' ) && ZDZ_Kiosk_Demo::is_active() ),
		],
		'askChatApps'     => class_exists( 'ZDZ_Ask_Context' ) ? ZDZ_Ask_Context::chat_apps() : [],
		'customerLinkPatterns' => zdz_customer_link_patterns(), // regex sources the kit's paper may copy as a customer link
	];

	wp_localize_script( 'zdz-app-js', 'zdzData', $zdz_data );
} );

/* ── Minimal Service Worker for the PWA login bridge ──
 * A page cache or CDN still handles all caching (browser cache headers). The SW
 * has a minimal scope: it handles the virtual /_zdz-bridge-token endpoint used
 * to pass auth tokens from Safari to the standalone PWA via CacheStorage, plus
 * the network-first shell and the camera upload drain. No asset caching logic.
 *
 * The SW must be registered for ALL users (including logged-out) because
 * the bridge operates during the login flow.
 *
 * Virtual /sw.js endpoint: serves the theme's sw.js from root scope.
 */
add_action( 'template_redirect', function () {
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
	$path        = trim( parse_url( $request_uri, PHP_URL_PATH ), '/' );

	// Many managed hosts configure nginx to serve *.js paths statically and to
	// 404 a .js URL that has no physical file, without ever consulting
	// WordPress. On such a host navigator.serviceWorker.register('/sw.js')
	// fails silently: no PWA bridge SW, no camera Background-Sync drain, and no
	// service worker to keep app shells fresh. The SW is therefore ALSO served
	// at the extension-less path /zdz-sw, which nginx passes through to
	// WordPress like any permalink. Registration (below) points there; /sw.js
	// is kept for hosts that route it to WordPress.
	if ( 'sw.js' !== $path && 'zdz-sw' !== $path ) {
		return;
	}

	$sw_file = get_template_directory() . '/sw.js';
	if ( ! file_exists( $sw_file ) ) {
		status_header( 404 );
		exit;
	}

	// WordPress marks this unknown path 404 in the main query (handle_404)
	// BEFORE template_redirect fires, so without an explicit status the route
	// serves the sw.js BODY with a 404 status line, and browsers REFUSE to
	// register a service worker from a non-200 response. Another app's
	// service worker would then take the root scope and the network-first
	// shell and self-heal flows would never run. status_header(200) prevents it.
	status_header( 200 );
	header( 'Content-Type: application/javascript; charset=utf-8' );
	header( 'Service-Worker-Allowed: /' );
	header( 'Cache-Control: no-cache, no-store, must-revalidate' );
	header( 'X-Robots-Tag: none' );

	readfile( $sw_file );
	exit;
}, 1 ); // Priority 1: run before access control

/**
 * /zdz-version — tiny JSON version beacon for the installed-PWA freshness
 * cycle. The SW update check compares sw.js bytes; this beacon catches
 * everything the byte-diff can't see (the inline registration script, theme +
 * component versions) for PWAs that stay open for days without a navigation.
 * Extension-less for the same nginx reason as /zdz-sw; no-store so no cache
 * layer (host page cache, optimisation plugin, browser HTTP cache) can pin it.
 */
add_action( 'template_redirect', function () {
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
	$path        = trim( parse_url( $request_uri, PHP_URL_PATH ), '/' );
	if ( 'zdz-version' !== $path ) {
		return;
	}

	// Same empty-version hardening as the enqueue callback (see ZDZ_THEME_VER_FLOOR).
	$theme_ver = wp_get_theme()->get( 'Version' );
	if ( ! is_string( $theme_ver ) || $theme_ver === '' ) {
		$theme_ver = ZDZ_THEME_VER_FLOOR; // single source — see the constant's docblock
	}

	$out = array( 'theme' => (string) $theme_ver );
	foreach ( array(
		'zana' => 'ZANA_VERSION',
		'zest' => 'ZEST_VERSION',
		'zcc'  => 'ZCC_VERSION',
		'zsch' => 'ZSCH_VERSION',
		'zim'  => 'ZIM_VERSION',
	) as $key => $const ) {
		if ( defined( $const ) ) {
			$out[ $key ] = (string) constant( $const );
		}
	}

	status_header( 200 ); // WP pre-marks unknown paths 404 (see the /zdz-sw note)
	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	header( 'X-Robots-Tag: none' );
	echo wp_json_encode( $out );
	exit;
}, 1 );

// SW registration — runs for all users (logged-in and logged-out)
// Registers /zdz-sw (extension-less; see the route handler above for why /sw.js
// cannot be served on some hosts). Default scope for a root-level script URL
// is '/', and the route also sends Service-Worker-Allowed: /.
add_action( 'wp_footer', function () {
	// Same empty-version hardening as the enqueue callback (see ZDZ_THEME_VER_FLOOR).
	$zdz_boot_ver = wp_get_theme()->get( 'Version' );
	if ( ! is_string( $zdz_boot_ver ) || $zdz_boot_ver === '' ) {
		$zdz_boot_ver = ZDZ_THEME_VER_FLOOR; // the boot version the update toast compares against
	}
	// Theme-SW registration is FLAG-GATED (default off). Another app's service
	// worker may own scope '/' carrying its push subscriptions; registering this
	// one as well would make the two workers REPLACE each other on alternating
	// page loads (network-first shell vs push). Until one worker carries both,
	// set the option zdz_sw_register='on' only for testing.
	$zdz_sw_on = ( 'on' === get_option( 'zdz_sw_register', 'off' ) );
	?>
	<script>
	(function () {
		var bootVer = <?php echo wp_json_encode( (string) $zdz_boot_ver ); ?>;

		var toastShown = false;
		function zdzUpdateToast(onTap) {
			if (toastShown || !document.body) { return; }
			toastShown = true;
			var t = document.createElement('div');
			t.id = 'zdz-update-toast';
			t.setAttribute('role', 'status');
			t.style.cssText = 'position:fixed;left:50%;transform:translateX(-50%);' +
				'bottom:calc(96px + env(safe-area-inset-bottom, 0px));z-index:2147483000;' +
				'background:var(--sys-surface-raised, var(--sys-surface, #1c2333));' +
				'color:var(--sys-text, var(--sys-on-surface, #ffffff));' +
				'border:1px solid var(--sys-outline-variant, #3a4358);border-radius:999px;' +
				'padding:6px 8px 6px 18px;font-family:inherit;font-size:14px;font-weight:600;' +
				'line-height:1.2;box-shadow:0 6px 24px rgba(0,0,0,.35);' +
				'display:flex;gap:8px;align-items:center;max-width:calc(100vw - 32px);';
			var label = document.createElement('span');
			label.textContent = 'App update ready';
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.textContent = 'Refresh';
			btn.style.cssText = 'all:unset;cursor:pointer;box-sizing:border-box;' +
				'color:var(--sys-primary, #7aa2ff);font-weight:700;font-size:14px;' +
				'min-height:44px;min-width:44px;display:inline-flex;align-items:center;' +
				'justify-content:center;padding:0 10px;';
			btn.addEventListener('click', function () {
				try { t.remove(); } catch (e) {}
				onTap();
			});
			t.appendChild(label);
			t.appendChild(btn);
			document.body.appendChild(t);
		}

		// ── FRESHNESS CYCLE — SW-INDEPENDENT ────────────────────────────
		// The /zdz-version poll and the update pill run unconditionally, so a
		// service worker that fails to register cannot park them. The SW
		// update() ride-along happens only when a registration exists
		// (window.__zdzReg).
		var lastCheck = Date.now();
		var verNagged = false;
		function zdzVersionPing() {
			if (verNagged || !bootVer) return;
			fetch('/zdz-version', { cache: 'no-store', credentials: 'same-origin' })
				.then(function (r) { return r.ok ? r.json() : null; })
				.then(function (j) {
					if (j && j.theme && j.theme !== bootVer) {
						verNagged = true;
						zdzUpdateToast(function () { window.location.reload(); });
					}
				})
				.catch(function () {});
		}
		function zdzFreshnessCheck(force) {
			if (document.visibilityState !== 'visible') return;
			if (!force && Date.now() - lastCheck < 5 * 60 * 1000) return;
			lastCheck = Date.now();
			if (window.__zdzReg) { try { window.__zdzReg.update(); } catch (e) {} }
			zdzVersionPing();
		}
		document.addEventListener('visibilitychange', function () { zdzFreshnessCheck(false); });
		setInterval(function () { zdzFreshnessCheck(true); }, 60 * 60 * 1000);
		setTimeout(zdzVersionPing, 20000); // first ping shortly after boot

		// ── SW registration (gated; see PHP note above) ─────────────────
		var SW_ON = <?php echo $zdz_sw_on ? 'true' : 'false'; ?>;
		if (SW_ON && 'serviceWorker' in navigator) {
		navigator.serviceWorker.register('/zdz-sw', { scope: '/', updateViaCache: 'none' }).then(function (reg) {
			window.__zdzReg = reg;
			try {
				// ── UPDATE FLOW ──
				// Applying an update the moment it installs is the right self-heal
				// at launch: the user hasn't started anything yet. MID-SESSION it is
				// exactly wrong: a deploy while someone is halfway through dictating
				// an estimate would reload the page under them. Never yank a working
				// user; offer a consented refresh instead of a blanket skipWaiting.
				// So: updates found within LAUNCH_MS of boot auto-apply silently;
				// updates found later show a small "App update ready — Refresh" pill
				// and wait.
				var bootT = Date.now();
				var LAUNCH_MS = 15000;

				function zdzApplyUpdate(nw) {
					try { nw.postMessage({ type: 'zdz-skip-waiting' }); } catch (e) {}
				}


				function zdzOnUpdateReady(nw) {
					if (Date.now() - bootT < LAUNCH_MS) {
						zdzApplyUpdate(nw); // launch-window self-heal
					} else {
						zdzUpdateToast(function () { zdzApplyUpdate(nw); });
					}
				}

				// A worker may already be parked in "waiting" from a previous visit.
				if (reg.waiting && navigator.serviceWorker.controller) {
					zdzOnUpdateReady(reg.waiting);
				}
				reg.addEventListener('updatefound', function () {
					var nw = reg.installing;
					if (!nw) return;
					nw.addEventListener('statechange', function () {
						if (nw.state === 'installed' && navigator.serviceWorker.controller) {
							zdzOnUpdateReady(nw);
						}
					});
				});

				var reloaded = false;
				navigator.serviceWorker.addEventListener('controllerchange', function () {
					if (reloaded) return;
					reloaded = true;
					window.location.reload();
				});

				reg.update();
			} catch (e) {}
		}).catch(function(){});
		}
	})();
	</script>
	<?php
}, 99 );

/* ── Logged-in HTML must never be cached by the device ──
 * Devices can reuse a CACHED dashboard shell for days: an iOS home-screen PWA
 * keeps serving its locally cached HTML (with a long-stale baked nonce and old
 * ?ver= script URLs), so plugin updates and fresh nonces never reach the
 * device (server-side purges can't touch a device's own HTTP cache). Send
 * explicit no-cache headers on logged-in front-end page loads so browsers and
 * PWAs revalidate the shell on every launch. Anonymous traffic is untouched: a
 * page cache keeps caching it exactly as before. (Belt and braces: the service
 * worker also fetches navigations network-first with an HTTP-cache bypass;
 * this header makes the non-SW first load correct too.)
 */
add_action( 'send_headers', function () {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
		nocache_headers();
	}
} );

/* ── Performance — defer non-critical JS ──
 * Bug reporter and dashboard personalization are interactive features that
 * don't need to block initial render. Adding `defer` lets the browser parse
 * and paint the page before downloading/executing these scripts. The core
 * app.js and bridge.js stay synchronous (in-footer) since the SPA depends
 * on them for initial routing.
 *
 * The Game app's script (zg-game-js) is deferred too: the block-breaker is
 * purely interactive and never needed for initial render (either as a
 * dashboard tile or a chat embed). The app enqueues it; this only defers it.
 */
add_filter( 'script_loader_tag', function( $tag, $handle ) {
	$defer_handles = [
		'zdz-bug-reporter-js',
		'zdz-dashboard-personalization-js',
		'zg-game-js',   // the Game app's block-breaker
	];
	if ( in_array( $handle, $defer_handles, true ) && false === strpos( $tag, 'defer' ) ) {
		$tag = str_replace( ' src=', ' defer src=', $tag );
	}

	// ── NitroPack Strong mode — protect the SPA boot chain ──
	// NitroPack Strong defers JavaScript (adds `defer` attribute) and may
	// combine or reorder scripts for performance. This is safe for most
	// sites, but the SPA boot chain has strict dependency ordering:
	//   zdz-lucide must load before zdz-app-js (app.js calls lucide.createIcons)
	//   zdz-app-js must load before zdz-bridge-js (bridge reads window state)
	//
	// Excluding these from NitroPack's JS pipeline ensures the boot chain
	// executes exactly as WordPress enqueued it — no combining, reordering,
	// or additional deferral that could break the dependency sequence.
	//
	// The nitro-exclude class is NitroPack's inline exclusion signal.
	// ALSO add these patterns to NitroPack → Settings → Exclusions:
	//   lucide.min.js
	//   app.js
	//   bridge.js
	$nitro_critical = [ 'zdz-lucide', 'zdz-app-js', 'zdz-bridge-js' ];
	if ( in_array( $handle, $nitro_critical, true ) ) {
		$tag = str_replace( '<script ', '<script class="nitro-exclude" ', $tag );
	}

	return $tag;
}, 10, 2 );

/* ── Performance — non-render-blocking CSS for secondary sheets ──
 * Bug reporter, personalization, and game CSS are not needed for first paint.
 * Switching them to media="print" with an onload swap to "all" lets the
 * browser download them without blocking rendering.
 *
 * Core CSS (zdz-fonts, zdz-style, zdz-app-css) is LEFT as normal
 * render-blocking. NitroPack (Strong mode) handles CSS optimization for
 * the authenticated SPA — it extracts critical above-the-fold CSS, inlines
 * it, and defers the rest. This is smarter than blanket deferral because
 * NitroPack knows which CSS rules are actually needed above the fold from
 * its page analysis. We only defer secondary/plugin CSS that NitroPack
 * might not recognise as non-critical.
 *
 * App CSS (Messaging zim-*, Game zg-*, Estimates zest-*, Leads zl-*,
 * Invoices zic-*) is also deferred via prefix match: these apps' styles are
 * only needed when their specific sub-view is active, well after first paint.
 */
add_filter( 'style_loader_tag', function( $tag, $handle ) {
	// Core theme CSS — let NitroPack handle these
	$nitro_handles = [ 'zdz-fonts', 'zdz-style', 'zdz-app-css' ];
	if ( in_array( $handle, $nitro_handles, true ) ) {
		return $tag; // NitroPack will optimize these
	}

	// Secondary CSS — defer ourselves (NitroPack may not know these are non-critical)
	$lazy_handles = [
		'zdz-bug-reporter-css',
		'zdz-dashboard-personalization-css',
		'zg-game-css',
	];
	// Also catch app CSS by prefix (see the docblock above).
	$is_lazy = in_array( $handle, $lazy_handles, true )
		|| preg_match( '/^(zim-|zg-|zest-|zl-|zic-)/', $handle );
	if ( $is_lazy ) {
		// Swap to print → all on load (Filament Group pattern)
		$tag = str_replace(
			"media='all'",
			"media='print' onload=\"this.media='all'\"",
			$tag
		);
		// Also handle the alternate quoting WP sometimes uses
		$tag = str_replace(
			'media="all"',
			'media="print" onload="this.media=\'all\'"',
			$tag
		);
		// noscript fallback
		$noscript = str_replace(
			[ "media='print' onload=\"this.media='all'\"",
			  'media="print" onload="this.media=\'all\'"' ],
			"media='all'",
			$tag
		);
		$tag .= '<noscript>' . $noscript . '</noscript>';
	}
	return $tag;
}, 10, 2 );

// Access Control
add_action( 'template_redirect', function() {
	// Skip for API, AJAX, CLI, and cron requests.
	if ( wp_is_json_request() || wp_doing_ajax() ) {
		return;
	}
	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}

	// Allow health-check and ACME/SSL-verification requests through.
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
	if ( strpos( $request_uri, '/.well-known/' ) !== false ) {
		return;
	}

	// Let login and register pages pass through normally.
	if ( is_page( 'login' ) || is_page( 'register' ) ) {
		return;
	}

	$path = trim( parse_url( $request_uri, PHP_URL_PATH ), '/' );
	if ( $path === 'login' || $path === 'register' ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		// An in-app page opened signed out (a vault document, say) is remembered, so signing in
		// comes back to it. ZDZ_Login_Next keeps only an allow-listed, same-site path.
		if ( class_exists( 'ZDZ_Login_Next' ) ) {
			ZDZ_Login_Next::remember( (string) parse_url( $request_uri, PHP_URL_PATH ) );
		}
		// Serve the login page directly with HTTP 200 instead of redirecting (HTTP 302).
		// Redirects block WP Engine SSL certificate provisioning because the
		// verification system cannot follow redirect chains to validate the domain.
		status_header( 200 );
		include get_theme_file_path( 'page-login.php' );
		exit;
	}
} );

add_filter( 'login_url', function( $login_url, $redirect, $force_reauth ) {
	// Only use the custom login page if it exists and is published.
	$login_page = get_page_by_path( 'login' );
	if ( ! $login_page || 'publish' !== $login_page->post_status ) {
		return $login_url; // Fall back to default wp-login.php.
	}

	// Return the clean login URL without redirect_to in the query string.
	// The login form's hidden field already handles post-login redirect to home_url('/').
	// Passing redirect_to here causes infinite nesting when multiple redirects interact.
	return home_url( '/login/' );
}, 10, 3 );

// Hide WP admin bar on the frontend — this is an app, not a dashboard.
add_filter( 'show_admin_bar', '__return_false' );

// Audit: track login/logout events
add_action( 'wp_login', [ 'ZDZ_Admin_Dashboard', 'track_login' ], 10, 2 );
add_action( 'wp_logout', [ 'ZDZ_Admin_Dashboard', 'track_logout' ] );

// Customizer: Light & Dark Logo uploads
add_action( 'customize_register', function( $wp_customize ) {
	$wp_customize->add_section( 'zdz_logos', [
		'title'    => __( 'Zorderz Logos', 'zorderz' ),
		'priority' => 30,
	] );

	// Logo for light backgrounds (dark text version)
	$wp_customize->add_setting( 'zdz_logo_light', [
		'default'           => '',
		'sanitize_callback' => 'esc_url_raw',
	] );
	$wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, 'zdz_logo_light', [
		'label'       => __( 'Logo — Light Background', 'zorderz' ),
		'description' => __( 'Dark-text logo used on white/light surfaces (login card, light theme topbar).', 'zorderz' ),
		'section'     => 'zdz_logos',
	] ) );

	// Logo for dark backgrounds (light text version)
	$wp_customize->add_setting( 'zdz_logo_dark', [
		'default'           => '',
		'sanitize_callback' => 'esc_url_raw',
	] );
	$wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, 'zdz_logo_dark', [
		'label'       => __( 'Logo — Dark Background', 'zorderz' ),
		'description' => __( 'Light-text logo used on dark surfaces (topbar, dark theme).', 'zorderz' ),
		'section'     => 'zdz_logos',
	] ) );

	// Vertical / square logo for the sidebar nav
	$wp_customize->add_setting( 'zdz_logo_vertical', [
		'default'           => '',
		'sanitize_callback' => 'esc_url_raw',
	] );
	$wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, 'zdz_logo_vertical', [
		'label'       => __( 'Logo — Vertical / Square (Sidebar)', 'zorderz' ),
		'description' => __( 'Portrait or 1:1 logo for the sidebar navigation. Recommended: T on top, company name below. Used on tablet/desktop where the nav is a vertical sidebar. Falls back to the standard logo if not set.', 'zorderz' ),
		'section'     => 'zdz_logos',
	] ) );
} );

// Theme Activation
add_action( 'after_switch_theme', function() {
	flush_rewrite_rules();

	// Create custom roles
	ZDZ_User_Roles::activate();

	// Create default pages if missing
	$pages = [
		'login'    => 'Login',
		'register' => 'Register',
		'terms'    => 'Terms & Conditions'
	];

	foreach ( $pages as $slug => $title ) {
		if ( ! get_page_by_path( $slug ) ) {
			wp_insert_post( [
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_status'  => 'publish',
				'post_type'    => 'page',
			] );
		}
	}

	// Wire the Terms page to WooCommerce if WooCommerce is active
	if ( class_exists( 'WooCommerce' ) ) {
		$terms_page = get_page_by_path( 'terms' );
		if ( $terms_page ) {
			update_option( 'woocommerce_terms_page_id', $terms_page->ID );
		}
	}
} );


/* ── Fresh-nonce endpoint for widget keepalive ──
 * Installed PWAs hold their auth cookie for days, but WP nonces expire in
 * 12–24h — the root of the "mysteriously logged out / quietly stopped
 * saving" class. Widgets (the Scheduler; Analytics has its own twin) call
 * this on a 6h timer + on foreground to keep a live REST nonce. Cookie-auth
 * only; minting a nonce for the logged-in user is exactly what a page load
 * does, so this exposes nothing new. */
$zdz_fresh_nonce = function () {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( 'not_logged_in', 401 );
	}
	wp_send_json_success( array(
		'rest' => wp_create_nonce( 'wp_rest' ),
		// Whether THIS device is in Demo Mode, so the PIN sheet can tell the truth after a failed request (the
		// nonce above is already for whichever identity the device now runs as).
		'demo' => class_exists( 'ZDZ_Kiosk_Demo' ) && ZDZ_Kiosk_Demo::is_active(),
	) );
};
add_action( 'wp_ajax_zdz_fresh_nonce', $zdz_fresh_nonce );

/* ── Session hygiene ──
 * 1. A password reset kills every OTHER session for that user — a stolen
 *    or shared login can be evicted by resetting the password.
 * 2. Admin-capable accounts get a capped "remember me" (3 days instead of
 *    14) — the roles that can see pay data shouldn't ride month-old
 *    cookies on a lost phone. Field/kiosk roles keep the long cookie
 *    (convenience is the point on the shop iPad).
 * NOTE: a managed host may set its own auth cookie (for example wpe-auth on
 * WP Engine). It is platform-managed: never build logic on it. */
add_action( 'after_password_reset', function ( $user ) {
	if ( $user instanceof WP_User ) {
		$manager = WP_Session_Tokens::get_instance( $user->ID );
		$manager->destroy_all(); // includes the resetting device — they just got a new password; one fresh login is the safe trade.
		error_log( 'Zorderz: destroyed all sessions for user #' . $user->ID . ' after password reset.' );
	}
}, 10, 1 );

add_filter( 'auth_cookie_expiration', function ( $seconds, $user_id, $remember ) {
	if ( $remember && user_can( $user_id, 'manage_options' ) ) {
		return min( $seconds, 3 * DAY_IN_SECONDS );
	}
	return $seconds;
}, 10, 3 );

/**
 * The customer-link shapes the kit's paper may copy (regex sources). The bundled billing connector
 * contributes its own; another connector adds its shape on the `zdz_customer_link_patterns` filter.
 * Nothing matching: the paper offers no copy at all.
 */
function zdz_customer_link_patterns(): array {
	$p = apply_filters( 'zdz_customer_link_patterns', [ '^https://([a-z0-9-]+\\.)*freshbooks\\.com/#/link/[A-Za-z0-9._~-]+$' ] );
	return array_values( array_filter( (array) $p, 'is_string' ) );
}

/**
 * The kit of parts also loads on wp-admin pages opened inside the app (?zdz_mobile=1), so an app's full
 * view and its home-screen widget share one set of parts. There is no zdzData there; the kit gets the few
 * values it reads.
 */
add_action( 'admin_enqueue_scripts', function ( $hook_suffix = '' ) {
	// Also on Zorderz → User Management, which draws the person badges with the kit.
	if ( empty( $_GET['zdz_mobile'] ) && 'zorderz_page_zdz-user-management' !== $hook_suffix ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only
		return;
	}
	$dir = get_template_directory();
	$uri = get_template_directory_uri();
	$ver = function ( $rel ) use ( $dir ) {
		$m = @filemtime( $dir . $rel );
		return ZDZ_THEME_VER_FLOOR . ( $m ? '.' . $m : '' );
	};
	wp_enqueue_style( 'zdz-kit-css', $uri . '/assets/css/zdz-kit.css', [], $ver( '/assets/css/zdz-kit.css' ) );
	if ( class_exists( 'ZDZ_App_Groups' ) ) {
		wp_add_inline_style( 'zdz-kit-css', ZDZ_App_Groups::css_vars() );
	}
	wp_enqueue_script( 'zdz-kit-js', $uri . '/assets/js/zdz-kit.js', [], $ver( '/assets/js/zdz-kit.js' ), true );
	$kit = [
		'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
		'apiUrl'               => esc_url_raw( rest_url( ZDZ_REST_NS . '/' ) ),
		'nonce'                => wp_create_nonce( 'wp_rest' ),
		'appGroups'            => class_exists( 'ZDZ_App_Groups' ) ? ZDZ_App_Groups::for_js() : [],
		'people'               => ( class_exists( 'ZDZ_Person_Color' ) && is_user_logged_in() ) ? ZDZ_Person_Color::for_js() : [],
		'customerLinkPatterns' => zdz_customer_link_patterns(),
	];
	wp_add_inline_script( 'zdz-kit-js', 'window.zdzData=Object.assign(' . wp_json_encode( $kit ) . ',window.zdzData||{});', 'before' );
} );

/**
 * ── Business Profile: dynamic PWA manifest ────────────────────────────
 * The manifest was a static file with a fixed name and colours that ignored
 * the profile, and a path to a theme folder that no longer existed. It is now generated from the
 * Business Profile and served at /zdz-manifest.json (extension-less routes are
 * unreliable on some managed hosts, so this one keeps its extension).
 */
add_action( 'init', function () {
	add_rewrite_rule( '^zdz-manifest\\.json$', 'index.php?zdz_manifest=1', 'top' );
} );
add_filter( 'query_vars', function ( $vars ) { $vars[] = 'zdz_manifest'; return $vars; } );
add_action( 'template_redirect', function () {
	if ( ! get_query_var( 'zdz_manifest' ) ) {
		return;
	}
	nocache_headers();
	header( 'Content-Type: application/manifest+json; charset=utf-8' );
	echo wp_json_encode( ZDZ_Business_Profile::manifest() );
	exit;
} );
