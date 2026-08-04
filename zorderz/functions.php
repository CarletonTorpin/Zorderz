<?php

/**
 * THE ONE version floor. Keep in lock-step with style.css.
 *
 * v1.1.0 — maximal port: advances the distribution onto the current internal
 * source (theme 2.38.1). New this release: the Party roster service (ZDZ_Party)
 * and the Connected Calendars card in Settings -> App Authorizations (via the
 * zdz_app_authorizations filter). Keep this constant in lock-step with style.css.
 *
 * v1.0.1 — RENUMBERED. This theme had been carrying `2.37.1`, which is the
 * version lineage of the single private app Zorderz was extracted from. Shipping
 * that number on a first public release is misleading twice over: it overstates
 * how long Zorderz has existed, and it hides that this is a new line rather than
 * a continuation. Zorderz versions start at 1.0.0.
 *
 * The update check compares this string for INEQUALITY, not ordering, so going
 * "backwards" is mechanically safe — it fires the reload prompt once, which is
 * exactly right. The one visible consequence is that an install upgrading from
 * the private app will show a smaller number than it did before.
 *
 * v2.31.0 — the original note, kept because the bug it records is instructive:
 * wp_get_theme()->get('Version') returns '' under WPE's early-callback stacking,
 * so several spots carry an explicit fallback. There were THREE hand-maintained
 * copies; one release bumped two and missed the footer ping's — which made the
 * page report one version against the beacon's other and showed a PERMANENT
 * "App update ready" toast. One constant now feeds all of them. wp_get_theme()->get('Version') returns ''
 * under WPE's early-callback stacking (the v2.24.8 saga), so several spots
 * carry an explicit fallback. There were THREE hand-maintained copies; the
 * 2.30.0 release bumped two and missed the footer ping's — which made the
 * page report bootVer 2.29.1 against the beacon's 2.30.0 and showed a
 * PERMANENT "App update ready" toast (live finding, Jul 3 test pass).
 * One constant now feeds all of them. Keep in lock-step with style.css.
 */
