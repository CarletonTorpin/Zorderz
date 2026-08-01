<?php
/**
 * Module: Zorderz - Prep (Cut Queue)
 * Description: Mobile-first fabrication PREP tool for the Zorderz dashboard. Pulls a job
 *   from the configured CRM (or billing provider), parses its measurement notes with the
 *   Core AI service, and produces a colour-accurate SVG nesting layout with printable cut
 *   sheets for the tenant's roll stock. The "cut queue" is generic prep work: the product
 *   line it filters on is a configurable QUEUE TAG bound to an admin-chosen Item Engine
 *   subtype/tag — nothing is hardcoded to one trade. What counts as a cut piece, the piece
 *   vocabulary, default sizes and the roll/material model all come from the Item Engine and
 *   tenant configuration; supplier costs SHIP EMPTY.
 * Version:     2.3.0
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
 * ── CORE-SERVICE BINDINGS ─────────────────────────────────────────────
 * Identity is READ from the theme, never hardcoded:
 *   - Item Engine      : the piece vocabulary, "is this a cut piece?", default sizes and
 *                        the queue-tag subtype come from the canonical `zdz_item_*` filters
 *                        / ZDZ_Item_Engine. An EMPTY catalog degrades to neutral (a piece
 *                        is cuttable when it has real dimensions and is not a pre-made
 *                        deliverable) — never a baked-in piece list.
 *   - Business Profile : the cut-sheet letterhead (`ZDZ_Business_Profile::name()`).
 *   - Connections      : CRM (`ZDZ_Core_Nutshell`), AI (`ZDZ_Core_Poe`), billing
 *                        (`ZDZ_Core_Freshbooks`). Prep never stores its own credentials.
 *   - Flow             : cut-queue states stay in-app FOR NOW; every drop/skip/promote/
 *                        completion is fired on `do_action('zdz_flow_disposition', ...)`
 *                        so the future Flow service can consume the same ledger. The
 *                        CRM pipeline stage that means "ready to cut" is configurable.
 *
 * ── QUEUE TAG (was a hardcoded product line) ──────────────────────────
 * The legacy build hard-filtered every record to a single reference token and treated
 * that one product line as THE product. Here the queue is generic: `ZPREP_Settings::queue_tag()`
 * is an admin-chosen reference token (EMPTY default = accept everything) and
 * `ZPREP_Settings::queue_subtype()` binds the queue to an Item Engine subtype, so the
 * product-line gate reads the item's own subtype rather than a string in a routing code.
 *
 * ── DATA-CONTRACT BLOCKS (was CRM-note IPC) ───────────────────────────
 * The blocks Prep reads out of / writes into CRM notes are neutral, VERSIONED contract
 * names held in ONE constant each (see below), never typed twice. Legacy prior-build
 * headers are recognised as deprecated aliases so historical notes still parse.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ──────────────────────────────────────────────────────
define( 'ZPREP_VERSION', '2.3.0' );
define( 'ZPREP_FILE', __FILE__ );
define( 'ZPREP_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZPREP_URL', plugin_dir_url( __FILE__ ) );
define( 'ZPREP_NONCE', 'zprep_nonce' );
define( 'ZPREP_APP_ID', 'prep' );

/**
 * ── THE CROSS-SYSTEM DATA CONTRACT (versioned, neutral, one authority) ──
 *
 * Prep exchanges two kinds of block with the CRM:
 *
 *   1. INBOUND measurements. Written by the estimate app (a separate module) onto the
 *      lead as a note. Prep RECOGNISES the block by these markers; it never types the
 *      estimate app's own header twice. Matching is by substring, case-insensitive.
 *        - ZPREP_CONTRACT_MEAS_MARKER  : primary marker the estimate app emits.
 *        - ZPREP_CONTRACT_MEAS_MARKER2 : secondary confirmation marker.
 *        - ZPREP_CONTRACT_MEAS_LEGACY* : deprecated prior-build aliases (upgrade only).
 *      The base/adjustment framing Prep wraps around the parser input is also here so the
 *      wording lives in one place.
 *
 *   2. OUTBOUND completion. Written by Prep when a cut plan is finished, and later
 *      recognised as "already cut" so a job isn't re-queued.
 *        - ZPREP_CONTRACT_CUT_COMPLETE : the completion-note header (versioned).
 *        - ZPREP_CONTRACT_SIGNATURE    : the machine-note footer signature.
 *        - ZPREP_CONTRACT_CUT_LEGACY   : deprecated prior-build header/footer aliases.
 *
 * Consumers read these via ZPREP_Crm; nothing hardcodes a block string inline.
 */
