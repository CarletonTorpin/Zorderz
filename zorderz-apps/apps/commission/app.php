<?php
/**
 * Module: Zorderz - Commission
 * Description: Deterministic commission + piece-rate payroll for the Zorderz
 *   dashboard. Reads HOW people are paid from the theme's Compensation Core
 *   service (ZDZ_Compensation) and WHAT the business sells from the Item Engine
 *   (products, subtypes, aliases, costs, and the cross-app counts contract).
 *   All commercially sensitive data — rates, tiers, piece $/unit, COGS,
 *   product minimums, per-party plans — ships EMPTY and is never seeded. Pure
 *   PHP math, zero LLM in any pay path. Consumes ZDZ_Core_FreshBooks for
 *   invoices, ZDZ_Party for the roster, ZDZ_Data_Permissions for tiered
 *   visibility, and publishes a cross-app bridge (ZCC_TSA_Bridge) so analytics
 *   and the chat orchestrator can ask for a commission figure or a unit tally.
 * Version:     1.0.0
 * Author:      Zorderz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 8.0
 *
 * This is a bundled app module (loaded by zorderz-apps.php), not a standalone
 * plugin. It registers with the theme through the `zdz_register_apps` filter on
 * after_setup_theme and declines cleanly when the theme is absent.
 *
 * CORE-SERVICE BINDINGS:
 *   - Compensation : ZDZ_Compensation — commission structures, tiers, split
 *                    policies, piece rates, product minimums, ledger kinds,
 *                    card-fee handling, pay calendar, payability gate,
 *                    attribution precedence. Ships EMPTY.
 *   - Item Engine  : product classification + costs via the zdz_item_* filters
 *                    (`zdz_item_classify` / `zdz_item_match` / `zdz_item_get`),
 *                    counts via the item_keyed_v2 contract. Empty catalog ⇒
 *                    every resolver degrades to neutral; no local taxonomy.
 *   - FreshBooks   : ZDZ_Core_FreshBooks for invoices (provider host is config).
 *   - Party        : ZDZ_Party roster; the short code is the `initials` key,
 *                    matched case-insensitively.
 *
 * SAFETY FLOOR (never weakened): commission is earned only on collected money
 * and the provider's own paid-filter is never trusted; an inferred rep is never
 * payable; an unresolvable share pays zero-and-flags; the shared kiosk hard-
 * refuses any commission figure. Nothing is silent — every drop is a logged
 * disposition.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ──────────────────────────────────────────────────────
define( 'ZCC_VERSION', '1.0.0' );
define( 'ZCC_DB_VERSION', '1.0.0' );
define( 'ZCC_FILE', __FILE__ );
define( 'ZCC_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZCC_URL', plugin_dir_url( __FILE__ ) );
define( 'ZCC_NONCE', 'zcc_nonce' );
define( 'ZCC_APP_ID', 'commission-calculator' ); // matches the theme's label map + role grants — NEVER change

/**
 * The chat/orchestrator commission marker. Protocol tokens live in ONE constant,
 * referenced by the bridge + parser, never typed twice. Replaces '[TSCC_CALC]',
 * which is recorded as a deprecated alias in the bridge format spec.
 */
if ( ! defined( 'ZCC_CALC_MARKER' ) ) {
	define( 'ZCC_CALC_MARKER', '[ZCC_CALC]' );
}

/** REST base — the single namespace constant; never type the literal twice. */
if ( ! defined( 'ZCC_REST_NS' ) ) {
	define( 'ZCC_REST_NS', defined( 'ZDZ_REST_NS' ) ? ZDZ_REST_NS : 'zorderz/v1' );
}

/** Content-keyed asset version: module version + file mtime. */
function zcc_asset_ver( $rel ) {
	$abs   = ZCC_DIR . ltrim( (string) $rel, '/' );
	$mtime = @filemtime( $abs );
	return $mtime ? ZCC_VERSION . '.' . $mtime : ZCC_VERSION;
}

/* ==================================================================
 * ITEM ENGINE BINDING HELPERS
 *
 * Every product decision flows through these. They prefer the Item Engine
 * static API, fall back to the mirrored zdz_item_* filters, and degrade to a
 * NEUTRAL value on an empty catalog — this module never invents a taxonomy.
 * ================================================================== */

/** Classify free text to an item id (a "kind"), or '' when unresolved/empty catalog. */
function zcc_item_classify( string $text ): string {
	if ( class_exists( 'ZDZ_Item_Engine' ) ) {
		$id = ZDZ_Item_Engine::classify( $text );
		return is_string( $id ) ? $id : '';
	}
	$id = apply_filters( 'zdz_item_classify', null, $text );
	return is_string( $id ) ? $id : '';
}

/** Match free text to a full item array, or null. */
function zcc_item_match( string $text ) {
	if ( class_exists( 'ZDZ_Item_Engine' ) ) {
		return ZDZ_Item_Engine::match( $text );
	}
	$m = apply_filters( 'zdz_item_match', null, $text, [] );
	return is_array( $m ) ? $m : null;
}

