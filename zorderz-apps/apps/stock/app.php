<?php
/**
 * Module: Zorderz - Stock (Inventory)
 * Description: OPTIONAL (beta) inventory app for the Zorderz dashboard. Tracks raw-material
 *   and parts stock against an immutable ledger (event sourcing), parses supplier invoices
 *   with the shared AI client, and auto-deducts stock from billed jobs via each catalog item's
 *   Bill-of-Materials (its Item Engine `consumes[]`). Low-stock alerts, cycle counts and usage
 *   forecasting round it out. Ships EMPTY: no catalog, no SKUs, no supplier costs, no seeds.
 * Version:     1.2.0-beta
 * Author:      Zorderz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 7.4
 *
 * This is a bundled app module (loaded by zorderz-apps.php), not a standalone plugin.
 * It registers with the theme through the `zdz_register_apps` filter on after_setup_theme
 * and declines cleanly when the theme is absent.
 *
 * ── Core-clean port (from the internal ts-stock-checker, which shipped ~66 real supplier
 *    SKUs with unit costs + par levels and a hardcoded BOM keyword→SKU map) ───────────────
 *   - THE CATALOG IS THE ITEM ENGINE. This module owns no product taxonomy. What a business
 *     stocks, its SKUs, unit nouns, par/reorder policy and — critically — the BOM (an item's
 *     `consumes[]`) all live in ZDZ_Item_Engine. The seed SKU catalog and the BOM keyword map
 *     are GONE from code; with an empty catalog every resolver degrades to neutral and nothing
 *     breaks. A fictional demo catalog is available only via the Item Engine's own sample
 *     mechanism (Settings → "Apply sample catalog"), never auto-seeded.
 *   - The off-repo "TS-STOCK-BRAIN" bot's baked product-catalog knowledge becomes an in-repo,
 *     placeholder-driven prompt template (defaults/brain-prompt.md) assembled at runtime from
 *     the Business Profile + the live Item Engine catalog, sent through the shared ZDZ_Core_Poe
 *     client. The bot name is a setting (blank ⇒ the platform's default model) — no bot name is
 *     hardcoded.
 *   - Credentials come from ZDZ_Core_Settings (Poe key, FreshBooks); the plugin's private
 *     AES-CBC credential cascade and its own Poe HTTP client are dropped (reuse v1.0.1 core).
 *   - EVERY endpoint gates on REAL app-access (ZDZ_Plugin_API::user_can_access_app), not the
 *     blanket zdz_access_app cap that every role (incl. the shared kiosk) holds — the v1.1.6
 *     source already moved this way; it is preserved.
 *   - Renamed off tssc/TSSC_ → zstock/ZSTOCK_. Tables/options/cron carry deprecated-alias
 *     rename-map entries so an existing install upgrades in place; the item_id columns are
 *     widened by a real guarded migration (see ZSTOCK_DB). No REST routes exist in this module
 *     (admin-ajax only), so there is no namespace to move.
 *   - REPAIR: the source did not actually run — the consumption query used non-existent columns
 *     and exact-string keyword equality, and the sync-log table was never created. Rebinding the
 *     catalog/BOM to the Item Engine and unifying the schema fixes all three.
 *
 * CORE-SERVICE BINDINGS (a service not yet built is bound via a documented filter with a neutral
 * fallback — no competing taxonomy is invented):
 *   - Item Engine : catalog + counts + `consumes[]` via ZDZ_Item_Engine (static API, with the
 *                   mirrored `zdz_item_*` filters as the load-order-safe fallback).
 *   - Connections : the consumption source (billed jobs) is ZDZ_Core_FreshBooks by default,
 *                   overridable via the `zstock_consumption_invoices` filter; missing/unconfigured
 *                   ⇒ a logged disposition, never a crash.
 *   - Ai          : ZDZ_Core_Poe (shared client) + ZDZ_Core_Settings (key/model).
 *
 * PREFIX:  zstock_  ·  CLASSES: ZSTOCK_* (module-local, not theme services)
 * TABLES:  wp_zstock_ledger, wp_zstock_stock, wp_zstock_supplier_orders,
 *          wp_zstock_order_items, wp_zstock_sync_log  (all ship EMPTY)
 * APP ID:  stock   ·  THEME: registers via `zdz_register_apps` on after_setup_theme.
 *
 * Kill switch: define('ZSTOCK_DISABLE', true) in wp-config.php to load nothing.
 *
 * @package Zorderz\Stock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Kill switch (optional/beta module). Returning leaves the rest of the bundle untouched.
if ( defined( 'ZSTOCK_DISABLE' ) && ZSTOCK_DISABLE ) {
	return;
}

// ── Constants ──────────────────────────────────────────────────────
define( 'ZSTOCK_VERSION', '1.2.0-beta' );
define( 'ZSTOCK_DB_VERSION', '1.0.0' );
define( 'ZSTOCK_FILE', __FILE__ );
define( 'ZSTOCK_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZSTOCK_URL', plugin_dir_url( __FILE__ ) );
define( 'ZSTOCK_APP_ID', 'stock' );
define( 'ZSTOCK_NONCE', 'zstock_nonce' );

/**
 * The brain-bot answer sentinel. Protocol/marker tokens live in ONE constant, referenced by the
 * engine — never typed twice. The legacy off-repo bot emitted `YABADABA`; that is recorded as a
 * deprecated alias and stripped for backward compatibility.
 */