if ( ! defined( 'ZPREP_CONTRACT_MEAS_MARKER' ) ) {
	define( 'ZPREP_CONTRACT_MEAS_MARKER', 'ZDZ Measurements' );
}
if ( ! defined( 'ZPREP_CONTRACT_MEAS_MARKER2' ) ) {
	define( 'ZPREP_CONTRACT_MEAS_MARKER2', 'FULL MEASUREMENTS' );
}
if ( ! defined( 'ZPREP_CONTRACT_CUT_COMPLETE' ) ) {
	define( 'ZPREP_CONTRACT_CUT_COMPLETE', '=== Zorderz Prep — Cut Plan Complete v1 ===' );
}
if ( ! defined( 'ZPREP_CONTRACT_SIGNATURE' ) ) {
	define( 'ZPREP_CONTRACT_SIGNATURE', 'Zorderz Prep' );
}

/**
 * Deprecated data-contract aliases (recognised for historical CRM notes; never emitted).
 * Declared as a filterable map rather than typed inline, so an upgrading prior-build
 * install still parses its old blocks while a fresh Zorderz install ships clean.
 *
 * @return array{meas:string[],cut:string[]}
 */
function zprep_contract_deprecated_aliases(): array {
	return (array) apply_filters(
		'zprep_contract_deprecated_aliases',
		array(
			'meas' => array( 'TS Est Maker', 'Est Maker' ),
			'cut'  => array( 'Prep — Cut Plan Complete', 'TS Prep' ),
		)
	);
}

/**
 * Content-keyed asset version: module version + file mtime, so a byte change to a
 * CSS/JS asset busts caches even within one version.
 */
function zprep_asset_ver( $rel ) {
	$abs   = ZPREP_DIR . ltrim( (string) $rel, '/' );
	$mtime = @filemtime( $abs );
	return $mtime ? ZPREP_VERSION . '.' . $mtime : ZPREP_VERSION;
}

// ── Load the plumbing (no theme-interface dependency) ──────────────
// class-zprep-app.php implements the theme interface and is required later, inside
// after_setup_theme, once \Zorderz\Widget_App_Interface exists.
require_once ZPREP_DIR . 'includes/class-zprep-settings.php';
require_once ZPREP_DIR . 'includes/class-zprep-leftovers.php';
require_once ZPREP_DIR . 'includes/class-zprep-nesting.php';
require_once ZPREP_DIR . 'includes/class-zprep-engine.php';
require_once ZPREP_DIR . 'includes/class-zprep-crm.php';
require_once ZPREP_DIR . 'includes/class-zprep-parser.php';
require_once ZPREP_DIR . 'includes/class-zprep-billing.php';
require_once ZPREP_DIR . 'includes/class-zprep-admin.php';
require_once ZPREP_DIR . 'includes/class-zprep-dashboard.php';

/**
 * Activation (called by the zorderz-apps bundle activator via the manifest entry).
 * Creates/upgrades the leftovers table (schema migration only — NO business data seeded)
 * and schedules the reservation-expiry + optional billing-sync crons.
 */
function zprep_activate() {
	if ( false === get_option( 'zprep_settings' ) ) {
		add_option( 'zprep_settings', ZPREP_Settings::defaults() );
	}
	$migrate = ZPREP_DIR . 'db/migrate-2.3.0.php';
	if ( file_exists( $migrate ) ) {
		require_once $migrate;
	}
	ZPREP_Leftovers::schedule_cron();
	if ( ! wp_next_scheduled( 'zprep_billing_sync' ) ) {
		wp_schedule_event( time() + 180, 'zprep_5min', 'zprep_billing_sync' );
	}
	update_option( 'zprep_db_version', ZPREP_Leftovers::DB_VERSION, false );
	update_option( 'zprep_version', ZPREP_VERSION, false );
}

/** Deactivation — clear scheduled events. Data + tables are preserved. */
function zprep_deactivate() {
	ZPREP_Leftovers::unschedule_cron();
	wp_clear_scheduled_hook( 'zprep_billing_sync' );
}

// A 5-minute recurrence for the optional billing-approval sync.
add_filter(
	'cron_schedules',
	function ( $s ) {
		if ( ! isset( $s['zprep_5min'] ) ) {
			$s['zprep_5min'] = array( 'interval' => 300, 'display' => __( 'Every 5 minutes (Zorderz Prep)', 'zorderz' ) );
		}
		return $s;
	}
);