/** Fetch one item by id, or null. */
function zcc_item_get( string $item_id ) {
	if ( $item_id === '' ) {
		return null;
	}
	if ( class_exists( 'ZDZ_Item_Engine' ) ) {
		return ZDZ_Item_Engine::get( $item_id );
	}
	$i = apply_filters( 'zdz_item_get', null, $item_id );
	return is_array( $i ) ? $i : null;
}

/** The Item Engine content version, folded into classification caches. 0 when absent. */
function zcc_item_engine_version(): int {
	if ( class_exists( 'ZDZ_Item_Engine' ) ) {
		return (int) ZDZ_Item_Engine::version();
	}
	return (int) apply_filters( 'zdz_item_engine_version', 0 );
}

// ── Load the classes with no theme-interface dependency ────────────
// class-zcc-app.php implements the theme interface, so it is required later,
// inside after_setup_theme, once that interface exists.
require_once ZCC_DIR . 'includes/class-zcc-cost-book.php';
require_once ZCC_DIR . 'includes/class-zcc-classifier.php';
require_once ZCC_DIR . 'includes/class-zcc-freshbooks.php';
require_once ZCC_DIR . 'includes/class-zcc-split.php';
require_once ZCC_DIR . 'includes/class-zcc-ledger.php';
require_once ZCC_DIR . 'includes/class-zcc-audit.php';
require_once ZCC_DIR . 'includes/class-zcc-rep-overrides.php';
require_once ZCC_DIR . 'includes/class-zcc-calc-engine.php';
require_once ZCC_DIR . 'includes/class-zcc-installer-pay.php';
require_once ZCC_DIR . 'includes/class-zcc-self-test.php';
require_once ZCC_DIR . 'includes/class-zcc-tsa-bridge.php';
require_once ZCC_DIR . 'includes/class-zcc-rest.php';
require_once ZCC_DIR . 'includes/class-zcc-admin.php';

/**
 * Activation (called by the zorderz-apps bundle activator via the manifest).
 * Creates SCHEMA ONLY — no COGS, no rates, no plans are ever seeded. Schedules
 * the finalisation cron.
 */
function zcc_activate() {
	$migration = ZCC_DIR . 'db/migrate-1.0.0.php';
	if ( file_exists( $migration ) ) {
		require_once $migration;
		if ( function_exists( 'zcc_migrate_1_0_0' ) ) {
			zcc_migrate_1_0_0();
		}
	}
	update_option( 'zcc_db_version', ZCC_DB_VERSION, false );
	if ( class_exists( 'ZCC_Ledger' ) ) {
		ZCC_Ledger::schedule_cron();
	}
}

/** Deactivation — clear scheduled events (data is preserved; tables are NOT dropped). */
function zcc_deactivate() {
	if ( class_exists( 'ZCC_Ledger' ) ) {
		ZCC_Ledger::unschedule_cron();
	}
}

// ── Self-heal schema + boot components ─────────────────────────────
add_action( 'plugins_loaded', function () {
	// Self-heal on any file-overwrite / fresh copy that skipped activation.
	if ( get_option( 'zcc_db_version' ) !== ZCC_DB_VERSION ) {
		$migration = ZCC_DIR . 'db/migrate-1.0.0.php';
		if ( file_exists( $migration ) ) {
			require_once $migration;
			if ( function_exists( 'zcc_migrate_1_0_0' ) ) {
				zcc_migrate_1_0_0();
			}
		}
		update_option( 'zcc_db_version', ZCC_DB_VERSION, false );
	}
}, 6 );

add_action( 'init', function () {
	if ( class_exists( 'ZCC_Admin' ) ) {
		ZCC_Admin::init();
	}
	if ( class_exists( 'ZCC_Ledger' ) ) {
		ZCC_Ledger::schedule_cron();
	}
} );

add_action( 'rest_api_init', function () {
	if ( class_exists( 'ZCC_REST' ) ) {
		ZCC_REST::register_routes();
	}
} );

/* ── Monthly finalisation cron ──────────────────────────────────────
 * A calendar-month schedule (period_type from ZDZ_Compensation::pay_calendar()).
 * The legacy 30-day interval that stood in for "monthly" was a defect; we
 * anchor on the WP-configured timezone and finalise the PRIOR calendar month.
 */
add_filter( 'cron_schedules', function ( $schedules ) {
	if ( ! isset( $schedules['zcc_monthly'] ) ) {
		$schedules['zcc_monthly'] = [ 'interval' => 30 * DAY_IN_SECONDS, 'display' => __( 'Once Monthly (Commission)', 'zorderz' ) ];
	}
	return $schedules;
} );
add_action( 'zcc_monthly_finalize', [ 'ZCC_Ledger', 'cron_finalize_prior_month' ] );