if ( ! defined( 'ZSTOCK_BRAIN_SENTINEL' ) ) {
	define( 'ZSTOCK_BRAIN_SENTINEL', '[ZSTOCK_ANSWER]' );
}
if ( ! defined( 'ZSTOCK_BRAIN_SENTINEL_LEGACY' ) ) {
	define( 'ZSTOCK_BRAIN_SENTINEL_LEGACY', 'YABADABA' );
}

// ── Small helpers ──────────────────────────────────────────────────
if ( ! function_exists( 'zstock_log' ) ) {
	/** Log a disposition (nothing is silent). Verbose only under WP_DEBUG. */
	function zstock_log( $msg ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[ZStock] ' . ( is_string( $msg ) ? $msg : print_r( $msg, true ) ) );
		}
	}
}

if ( ! function_exists( 'zstock_safe' ) ) {
	/** Wrap a callable so a throw is logged rather than fatal (beta safety). */
	function zstock_safe( $callable ) {
		return function () use ( $callable ) {
			try {
				return call_user_func_array( $callable, func_get_args() );
			} catch ( \Throwable $e ) {
				zstock_log( 'safe-catch: ' . $e->getMessage() );
				return null;
			}
		};
	}
}

/**
 * Content-keyed asset version: module version + file mtime, so a byte change to a CSS/JS asset
 * busts caches even within one version.
 */
function zstock_asset_ver( $rel ) {
	$mtime = @filemtime( ZSTOCK_DIR . ltrim( (string) $rel, '/' ) );
	return $mtime ? ZSTOCK_VERSION . '.' . $mtime : ZSTOCK_VERSION;
}

/**
 * The AI model to use as the platform default (central registry, never a hardcoded per-plugin
 * model). Reads the theme's shared AI-model setting; falls back to a neutral default.
 */
function zstock_default_model() {
	$m = (string) get_option( 'zdz_core_ai_model', '' );
	return '' !== $m ? $m : 'Gemini-3.1-Pro';
}

/**
 * The inventory brain-bot name. A SETTING — never a hardcoded bot name. Blank (the shipped
 * default) means "use the platform's default model with the in-repo prompt template", so the
 * intelligence no longer depends on an off-repo bot's baked catalog.
 */
function zstock_brain_bot() {
	$bot = trim( (string) get_option( 'zstock_brain_bot', '' ) );
	return '' !== $bot ? $bot : zstock_default_model();
}

/**
 * A one-line business descriptor for AI prompts, assembled at runtime from the Business Profile —
 * never a typed company/industry/place. Falls back to a neutral phrase when the profile is empty
 * (the shipped default).
 *
 * @return string e.g. "Acme Co, a field-service business" or "the business"
 */
function zstock_business_descriptor() {
	if ( ! class_exists( 'ZDZ_Business_Profile' ) ) {
		return 'the business';
	}
	$name     = trim( (string) ZDZ_Business_Profile::name() );
	$industry = trim( (string) ZDZ_Business_Profile::get( 'identity.industry', '' ) );
	if ( '' === $name ) {
		return '' !== $industry ? 'a ' . $industry . ' business' : 'the business';
	}
	return '' !== $industry ? $name . ', a ' . $industry . ' business' : $name;
}

// ── Load module classes (wrapped; a fatal in one must not take the bundle down) ──
$zstock_includes = array(
	'includes/class-zstock-db.php',
	'includes/class-zstock-catalog.php',
	'includes/class-zstock-engine.php',
	'includes/class-zstock-admin.php',
	'includes/class-zstock-dashboard.php',
);
foreach ( $zstock_includes as $zstock_rel ) {
	$zstock_path = ZSTOCK_DIR . $zstock_rel;
	try {
		if ( file_exists( $zstock_path ) ) {
			require_once $zstock_path;
		}
	} catch ( \Throwable $e ) {
		zstock_log( 'require failed: ' . $zstock_rel . ' :: ' . $e->getMessage() );
	}
}

/* ═══════════════════════════════════════════════════════════════════════════
 * ACTIVATION / DEACTIVATION (exposed to the bundle manifest)
 * ═══════════════════════════════════════════════════════════════════════════
 * The bundle's zorderz-apps.php calls these by name. Schema only — the tables ship EMPTY and are
 * NEVER seeded. Business data (a catalog) only ever arrives through the Item Engine.
 */