// ── Boot ───────────────────────────────────────────────────────────
add_action(
	'plugins_loaded',
	function () {
		// Self-heal the leftovers schema on any file-overwrite / fresh copy that
		// skipped activation.
		ZPREP_Leftovers::maybe_upgrade();

		ZPREP_Dashboard::init();

		if ( is_admin() ) {
			ZPREP_Admin::init();
		}

		if ( ! wp_next_scheduled( ZPREP_Leftovers::CRON_HOOK ) ) {
			ZPREP_Leftovers::schedule_cron();
		}
		if ( ! wp_next_scheduled( 'zprep_billing_sync' ) ) {
			wp_schedule_event( time() + 180, 'zprep_5min', 'zprep_billing_sync' );
		}

		// Expose this module's marker tokens via one map (never a literal). Legacy
		// prior-build headers are recorded as deprecated aliases.
		add_filter(
			'zdz_prep_markers',
			function ( $markers ) {
				if ( ! is_array( $markers ) ) {
					$markers = array();
				}
				$dep                          = zprep_contract_deprecated_aliases();
				$markers['prep.cut_complete'] = array(
					'token'      => ZPREP_CONTRACT_CUT_COMPLETE,
					'signature'  => ZPREP_CONTRACT_SIGNATURE,
					'deprecated' => $dep['cut'],
				);
				$markers['prep.measurements'] = array(
					'markers'    => array( ZPREP_CONTRACT_MEAS_MARKER, ZPREP_CONTRACT_MEAS_MARKER2 ),
					'deprecated' => $dep['meas'],
				);
				return $markers;
			}
		);
	},
	20
);

add_action( 'zprep_leftovers_expire_reservations', array( 'ZPREP_Leftovers', 'cron_expire' ) );
add_action( 'zprep_billing_sync', array( 'ZPREP_Billing', 'run_approved_sync' ) );

// ── KPI metrics contribution (theme's zdz_kpi_metrics filter) ──────
add_filter(
	'zdz_kpi_metrics',
	function ( $metrics ) {
		if ( ! is_array( $metrics ) ) {
			$metrics = array();
		}
		global $wpdb;
		$t = ZPREP_Leftovers::table_name();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
			return $metrics;
		}
		$saveable = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'available'" );
		$metrics['prep_leftovers_available'] = array( 'value' => (string) $saveable, 'raw' => $saveable, 'source' => 'prep' );
		return $metrics;
	}
);

// ── Dashboard app registration (theme interface — after_setup_theme) ──
add_action(
	'after_setup_theme',
	function () {
		if ( ! interface_exists( '\Zorderz\Widget_App_Interface' ) ) {
			return; // degrade gracefully on a missing/older theme — never fatal.
		}
		require_once ZPREP_DIR . 'includes/class-zprep-app.php';
		add_filter(
			'zdz_register_apps',
			function ( $apps ) {
				if ( class_exists( 'ZPREP_App' ) ) {
					$apps[ ZPREP_APP_ID ] = new ZPREP_App();
				}
				return $apps;
			}
		);
	},
	20
);

/**
 * Declare this module's legacy->current rename map to the platform migration. Plugins
 * DECLARE; the theme's ZDZ_Rename_Migration performs the renames in one place. A fresh
 * Zorderz install has no legacy rows, so every entry no-ops. Data is never seeded — only
 * renamed if present. (The leftovers TABLE rename is a real migration recorded in
 * db/migrate-2.3.0.php; the platform map handles whole tables/options/crons/app-ids.)
 */
add_filter(
	'zdz_rename_map',
	function ( $map ) {
		$map['tables'] = array_merge(
			$map['tables'] ?? array(),
			array( 'tsemc_leftovers' => 'zdz_prep_leftovers' )
		);
		$map['options'] = array_merge(
			$map['options'] ?? array(),
			array(
				'tsemc_settings'              => 'zprep_settings',
				'tsemc_version'               => 'zprep_version',
				'tsemc_fb_ground_truth'       => 'zprep_billing_ground_truth',
				'tsemc_fb_promotions'         => 'zprep_billing_promotions',
				'tsemc_fb_status_filter'      => 'zprep_billing_status_filter',
				'tsemc_fb_status_filter_ver'  => 'zprep_billing_status_filter_ver',
				'tsemc_ns_activity_type_id'   => 'zprep_crm_activity_type_id',
				'tsemc_ns_cut_stage_name'     => 'zprep_cut_stage_name',
			)
		);
		$map['cron'] = array_merge(
			$map['cron'] ?? array(),
			array(
				'tsemc_fb_sync'                        => 'zprep_billing_sync',
				'tsemc_leftovers_expire_reservations'  => ZPREP_Leftovers::CRON_HOOK,
			)
		);
		// NOTE: a tenant upgrading from a differently-named legacy cut/prep app maps its
		// old app-id to ZPREP_APP_ID through this same `zdz_rename_map` filter from its own
		// (private) pack, so user app-grants survive the rename. The public module ships
		// no legacy product/brand id of its own.
		return $map;
	}
);