// ── Orchestrator capability registration (chat verbs) ──────────────
add_action( 'plugins_loaded', function () {
	add_filter( 'zdz_register_capabilities', function ( $caps ) {
		if ( class_exists( 'ZCC_TSA_Bridge' ) ) {
			$caps['commission.calc'] = [
				'callable'   => [ 'ZCC_TSA_Bridge', 'commission_calc_for_tsa' ],
				'descriptor' => ZCC_TSA_Bridge::get_capability_descriptor(),
			];
			$caps['commission.units'] = [
				'callable'   => [ 'ZCC_TSA_Bridge', 'unit_counts_for_tsa' ],
				'descriptor' => ZCC_TSA_Bridge::get_units_capability_descriptor(),
			];
		}
		return $caps;
	} );

	// Expose this module's marker token to the chat engine via one map (never a
	// literal). Legacy '[TSCC_CALC]' is recorded as the deprecated alias.
	add_filter( 'zdz_chat_markers', function ( $markers ) {
		if ( ! is_array( $markers ) ) {
			$markers = [];
		}
		$markers['commission.calc'] = [ 'token' => ZCC_CALC_MARKER, 'deprecated' => [ '[TSCC_CALC]' ] ];
		return $markers;
	} );
}, 20 );

// ── Dashboard app registration (theme interface — after_setup_theme) ──
add_action( 'after_setup_theme', function () {
	if ( ! interface_exists( '\Zorderz\Widget_App_Interface' ) && ! interface_exists( '\Zorderz\App_Interface' ) ) {
		return; // theme absent / older — decline cleanly.
	}
	require_once ZCC_DIR . 'includes/class-zcc-app.php';
	add_filter( 'zdz_register_apps', function ( $apps ) {
		if ( class_exists( 'ZCC_App' ) ) {
			$apps[ ZCC_APP_ID ] = new ZCC_App();
		}
		return $apps;
	} );
}, 20 );

// ── Front-end assets ────────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_user_logged_in() || ! is_front_page() ) {
		return;
	}
	wp_enqueue_style( 'zcc-widget', ZCC_URL . 'assets/css/widget.css', [ 'zdz-app-css' ], zcc_asset_ver( 'assets/css/widget.css' ) );
	wp_enqueue_script( 'zcc-widget', ZCC_URL . 'assets/js/widget.js', [ 'zdz-app-js' ], zcc_asset_ver( 'assets/js/widget.js' ), true );
	wp_localize_script( 'zcc-widget', 'zccCfg', [
		'restUrl' => esc_url_raw( rest_url( ZCC_REST_NS . '/commission/' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'appId'   => ZCC_APP_ID,
	] );
}, 20 );

/**
 * Declare this module's legacy → current rename map to the platform migration.
 * Plugins DECLARE; the theme's ZDZ_Rename_Migration performs the renames in one
 * place. A fresh Zorderz install has no legacy rows, so every entry no-ops.
 * Data is never seeded — only renamed if present.
 */
add_filter( 'zdz_rename_map', function ( $map ) {
	$map['tables'] = array_merge( $map['tables'] ?? [], [
		'ts_commission_ledger' => 'zcc_commission_ledger',
		'tscc_commission_ledger' => 'zcc_commission_ledger',
		'tscc_audit_log'         => 'zcc_audit_log',
		'tscc_rep_overrides'     => 'zcc_rep_overrides',
		// wp_tscc_cogs_catalog + wp_tscc_classification_cache are NOT renamed:
		// the catalog is subsumed by the Item Engine and the classification cache
		// is regenerated from it, so both legacy tables are simply abandoned.
	] );
	$map['options'] = array_merge( $map['options'] ?? [], [
		'tscc_db_version'          => 'zcc_db_version',
		'tscc_installer_pay'       => 'zdz_compensation_piece_rates',
		// The legacy single-id product-minimum-rep option is NOT key-renamed:
		// its scalar shape does not map onto the new rule set, so it is migrated
		// by the admin re-declaring the rule (not by a blind key rename).
	] );
	$map['user_meta'] = array_merge( $map['user_meta'] ?? [], [
		'tscc_commission_structure' => 'zdz_comp_structure',
		'tscc_commission_rate'      => 'zdz_comp_rate_percent',
		'tscc_base_pay'             => 'zdz_comp_base_pay',
		'tscc_bonus_per_job'        => 'zdz_comp_bonus_per_job',
		'tscc_tiers'                => 'zdz_comp_tiers',
		'tscc_pay_period'           => 'zdz_comp_pay_period',
		'tscc_exclude_cc_fees'      => 'zdz_comp_exclude_card_fees',
		'tscc_split_policy'         => 'zdz_comp_split_policy',
		'tscc_split_weight'         => 'zdz_comp_split_weight',
		'tscc_split_own_share'      => 'zdz_comp_split_own_share',
		'tscc_ember_pct_rep'        => 'zdz_comp_minimum_party',
		'tscc_is_installer'         => 'zdz_comp_is_piece_worker',
		'tscc_salesperson_code'     => 'zdz_party_code',
	] );
	$map['cron'] = array_merge( $map['cron'] ?? [], [
		'tscc_monthly_finalize' => 'zcc_monthly_finalize',
	] );
	$map['app_ids'] = array_merge( $map['app_ids'] ?? [], [
		'ts-commission-calculator' => ZCC_APP_ID,
	] );
	return $map;
} );