function zstock_activate() {
	if ( class_exists( 'ZSTOCK_DB' ) ) {
		ZSTOCK_DB::install();
	}
	zstock_reschedule_sync();
	update_option( 'zstock_db_version', ZSTOCK_DB_VERSION, false );
}

function zstock_deactivate() {
	wp_clear_scheduled_hook( 'zstock_consumption_sweep' );
}

/** (Re)schedule the auto consumption sweep to match the admin's interval setting. */
function zstock_reschedule_sync() {
	wp_clear_scheduled_hook( 'zstock_consumption_sweep' );
	if ( ! get_option( 'zstock_auto_sync', '' ) ) {
		return; // off by default (ship neutral).
	}
	$interval = (string) get_option( 'zstock_sync_interval', 'daily' );
	if ( ! in_array( $interval, array( 'hourly', 'twicedaily', 'daily' ), true ) ) {
		$interval = 'daily';
	}
	wp_schedule_event( time() + 900, $interval, 'zstock_consumption_sweep' );
}

// ── Self-heal the schema on any file-overwrite / fresh copy that skipped activation ──
add_action(
	'plugins_loaded',
	function () {
		if ( class_exists( 'ZSTOCK_DB' ) ) {
			ZSTOCK_DB::maybe_upgrade();
		}
	},
	5
);

// ── Boot the AJAX + admin surfaces ──
add_action(
	'plugins_loaded',
	zstock_safe(
		function () {
			if ( class_exists( 'ZSTOCK_Dashboard' ) ) {
				ZSTOCK_Dashboard::init();
			}
			if ( class_exists( 'ZSTOCK_Admin' ) ) {
				ZSTOCK_Admin::init();
			}
		}
	),
	20
);

// ── Auto consumption sweep (billed jobs → stock deductions) ──
add_action(
	'zstock_consumption_sweep',
	zstock_safe(
		function () {
			if ( class_exists( 'ZSTOCK_Engine' ) ) {
				ZSTOCK_Engine::run_consumption_sweep();
			}
		}
	)
);

/* ── Deprecated-alias rename map (plugins DECLARE; the kernel's ZDZ_Rename_Migration performs
 * the renames in one place). A fresh Zorderz install has no legacy rows, so every entry no-ops;
 * data is never seeded, only renamed if present. The legacy local catalog (tssc_items) and BOM
 * (tssc_bom) tables are deliberately NOT renamed — that data belongs in the Item Engine now, via
 * its discovery/import, not in a competing local table. ───────────────────────────────────── */
add_filter(
	'zdz_rename_map',
	function ( $map ) {
		$map['tables'] = array_merge(
			$map['tables'] ?? array(),
			array(
				'tssc_ledger'          => 'zstock_ledger',
				'tssc_supplier_orders' => 'zstock_supplier_orders',
				'tssc_order_items'     => 'zstock_order_items',
				'tssc_fb_sync_log'     => 'zstock_sync_log',
			)
		);
		$map['options'] = array_merge(
			$map['options'] ?? array(),
			array(
				'tssc_db_version'            => 'zstock_db_version',
				'tssc_brain_bot'             => 'zstock_brain_bot',
				'tssc_fb_auto_sync'          => 'zstock_auto_sync',
				'tssc_fb_sync_interval'      => 'zstock_sync_interval',
				'tssc_low_stock_email'       => 'zstock_low_stock_email',
				'tssc_default_supplier_name' => 'zstock_default_supplier_name',
				'tssc_last_fb_sync'          => 'zstock_last_sync',
			)
		);
		$map['cron'] = array_merge(
			$map['cron'] ?? array(),
			array( 'tssc_fb_sync_cron' => 'zstock_consumption_sweep' )
		);
		$map['app_ids'] = array_merge(
			$map['app_ids'] ?? array(),
			array( 'stock-checker' => ZSTOCK_APP_ID )
		);
		return $map;
	}
);

/* ═══════════════════════════════════════════════════════════════════════════
 * THEME INTEGRATION — DASHBOARD APP TILE
 * ═══════════════════════════════════════════════════════════════════════════
 * The theme's interfaces are not defined until after_setup_theme (WordPress loads plugins before
 * themes). Deps missing → the app declines to register rather than failing.
 */
add_action(
	'after_setup_theme',
	function () {
		if ( ! interface_exists( '\\Zorderz\\Widget_App_Interface' ) ) {
			return;
		}
		require_once ZSTOCK_DIR . 'includes/class-zstock-app.php';
		add_filter(
			'zdz_register_apps',
			function ( $apps ) {
				if ( is_array( $apps ) && class_exists( 'ZSTOCK_App' ) ) {
					$apps[ ZSTOCK_APP_ID ] = new ZSTOCK_App();
				}
				return $apps;
			}
		);
	},
	20
);