if ( ! defined( 'ZDZ_THEME_VER_FLOOR' ) ) {
	define( 'ZDZ_THEME_VER_FLOOR', '1.4.2' );
}
/**
 * The REST namespace, in exactly one place.
 *
 * WHY A CONSTANT AND NOT A LITERAL
 * The Z-rename moved every register_rest_route() to `zorderz/v1` but MISSED the
 * four rest_url() calls that hand the base URL to the browser, so the entire
 * front end went on requesting `ts/v1/` against a server that no longer answered
 * there. Nothing errored server-side — every call just 404'd, which looked like
 * "the photo upload is broken" and "KPI fetch failed" rather than like a rename
 * defect. A literal that has to agree with itself in two dozen files will
 * eventually disagree; a constant cannot.
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
 * v2.25.4: Silence the WooCommerce "translation triggered too early" notice.
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

// v2.20.4: Force display_errors off for AJAX requests.
// PHP Warnings/Notices output to the response body corrupt JSON and
// silently break frontend AJAX chains (discovered in Surveys v2.9.3
// batch creation failure — a single "Undefined array key" Warning
// killed the entire multi-step batch process). Errors still go to
// debug.log via WP_DEBUG_LOG; this only prevents screen output.
if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
	@ini_set( 'display_errors', '0' );
}

// Load all inc/ class files explicitly
require_once get_template_directory() . '/inc/interface-zdz-app.php';
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
require_once get_template_directory() . '/inc/class-zdz-doc-conventions.php'; // v1.1.0: Document Conventions (ZDZ_Doc_Conventions) — tenant house style applied ON OUTPUT (BID-9). Ships neutral; self-boots.
require_once get_template_directory() . '/inc/class-zdz-compensation.php'; // v1.1.0: Compensation Core service (ZDZ_Compensation) — commission structures/tiers, split policies, piece rates, product minimums, ledger kinds, card-fee handling, pay calendar, payability gate, attribution precedence. Ships EMPTY. Self-boots.
require_once get_template_directory() . '/inc/class-zdz-answer-authority.php'; // v1.1.0: Answer Authority — confidence tier (confirmed>derived>inferred>unknown, propagates through arithmetic) + the SINGLE outbound gate (chat/email/push/digest/stream) enforcing INV-12. Ships neutral; self-boots.
require_once get_template_directory() . '/inc/class-zdz-rule-governance.php'; // v1.1.0: Rule Governance — rules as typed parameterised objects; the prompt is a rendering of the rule set. A cited rule must exist (fails loudly); the safety floor is non-overridable. Self-boots.
require_once get_template_directory() . '/inc/class-zdz-model-registry.php'; // v1.1.0: Model Registry — per-task model slots replacing hardcoded model names; capability/fallback/retired maps ship EMPTY; base model read from ZDZ_Core_Settings; Poe stays the v1 gateway. Self-boots.

require_once get_template_directory() . '/inc/class-zdz-core-poe.php';
require_once get_template_directory() . '/inc/class-zdz-core-freshbooks.php';
require_once get_template_directory() . '/inc/class-zdz-token-service.php'; // v1.1.0: Connections credential authority — provider-agnostic single-flight OAuth refresher (ZDZ_Token_Service). Self-boots; registers the bundled FreshBooks provider via zdz_token_providers. Consumed by ZDZ_Core_FreshBooks::refresh_token().
require_once get_template_directory() . '/inc/class-zdz-core-nutshell.php';
require_once get_template_directory() . '/inc/class-zdz-core-review-bridge.php'; // v2.14.5
require_once get_template_directory() . '/inc/class-zdz-contact-bridge.php'; // v2.21.4: orchestrator contact-lookup capability ([ZDZ_CONTACT])
require_once get_template_directory() . '/inc/class-zdz-orchestrator.php'; // v2.22.0: deterministic Poe-free dashboard intent classifier (/zorderz/v1/orchestrate)
require_once get_template_directory() . '/inc/class-zdz-plugin-api.php';
require_once get_template_directory() . '/inc/class-zdz-share-link.php'; // v2.35.0: reusable secret share-link primitives (ZDZ_Share_Link)
require_once get_template_directory() . '/inc/class-zdz-rest-api.php';
require_once get_template_directory() . '/inc/class-zdz-user-roles.php';

// ── Zorderz transition: rename migration ──────────────────────────────
// Carries a pre-Zorderz install onto the new identifier scheme (options,
// user meta, roles, capabilities, tables, cron). Idempotent and guarded by
// a stored version; a no-op on a fresh install. Must load before anything
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
require_once get_template_directory() . '/inc/class-zdz-kiosk-demo.php'; // v2.21.0: PIN-gated kiosk/demo identity switch
require_once get_template_directory() . '/inc/class-zdz-kpi-metrics.php';
require_once get_template_directory() . '/inc/class-zdz-data-permissions.php'; // v2.17.0: Cross-plugin data permission resolution
require_once get_template_directory() . '/inc/class-zdz-hierarchy.php'; // v2.32.0: Crew Lead hierarchy (ZDZ_Hierarchy)
require_once get_template_directory() . '/inc/class-zdz-party.php'; // v1.1.0: authoritative "selectable people" roster (ZDZ_Party) — first shape of the Party core service
require_once get_template_directory() . '/inc/class-zdz-integration-tests.php'; // v2.17.0 7B: Integration health check panel

// ── v2.13.0 Backend infrastructure (no frontend changes) ──────────────
// Adds ZDZ_User_Goals + ZDZ_Personal_Records classes and runs their
// one-time DB migration. Purely additive — does NOT modify KPI metric
// collection, cache keys, or any existing render path.
require_once get_template_directory() . '/inc/class-zdz-user-goals.php';
require_once get_template_directory() . '/inc/class-zdz-personal-records.php';
require_once get_template_directory() . '/inc/class-zdz-user-media.php'; // v2.17.1: Shared media management for sketches, photos, documents
require_once get_template_directory() . '/inc/class-zdz-media-geocoder.php';   // EXIF inspector: offline reverse geocoder (privacy-first)
require_once get_template_directory() . '/inc/class-zdz-media-exif.php';       // EXIF inspector: report builder
require_once get_template_directory() . '/inc/class-zdz-media-exif-rest.php';  // EXIF inspector: GET /zorderz/v1/media/{id}/exif
require_once get_template_directory() . '/inc/class-zdz-magic-link-bridge.php'; // v2.18.0: PWA magic login bridge
require_once get_template_directory() . '/inc/class-zdz-alert-router.php'; // v2.19.0: Cross-plugin alert routing + notification delivery
require_once get_template_directory() . '/inc/class-zdz-data-portability.php'; // v1.4.0: Company Data Export / Import (portability, backup, migration) under Tools -> Zorderz Data
require_once get_template_directory() . '/db/migrate-2.13.0.php';
require_once get_template_directory() . '/db/migrate-2.17.1.php'; // v2.17.1: zdz_user_media table
require_once get_template_directory() . '/db/migrate-alert-router.php'; // v2.19.0: zdz_notifications table
require_once get_template_directory() . '/db/migrate-2.20.3.php'; // v2.20.3: clean deprecated zdz_pr_* user meta
require_once get_template_directory() . '/db/migrate-2.20.4.php'; // v2.20.4: zdz_user_media EXIF columns (captured_at, gps_lat, gps_lng)

// v2.18.0: Initialize PWA Magic Link Bridge (registers REST routes + login_redirect filter)
ZDZ_Magic_Link_Bridge::get_instance();

// Initialize View-As role switcher (registers hooks before 'init' fires)
ZDZ_View_As::init();

// v1.4.0: Company Data Export / Import (admin_menu + admin_post handlers)
ZDZ_Data_Portability::init();

// Initialize Singleton Classes
add_action( 'init', function() {
	ZDZ_Core_Settings::get_instance();
	ZDZ_Plugin_API::get_instance();
	ZDZ_Rest_API::get_instance();
	ZDZ_Admin_Dashboard::get_instance();
	ZDZ_KPI_Metrics::get_instance();
	ZDZ_Alert_Router::get_instance(); // v2.19.0: Alert routing + notification delivery
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

/* ── v2.15.0: Strip WordPress core bloat ──
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
	// ── v2.24.8 FIX: $version was resolving to EMPTY on the live site. ──
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

	// ── v2.21.6 (hardened v2.24.8): Content-based cache-busting for the theme's
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

	// ── v2.15.0: Performance — self-host Inter fonts and Lucide icons.
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

	// ── v2.15.0: Resource preloading is handled by NitroPack ──
	// NitroPack analyzes real page loads and generates optimal preload
	// hints automatically. Manual preloads would duplicate its work.

	wp_enqueue_script( 'zdz-app-js', get_template_directory_uri() . '/assets/js/app.js', ['zdz-lucide'], $asset_ver( '/assets/js/app.js' ), true );
	wp_enqueue_script( 'zdz-bridge-js', get_template_directory_uri() . '/assets/js/bridge.js', ['zdz-app-js'], $asset_ver( '/assets/js/bridge.js' ), true );
	wp_enqueue_script( 'zdz-bug-reporter-js', get_template_directory_uri() . '/assets/js/bug-reporter.js', ['zdz-app-js'], $asset_ver( '/assets/js/bug-reporter.js' ), true );

	// EXIF inspector (v2.21.0): on-demand photo metadata panel. CSS depends on
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

	// v2.13.1: optional personalization layer — drag-reorder KPI cards
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
	$logo_vertical = get_theme_mod( 'zdz_logo_vertical', '' ); // v2.14.4 A5

	// v2.16.0 T12: Logo variant fallbacks — if only one variant is uploaded,
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
		'themeVersion'   => $version,
		'logoutUrl'      => wp_logout_url( home_url( '/login/' ) ),
		'userFirstName'  => $first_name,
		'customLogoUrl'  => $custom_logo_url,
		'logoLight'      => $logo_light,
		'logoDark'       => $logo_dark,
		'logoVertical'   => $logo_vertical,  // v2.14.4 A5
		'reviewBridge'   => ( new ZDZ_Core_ReviewBridge() )->is_configured(), // v2.14.5
		'gameAvailable'  => class_exists( 'TSG_App' ) && defined( 'TSG_PLUGIN_URL' ), // v2.14.6: TS Game embed flag — lets app.js/bridge.js offer the game during chat wait times without DOM sniffing
		'dataPermissions' => class_exists( 'ZDZ_Data_Permissions' ) ? ZDZ_Data_Permissions::resolve( $user_id ) : [], // v2.17.0: Cross-plugin data permissions for frontend gating
		// v2.21.0: Kiosk / Demo mode flags. These are computed against the
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
	];

	wp_localize_script( 'zdz-app-js', 'zdzData', $zdz_data );
} );

/* ── v2.18.0: Minimal Service Worker for PWA login bridge ──
 * NitroPack still handles all caching (CDN, browser cache headers). The SW
 * is re-enabled with a minimal scope: it ONLY handles the virtual
 * /_ts-bridge-token endpoint used to pass auth tokens from Safari to the
 * standalone PWA via CacheStorage. No caching logic, no NitroPack conflict.
 *
 * The SW must be registered for ALL users (including logged-out) because
 * the bridge operates during the login flow.
 *
 * Virtual /sw.js endpoint: serves the theme's sw.js from root scope.
 */
