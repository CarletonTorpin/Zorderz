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
 * Version:     2.6.0
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
 * ── QUEUE TAG ─────────────────────────────────────────────────────────
 * The queue is generic: `ZPREP_Settings::queue_tag()`
 * is an admin-chosen reference token (EMPTY default = accept everything) and
 * `ZPREP_Settings::queue_subtype()` binds the queue to an Item Engine subtype, so the
 * product-line gate reads the item's own subtype rather than a string in a routing code.
 *
 * ── DATA-CONTRACT BLOCKS ──────────────────────────────────────────────
 * The blocks Prep reads out of / writes into CRM notes are neutral, VERSIONED contract
 * names held in ONE constant each (see below), never typed twice.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ──────────────────────────────────────────────────────
define( 'ZPREP_VERSION', '2.6.0' );
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
 *      The base/adjustment framing Prep wraps around the parser input is also here so the
 *      wording lives in one place.
 *
 *   2. OUTBOUND completion. Written by Prep when a cut plan is finished, and later
 *      recognised as "already cut" so a job isn't re-queued.
 *        - ZPREP_CONTRACT_CUT_COMPLETE : the completion-note header (versioned).
 *        - ZPREP_CONTRACT_SIGNATURE    : the machine-note footer signature.
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
 * Other headers a business's older CRM notes may carry for the same two blocks (recognised
 * when reading; never emitted). Core knows only the generic cut-completion title; a business
 * whose notes were written under other headers adds them on this filter.
 *
 * @return array{meas:string[],cut:string[]}
 */
function zprep_contract_deprecated_aliases(): array {
	return (array) apply_filters(
		'zprep_contract_deprecated_aliases',
		array(
			'meas' => array(),
			'cut'  => array( 'Prep — Cut Plan Complete' ),
		)
	);
}

/**
 * Record one of Prep's actions in the platform's usage records ($ref a number or 0, never a
 * name). Does nothing on a theme without the usage service.
 */
function zprep_usage( string $key, int $ref = 0, array $opts = array() ): void {
	if ( class_exists( 'ZDZ_Usage' ) && method_exists( 'ZDZ_Usage', 'record' ) ) {
		ZDZ_Usage::record( ZPREP_APP_ID, $key, $ref, $opts );
	}
}

add_filter(
	'zdz_register_usage_actions',
	function ( $a ) {
		$a                 = is_array( $a ) ? $a : array();
		$a[ ZPREP_APP_ID ] = array(
			'cut_plan_computed' => __( 'Planned the cuts', 'zorderz' ),
			'cut_marked'        => __( 'Marked pieces cut', 'zorderz' ),
			'cut_completed'     => __( 'Marked a cut complete', 'zorderz' ),
			'section_saved'     => __( 'Saved field measurements', 'zorderz' ),
		);
		return $a;
	}
);

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
require_once ZPREP_DIR . 'includes/class-zprep-install-date.php';
require_once ZPREP_DIR . 'includes/class-zprep-install-fallback.php';
require_once ZPREP_DIR . 'includes/class-zprep-leftovers.php';
require_once ZPREP_DIR . 'includes/class-zprep-nesting.php';
require_once ZPREP_DIR . 'includes/class-zprep-engine.php';
require_once ZPREP_DIR . 'includes/class-zprep-crm.php';
require_once ZPREP_DIR . 'includes/class-zprep-parser.php';
require_once ZPREP_DIR . 'includes/class-zprep-billing.php';
require_once ZPREP_DIR . 'includes/class-zprep-billing-chain.php';
require_once ZPREP_DIR . 'includes/class-zprep-admin.php';
require_once ZPREP_DIR . 'includes/class-zprep-dashboard.php';
require_once ZPREP_DIR . 'includes/class-zprep-cut-state.php';
require_once ZPREP_DIR . 'includes/class-zprep-sections.php';
require_once ZPREP_DIR . 'includes/class-zprep-upcoming.php';
require_once ZPREP_DIR . 'includes/class-zprep-pack.php';
ZPREP_Upcoming::init();
add_filter( 'zdz_identity_pack_consumers', array( 'ZPREP_Pack', 'register' ) );
require_once ZPREP_DIR . 'includes/class-zprep-assignments.php';
require_once ZPREP_DIR . 'includes/class-zprep-remakes.php';
require_once ZPREP_DIR . 'includes/class-zprep-plan-preview.php';
require_once ZPREP_DIR . 'includes/class-zprep-queue-counts.php';

/**
 * The job tables (cut state, assignments, remakes). Created on activation and again whenever
 * the stored schema version differs, because an update dropped over an existing install does
 * not re-run activation. Schema only; nothing is seeded.
 */
function zprep_migrate_job_tables(): void {
	ZPREP_Cut_State::migrate();
	ZPREP_Sections::migrate();
	ZPREP_Assignments::migrate();
	ZPREP_Remakes::migrate();
	update_option( 'zprep_jobs_schema_version', ZPREP_VERSION, false );
}

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
	zprep_migrate_job_tables();
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
		if ( get_option( 'zprep_jobs_schema_version' ) !== ZPREP_VERSION ) {
			zprep_migrate_job_tables();
		}

		ZPREP_Dashboard::init();
		ZPREP_Assignments::register_hooks();     // Prep access for a person holding a job assignment
		ZPREP_Cut_State::register_project_source(); // the "Cut state" panel on a jobs-app project
		ZPREP_Queue_Counts::init();              // prep.queue_counts

		if ( is_admin() ) {
			ZPREP_Admin::init();
		}

		if ( ! wp_next_scheduled( ZPREP_Leftovers::CRON_HOOK ) ) {
			ZPREP_Leftovers::schedule_cron();
		}
		if ( ! wp_next_scheduled( 'zprep_billing_sync' ) ) {
			wp_schedule_event( time() + 180, 'zprep_5min', 'zprep_billing_sync' );
		}

		// Expose this module's marker tokens via one map (never a literal), with the older
		// headers a business's notes may carry (zprep_contract_deprecated_aliases).
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

