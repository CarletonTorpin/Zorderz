<?php
/**
 * Module: Zorderz - Charts
 * Description: Ask about sales and get a chart. A thin front end: every figure comes from a
 *   Charts hub over a sealed envelope (the reference hub ships with Zorderz under hub/, and any
 *   service that speaks the same contract will do). This site keeps only saved chart settings,
 *   and, if the business turns it on, dated aggregate snapshots. With no hub connected the app
 *   runs in local mode and says so.
 * Version:     1.0.0
 * Author:      Zorderz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 8.0
 *
 * This is a bundled app module (loaded by zorderz-apps.php), not a standalone plugin. It
 * registers with the theme through the `zdz_register_apps` filter on after_setup_theme and
 * declines cleanly when the theme is absent.
 *
 * ── What lives where ──────────────────────────────────────────────────
 *   includes/class-zch-envelope.php      the sealed-envelope protocol (libsodium crypto_box)
 *   includes/class-zch-config.php        hub address, keys and the live-host lock
 *   includes/class-zch-hub-client.php    the only code that talks to the hub
 *   includes/class-zch-access.php        who may see what (role defaults, per-user overrides)
 *   includes/class-zch-store.php         saved charts, snapshots, the audit log, rate limits
 *   includes/class-zch-rest.php          the browser's only door (ZDZ_REST_NS/charts/*)
 *   includes/class-zch-ledger.php        an invoice card's customer, from the site's billing ledger
 *   includes/class-zch-invoice-doc.php   the invoice as the customer got it, read live
 *   includes/class-zch-feed.php          the hourly invoice feed to the hub
 *   includes/class-zch-counts.php        measured piece counts and lead sources for the hub
 *   includes/class-zch-chat-bridge.php   the registry verb charts.run
 *   includes/class-zch-prospect*.php     where to canvass; the registry verb charts.prospect
 *   includes/class-zch-local.php         the region's address and place data for the canvassing app
 *   includes/class-zch-survey-verb.php   the registry verb charts.survey (recorded drives)
 *   includes/class-zch-territories.php   the territory map, from the Territories app's read verbs
 *   includes/class-zch-admin.php         screens, settings, the health monitor
 *   includes/class-zch-app.php           the dashboard tile (required after the theme)
 *
 * Business vocabulary (products, towns, people, colours) never lives here: the hub's identity
 * pack carries it and the hub sends it with each answer.
 *
 * @package Zorderz\Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ──────────────────────────────────────────────────────
define( 'ZCH_VERSION', '1.0.0' );
define( 'ZCH_DB_VERSION', '1' );
define( 'ZCH_FILE', __FILE__ );
define( 'ZCH_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZCH_URL', plugin_dir_url( __FILE__ ) );

define( 'ZCH_APP_ID', 'charts' );
define( 'ZCH_SLUG', 'zorderz-charts' );
define( 'ZCH_REST_BASE', '/charts' );                 // routes live under ZDZ_REST_NS . ZCH_REST_BASE.
define( 'ZCH_OPTION', 'zch_settings' );
define( 'ZCH_TABLE_SAVED', 'zch_saved' );
define( 'ZCH_TABLE_SNAPSHOT', 'zch_snapshot' );
define( 'ZCH_TABLE_AUDIT', 'zch_audit' );
define( 'ZCH_CRON_HOOK', 'zch_health_check' );

// The envelope's party labels: protocol constants, the same on both sides (hub/zorderz_hub/names.py).
define( 'ZCH_WEB_PARTY', 'zorderz-site' );
define( 'ZCH_HUB_PARTY', 'charts-hub' );

// The map's street tiles: OpenStreetMap's own servers, free with attribution for light use (their
// tile policy). Swap both through the zch_map_tiles filter for another provider.
define( 'ZCH_MAP_TILES', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png' );
define( 'ZCH_MAP_ATTRIBUTION', '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' );

/** Content-keyed asset version: module version + file mtime. */
function zch_asset_ver( $rel ) {
	$mtime = @filemtime( ZCH_DIR . ltrim( (string) $rel, '/' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	return $mtime ? ZCH_VERSION . '.' . $mtime : ZCH_VERSION;
}

// ── Load the plumbing ──────────────────────────────────────────────
require_once ZCH_DIR . 'includes/class-zch-envelope.php';
require_once ZCH_DIR . 'includes/class-zch-config.php';
require_once ZCH_DIR . 'includes/class-zch-hub-client.php';
require_once ZCH_DIR . 'includes/class-zch-access.php';
require_once ZCH_DIR . 'includes/class-zch-ledger.php';
require_once ZCH_DIR . 'includes/class-zch-invoice-doc.php';
require_once ZCH_DIR . 'includes/class-zch-store.php';
require_once ZCH_DIR . 'includes/class-zch-rest.php';
require_once ZCH_DIR . 'includes/class-zch-admin.php';
require_once ZCH_DIR . 'includes/class-zch-counts.php';
require_once ZCH_DIR . 'includes/class-zch-feed.php';
require_once ZCH_DIR . 'includes/class-zch-chat-bridge.php';
require_once ZCH_DIR . 'includes/class-zch-prospect.php';
require_once ZCH_DIR . 'includes/class-zch-local.php';
require_once ZCH_DIR . 'includes/class-zch-prospect-verb.php';
require_once ZCH_DIR . 'includes/class-zch-survey-verb.php';
require_once ZCH_DIR . 'includes/class-zch-territories.php';

// The tile for everyone Charts allows (the platform's ADD-only grant), and the hub's address and keys on
// Zorderz → Credentials (read there first, Charts' own settings as the fallback).
add_filter( 'zdz_extra_allowed_apps', array( 'ZCH_Access', 'extra_allowed' ), 10, 2 );
add_filter( 'zdz_core_credentials_services', array( 'ZCH_Config', 'credentials_service' ) );

/** Activation (called by the apps bundle's activator through the manifest entry). Never seeds business data. */
function zch_activate() {
	ZCH_Store::activate();
}

/** Deactivation: stop the scheduled jobs. */
function zch_deactivate() {
	ZCH_Admin::deactivate();
}

// ── Boot ───────────────────────────────────────────────────────────
add_action(
	'plugins_loaded',
	function () {
		ZCH_Store::maybe_upgrade();        // safe when a zip is overwritten in place.
		ZCH_Rest::init();
		ZCH_Admin::init();
		ZCH_Access::init_admin();
		ZCH_Counts::init();
		ZCH_Feed::init();
	},
	20
);

// ── Dashboard tile (theme interface: after_setup_theme) ─────────────
add_action(
	'after_setup_theme',
	function () {
		if ( ! interface_exists( '\Zorderz\Widget_App_Interface' ) ) {
			return; // a missing or older theme: degrade, never fatal.
		}
		require_once ZCH_DIR . 'includes/class-zch-app.php';
		ZCH_App::init();
	},
	20
);