add_action( 'template_redirect', function () {
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
	$path        = trim( parse_url( $request_uri, PHP_URL_PATH ), '/' );

	// v2.23.1: the virtual /sw.js route NEVER worked in production — WP Engine's
	// nginx serves *.js paths statically and 404s a .js URL that has no physical
	// file, without ever consulting WordPress (verified live: GET /sw.js →
	// nginx 404). So navigator.serviceWorker.register('/sw.js') has failed
	// silently on every device since 2.18.0 — no PWA bridge SW, no camera
	// Background-Sync drain, and (the real cost) no service worker available
	// to keep app shells fresh. The SW is therefore ALSO served at the
	// extension-less path /zdz-sw, which nginx passes through to WordPress
	// like any permalink. Registration (below) now points there. /sw.js is
	// kept for non-WPE environments where it already worked.
	if ( 'sw.js' !== $path && 'zdz-sw' !== $path ) {
		return;
	}

	$sw_file = get_template_directory() . '/sw.js';
	if ( ! file_exists( $sw_file ) ) {
		status_header( 404 );
		exit;
	}

	// v2.29.1 ROOT CAUSE FIX: WordPress marks this unknown path 404 in the
	// main query (handle_404) BEFORE template_redirect fires, so this route
	// has been serving the sw.js BODY with a **404 status line** since it was
	// created in v2.23.1 — and browsers REFUSE to register a service worker
	// from a non-200 response. Net effect: the theme SW never actually
	// registered on any device (verified live Jul 3 2026: GET /zdz-sw →
	// status 404 + full v2.29.0 body), the analytics app's /tsa-sw (which does send 200)
	// took the root scope, and the v2.22.0 network-first shell + v2.24.2
	// self-heal flows never ran anywhere. status_header(200) closes it.
	status_header( 200 );
	header( 'Content-Type: application/javascript; charset=utf-8' );
	header( 'Service-Worker-Allowed: /' );
	header( 'Cache-Control: no-cache, no-store, must-revalidate' );
	header( 'X-Robots-Tag: none' );

	readfile( $sw_file );
	exit;
}, 1 ); // Priority 1: run before access control

/**
 * v2.29.0: /zdz-version — tiny JSON version beacon for the installed-PWA
 * freshness cycle (Workstream F, Deploy & Update Runbook). The SW update
 * check compares sw.js bytes; this beacon catches everything the byte-diff
 * can't see (the inline registration script, theme + component versions)
 * for PWAs that stay open for days without a navigation. Extension-less for
 * the same WP Engine nginx reason as /zdz-sw; no-store so no cache layer
 * (WPE page cache, NitroPack, browser HTTP cache) can ever pin it.
 */
add_action( 'template_redirect', function () {
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
	$path        = trim( parse_url( $request_uri, PHP_URL_PATH ), '/' );
	if ( 'zdz-version' !== $path ) {
		return;
	}

	// Same empty-version hardening as the enqueue callback (v2.24.8 saga).
	$theme_ver = wp_get_theme()->get( 'Version' );
	if ( ! is_string( $theme_ver ) || $theme_ver === '' ) {
		$theme_ver = ZDZ_THEME_VER_FLOOR; // single source — see the constant's docblock
	}

	$out = array( 'theme' => (string) $theme_ver );
	foreach ( array(
		'tsa'   => 'TSA_VERSION',
		'tsec'  => 'TSEC_VERSION',
		'tscc'  => 'TSCC_VERSION',
		'tssch' => 'TSSCH_VERSION',
		'tsim'  => 'ZIM_VERSION',
	) as $key => $const ) {
		if ( defined( $const ) ) {
			$out[ $key ] = (string) constant( $const );
		}
	}

	status_header( 200 ); // v2.29.1: WP pre-marks unknown paths 404 (see /zdz-sw note)
	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	header( 'X-Robots-Tag: none' );
	echo wp_json_encode( $out );
	exit;
}, 1 );

// SW registration — runs for all users (logged-in and logged-out)
// v2.23.1: registers /zdz-sw (extension-less; see the route handler above for
// why /sw.js can never be served on WP Engine). Default scope for a root-level
// script URL is '/', and the route also sends Service-Worker-Allowed: /.
add_action( 'wp_footer', function () {
	// Same empty-version hardening as the enqueue callback (v2.24.8 saga).
	$zdz_boot_ver = wp_get_theme()->get( 'Version' );
	if ( ! is_string( $zdz_boot_ver ) || $zdz_boot_ver === '' ) {
		$zdz_boot_ver = ZDZ_THEME_VER_FLOOR; // v2.31.0: THIS was the missed third copy — the permanent-toast bug
	}
	// v2.29.1: theme-SW registration is FLAG-GATED (default off). Live testing
	// (Jul 3) proved the theme SW has never registered (the 404-status bug fixed
	// above), and the analytics app's /tsa-sw currently owns scope '/' carrying its push
	// subscriptions. Flipping registration on now would make the two workers
	// REPLACE each other on alternating page loads (feature flap: network-first
	// shell vs push). The Phase-1 E-addendum merges them (theme SW absorbs push,
	// TSA defers); until then set option zdz_sw_register='on' only for testing.
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

		// ── v2.29.1 FRESHNESS CYCLE — SW-INDEPENDENT ────────────────────
		// v2.29.0 nested this inside the SW registration .then(); since
		// registration was silently failing (404-status bug), the whole
		// freshness cycle was parked. Now the /zdz-version poll + update pill
		// run unconditionally; the SW update() ride-along happens only when a
		// registration exists (window.__tsReg).
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
			if (window.__tsReg) { try { window.__tsReg.update(); } catch (e) {} }
			zdzVersionPing();
		}
		document.addEventListener('visibilitychange', function () { zdzFreshnessCheck(false); });
		setInterval(function () { zdzFreshnessCheck(true); }, 60 * 60 * 1000);
		setTimeout(zdzVersionPing, 20000); // first ping shortly after boot

		// ── SW registration (gated; see PHP note above) ─────────────────
		var SW_ON = <?php echo $zdz_sw_on ? 'true' : 'false'; ?>;
		if (SW_ON && 'serviceWorker' in navigator) {
		navigator.serviceWorker.register('/zdz-sw', { scope: '/', updateViaCache: 'none' }).then(function (reg) {
			window.__tsReg = reg;
			try {
				// ── v2.29.0 UPDATE FLOW (replaces the v2.24.2 always-auto-apply) ──
				// v2.24.2 skipWaiting-and-reloaded the moment ANY update installed.
				// At launch that is the right self-heal — the user hasn't started
				// anything yet. MID-SESSION it is exactly wrong: a deploy while a
				// rep is halfway through dictating an estimate force-reloaded the
				// page under them (research pass, Jul 2026: never yank working
				// users; offer a consented refresh — no blanket skipWaiting).
				// So: updates found within LAUNCH_MS of boot auto-apply silently
				// (v2.24.2 behavior preserved where it was right); updates found
				// later show a small "App update ready — Refresh" pill and wait.
				var bootT = Date.now();
				var LAUNCH_MS = 15000;

				function zdzApplyUpdate(nw) {
					try { nw.postMessage({ type: 'zdz-skip-waiting' }); } catch (e) {}
				}


				function zdzOnUpdateReady(nw) {
					if (Date.now() - bootT < LAUNCH_MS) {
						zdzApplyUpdate(nw); // launch-window self-heal (v2.24.2 behavior)
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

/* ── v2.23.1: Logged-in HTML must never be cached by the device ──
 * The TS Camera nonce saga (Jun 2026) proved devices reuse a CACHED dashboard
 * shell for days: the iOS home-screen PWA kept serving its locally cached
 * HTML — with a long-stale baked nonce and old ?ver= script URLs — so plugin
 * updates and fresh nonces never reached the device (server-side purges
 * can't touch a device's own HTTP cache). Send explicit no-cache headers on
 * logged-in front-end page loads so browsers/PWAs revalidate the shell on
 * every launch. Anonymous traffic is untouched — NitroPack keeps caching it
 * exactly as before. (Belt & braces: the 2.22.0 service worker also fetches
 * navigations network-first with an HTTP-cache bypass; this header makes the
 * non-SW first load correct too.)
 */
add_action( 'send_headers', function () {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
		nocache_headers();
	}
} );

/* ── v2.14.5: Performance — defer non-critical JS ──
 * Bug reporter and dashboard personalization are interactive features that
 * don't need to block initial render. Adding `defer` lets the browser parse
 * and paint the page before downloading/executing these scripts. The core
 * app.js and bridge.js stay synchronous (in-footer) since the SPA depends
 * on them for initial routing.
 *
 * v2.14.6: Added tsg-game-js — the block-breaker game is purely interactive
 * and never needed for initial render (either as a dashboard tile or TSA
 * chat embed). The game plugin enqueues its own script; we just defer it.
 */
add_filter( 'script_loader_tag', function( $tag, $handle ) {
	$defer_handles = [
		'zdz-bug-reporter-js',
		'zdz-dashboard-personalization-js',
		'tsg-game-js',  // v2.14.6: TS Game block-breaker
	];
	if ( in_array( $handle, $defer_handles, true ) && false === strpos( $tag, 'defer' ) ) {
		$tag = str_replace( ' src=', ' defer src=', $tag );
	}

	// ── v2.15.0: NitroPack Strong mode — protect SPA boot chain ──
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

/* ── v2.14.5: Performance — non-render-blocking CSS for secondary sheets ──
 * Bug reporter, personalization, and game CSS are not needed for first paint.
 * Switching them to media="print" with an onload swap to "all" lets the
 * browser download them without blocking rendering.
 *
 * v2.15.0: Core CSS (zdz-fonts, zdz-style, zdz-app-css) is LEFT as normal
 * render-blocking. NitroPack (Strong mode) handles CSS optimization for
 * the authenticated SPA — it extracts critical above-the-fold CSS, inlines
 * it, and defers the rest. This is smarter than blanket deferral because
 * NitroPack knows which CSS rules are actually needed above the fold from
 * its page analysis. We only defer secondary/plugin CSS that NitroPack
 * might not recognise as non-critical.
 *
 * TS plugin CSS (tsa-*, zim-*, etc.) is also deferred via prefix match —
 * these plugins' styles are only needed when their specific sub-view is
 * active, well after first paint.
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
		'tsg-game-css',
	];
	// Also catch TS plugin CSS by prefix (tsa-*, zim-*, tsg-*, tsec-*, etc.)
	$is_lazy = in_array( $handle, $lazy_handles, true )
		|| preg_match( '/^(tsa-|zim-|tsg-|tsec-|tss-|tsl-|tsic-)/', $handle );
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

	// v2.14.4 A5: Vertical / square logo for the sidebar nav
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


/**
 * v2.14.0: Front-end OAuth bounce.
 *
 * ROOT CAUSE (first reported in v2.14.0, hot-fixed here in v2.14.0):
 *   TSEC_Admin::handle_oauth_callback runs on admin_init. It calls
 *     wp_redirect( admin_url( 'admin.php?page=tsec-settings&connected=1' ) )
 *   on success, or ...&error=oauth_failed on failure — which drops the
 *   user in the WordPress backend. Non-admin TS users shouldn't see
 *   wp-admin at all, and even admins shouldn't be deposited there after a
 *   front-end authorize action.
 *
 *   v2.14.0 tried to gate this on the INCOMING request URL (looking for
 *   ?page=tsec or tsec_oauth=1). That never matched — inspecting the
 *   shipped TSEC plugin proves its redirect URI is the CLEAN
 *   site_url('/wp-admin/admin.php') with no query params, so neither
 *   marker is ever present on the callback request. The filter always
 *   short-circuited and the user ended up on wp-admin anyway.
 *
 * FIX: Gate on the OUTGOING $location — the URL TSEC is trying to
 * wp_redirect() to. TSEC's handle_oauth_callback only ever redirects to
 * one of two URLs after completing the token exchange:
 *     admin.php?page=tsec-settings&connected=1        (success)
 *     admin.php?page=tsec-settings&error=oauth_failed (failure)
 * Those patterns are exclusive to the post-OAuth handler, so matching them
 * is safe. The origin transient (zdz_fb_auth_origin_<uid>, set in
 * handle_fb_auth_start) is still consulted so admins who launched the
 * authorize flow from wp-admin itself are NOT bounced — their existing
 * re-authorize flow continues to land on the wp-admin settings page.
 *
 * Flow end-to-end:
 *   1. Front-end [Authorize FreshBooks] button calls /zorderz/v1/fb-auth-start,
 *      which sets zdz_fb_auth_origin_<uid> = 'frontend' (10 min) and
 *      returns the FreshBooks authorize URL.
 *   2. User is redirected to FreshBooks, approves, FB redirects to
 *      wp-admin/admin.php?code=XXX&state=YYY.
 *   3. TSEC's admin_init handler runs the token exchange, then calls
 *      wp_redirect( admin.php?page=tsec-settings&connected=1 ).
 *   4. THIS filter fires, sees the transient marker, recognises the
 *      outbound URL as TSEC's post-OAuth redirect, consumes the marker,
 *      and rewrites to home_url( '/?zdz_authorized=freshbooks' ) (or
 *      ?zdz_auth_error=... on failure).
 *   5. The existing app.js handler on the homepage picks up the flag,
 *      shows a toast, and switches to the Settings view.
 * No TSEC plugin change required.
 */
add_filter( 'wp_redirect', 'zdz_frontend_oauth_bounce', 1, 2 );
function zdz_frontend_oauth_bounce( $location, $status ) {
	if ( ! is_user_logged_in() ) {
		return $location;
	}

	// Match on the OUTGOING redirect target, not the incoming request URL.
	// TSEC builds these two exclusively in its post-OAuth handler.
	$loc_has_tsec = ( strpos( $location, 'page=tsec-settings' ) !== false );
	$is_success   = $loc_has_tsec && ( strpos( $location, 'connected=1' )      !== false );
	$is_error     = $loc_has_tsec && ( strpos( $location, 'error=oauth_failed' ) !== false );
	if ( ! $is_success && ! $is_error ) {
		return $location;
	}

	// Only rewrite if this user started the flow from the front-end. If the
	// transient isn't set, the user launched authorize from wp-admin and
	// we leave TSEC's redirect alone.
	$uid = get_current_user_id();
	$key = 'zdz_fb_auth_origin_' . $uid;
	if ( get_transient( $key ) !== 'frontend' ) {
		return $location;
	}
	delete_transient( $key );

	if ( $is_error ) {
		// TSEC stashes the detail message in its own transient — surface
		// whatever's there to the user so they don't see a bare "failed".
		$detail  = get_transient( 'tsec_oauth_error' );
		$err_val = $detail ? (string) $detail : 'oauth_failed';
		return home_url( '/?zdz_auth_error=' . rawurlencode( $err_val ) );
	}
	return home_url( '/?zdz_authorized=freshbooks' );
}

/**
 * v2.14.3.1: Safety net — if the wp_redirect filter above didn't fire
 * (e.g., TSEC changed its redirect URL pattern, or the redirect was
 * handled differently), catch the user in wp-admin and bounce them home.
 *
 * CRITICAL: Must run AFTER TSEC's handle_oauth_callback (priority 10)
 * so the code exchange completes first. We fire at priority 99.
 * Also must NOT fire when ?code= is present — that means TSEC hasn't
 * processed the callback yet (or failed to redirect), so we let
 * TSEC finish first. We only intercept the POST-exchange redirect
 * (connected=1 or page=tsec-settings).
 */
add_action( 'admin_init', function () {
	if ( ! is_user_logged_in() ) {
		return;
	}

	$uid = get_current_user_id();
	$key = 'zdz_fb_auth_origin_' . $uid;
	if ( get_transient( $key ) !== 'frontend' ) {
		return;
	}

	$request = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';

	// Do NOT intercept when ?code= is still in the URL — that means TSEC's
	// handle_oauth_callback already ran (at priority 10) but its wp_redirect
	// didn't fire or was blocked. If ?code= is present AND we're at priority 99,
	// TSEC already had its chance. But to be safe, only intercept the
	// post-exchange "connected=1" redirect.
	if ( strpos( $request, 'code=' ) !== false ) {
		return; // Let TSEC handle the code exchange
	}

	// Only intercept the TSEC settings page landing (post-exchange redirect
	// that the wp_redirect filter should have caught but didn't).
	$is_post_exchange = ( strpos( $request, 'connected=1' ) !== false )
		|| ( strpos( $request, 'page=tsec-settings' ) !== false );

	if ( ! $is_post_exchange ) {
		return;
	}

	delete_transient( $key );

	$is_error = strpos( $request, 'error=oauth_failed' ) !== false;
	if ( $is_error ) {
		$detail  = get_transient( 'tsec_oauth_error' );
		$err_val = $detail ? (string) $detail : 'oauth_failed';
		wp_safe_redirect( home_url( '/?zdz_auth_error=' . rawurlencode( $err_val ) ) );
	} else {
		wp_safe_redirect( home_url( '/?zdz_authorized=freshbooks' ) );
	}
	exit;
}, 99 );


/* ── PHASE 4 · G2 (v2.30.0): fresh-nonce endpoint for widget keepalive ──
 * Installed PWAs hold their auth cookie for days, but WP nonces expire in
 * 12–24h — the root of the "mysteriously logged out / quietly stopped
 * saving" class. Widgets (scheduler v1.5.1+, TSA has its own twin) call
 * this on a 6h timer + on foreground to keep a live REST nonce. Cookie-auth
 * only; minting a nonce for the logged-in user is exactly what a page load
 * does, so this exposes nothing new. */
add_action( 'wp_ajax_ts_fresh_nonce', function () {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( 'not_logged_in', 401 );
	}
	wp_send_json_success( array(
		'rest' => wp_create_nonce( 'wp_rest' ),
	) );
} );

/* ── PHASE 4 · G5 (v2.30.0): session hygiene ──
 * 1. A password reset kills every OTHER session for that user — a stolen
 *    or shared login can be evicted by resetting the password.
 * 2. Admin-capable accounts get a capped "remember me" (3 days instead of
 *    14) — the roles that can see pay data shouldn't ride month-old
 *    cookies on a lost phone. Field/kiosk roles keep the long cookie
 *    (convenience is the point on the shop iPad).
 * NOTE: the wpe-auth cookie is WP Engine platform-managed — never build
 * logic on it (documented in TS-DEPLOY-RUNBOOK). */
add_action( 'after_password_reset', function ( $user ) {
	if ( $user instanceof WP_User ) {
		$manager = WP_Session_Tokens::get_instance( $user->ID );
		$manager->destroy_all(); // includes the resetting device — they just got a new password; one fresh login is the safe trade.
		error_log( 'TS Theme G5: destroyed all sessions for user #' . $user->ID . ' after password reset.' );
	}
}, 10, 1 );

add_filter( 'auth_cookie_expiration', function ( $seconds, $user_id, $remember ) {
	if ( $remember && user_can( $user_id, 'manage_options' ) ) {
		return min( $seconds, 3 * DAY_IN_SECONDS );
	}
	return $seconds;
}, 10, 3 );

/**
 * ── Business Profile: dynamic PWA manifest ────────────────────────────
 * The manifest was a static file carrying one company's name, colours and a
 * path to a theme folder that no longer existed. It is now generated from the
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
