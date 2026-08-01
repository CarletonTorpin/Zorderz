<?php
/**
 * Module: Zorderz - Jobs
 * Description: Field-job engine for the Zorderz dashboard. Track your jobs to do and
 *   hand a job COMPONENT of a mixed order to a specialist as a separate, tracked
 *   sub-job — recorded in the app and (when a CRM is configured) as a child lead,
 *   and DELIBERATELY invisible on the customer's billing document. Two-party
 *   photo-gated completion, with a recorded single-party attestation path for a solo
 *   operator. Consumes the theme's crew-lead hierarchy (ZDZ_Hierarchy), party roster
 *   (ZDZ_Party), media store (ZDZ_User_Media) and geocoder (ZDZ_Media_Geocoder).
 * Version:     1.16.0
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
 * CORE-SERVICE BINDINGS (services that do not exist yet are bound via a documented
 * filter with a graceful fallback — no competing taxonomy is invented):
 *   - Item Engine : `zdz_default_job_component` (default job kind, fallback 'other'),
 *                   `zdz_job_components` (kind list), `zdz_job_classify_component`,
 *                   `zdz_job_detect_brand`.
 *   - Flow        : states stay in-app for now; every disposition is fired on
 *                   `zdz_flow_disposition`. The 60-day auto-close is a configurable
 *                   rule (`zdz_job_close_max_days`, default 60).
 *   - Service Area: `zdz_job_location_verified` (proximity/geofence gate), with a
 *                   graceful fallback to the accuracy gate; the theme geocoder
 *                   reverse-geocodes the finish fix for provenance.
 *   - Party       : worker roster + code resolution via ZDZ_Party (never a local roster).
 *
 * SAFETY FLOOR: two-party photo-gated completion is preserved. A SOLO operator may
 * satisfy it with a RECORDED single-party attestation, raised to a distinct
 * assurance level (single_party_attested — never laundered into two_party) and
 * logged as a disposition.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ──────────────────────────────────────────────────────
define( 'ZJOB_VERSION', '1.16.0' );
define( 'ZJOB_FILE', __FILE__ );
define( 'ZJOB_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZJOB_URL', plugin_dir_url( __FILE__ ) );
define( 'ZJOB_NONCE', 'zjob_nonce' );
define( 'ZJOB_APP_ID', 'jobs' );

/**
 * The chat/orchestrator handoff marker. Protocol tokens live in ONE constant,
 * referenced by the bridge and exposed via `zdz_job_markers` — never typed twice.
 * Replaces the legacy '[TS_HANDOFF]' token.
 */
if ( ! defined( 'ZJOB_MARKER' ) ) {
	define( 'ZJOB_MARKER', '[ZDZ_JOB_HANDOFF]' );
}

/**
 * Content-keyed asset version: module version + the file mtime, so a byte change to
 * a CSS/JS asset busts caches even within one version.
 */
function zjob_asset_ver( $rel ) {
	$abs   = ZJOB_DIR . ltrim( (string) $rel, '/' );
	$mtime = @filemtime( $abs );
	return $mtime ? ZJOB_VERSION . '.' . $mtime : ZJOB_VERSION;
}

/**
 * The default job component/kind.
 *
 * Item Engine binding: the Item Engine's first kind is supplied via the
 * `zdz_default_job_component` filter. Until the Item Engine exists, the graceful
 * fallback is the neutral 'other' — NO product name is hardcoded.
 */
function zjob_default_component(): string {
	$c = sanitize_key( (string) apply_filters( 'zdz_default_job_component', 'other' ) );
	return $c !== '' ? $c : 'other';
}

/**
 * The job component/kind list (key => label).
 *
 * Item Engine binding: the kinds come from `zdz_job_components`. The neutral fallback
 * carries only generic work-types (service / other) — NO product name is hardcoded.
 *
 * @return array<string,string>
 */
function zjob_components(): array {
	$default = array(
		'service' => __( 'Service', 'zorderz' ),
		'other'   => __( 'Other', 'zorderz' ),
	);
	$map = apply_filters( 'zdz_job_components', $default );
	if ( ! is_array( $map ) || empty( $map ) ) {
		return $default;
	}
	$out = array();
	foreach ( $map as $k => $v ) {
		$k = sanitize_key( (string) $k );
		if ( $k !== '' ) {
			$out[ $k ] = (string) $v;
		}
	}
	return $out ?: $default;
}

/** Friendly label for a component key (from the kind list; humanized fallback). */
function zjob_component_label( $key ): string {
	$key = sanitize_key( (string) $key );
	if ( $key === '' ) {
		return __( 'Job', 'zorderz' );
	}
	$map = zjob_components();
	if ( isset( $map[ $key ] ) ) {
		return $map[ $key ];
	}
	return ucwords( str_replace( array( '_', '-' ), ' ', $key ) );
}

// ── Load the classes that carry no theme-interface dependency ──────
// The dashboard-app class (class-zjob-app.php) implements the theme interface, so it
// is required later, inside after_setup_theme, once the theme has defined it.
require_once ZJOB_DIR . 'includes/class-zjob-db.php';
require_once ZJOB_DIR . 'includes/class-zjob-jobs.php';
require_once ZJOB_DIR . 'includes/class-zjob-nutshell.php';
require_once ZJOB_DIR . 'includes/class-zjob-crm.php';
require_once ZJOB_DIR . 'includes/class-zjob-notify.php';
require_once ZJOB_DIR . 'includes/class-zjob-scheduler.php';
require_once ZJOB_DIR . 'includes/class-zjob-user-log.php';
require_once ZJOB_DIR . 'includes/class-zjob-photos.php';
require_once ZJOB_DIR . 'includes/class-zjob-ajax.php';
require_once ZJOB_DIR . 'includes/class-zjob-chat-bridge.php';

/**
 * Activation (called by the zorderz-apps bundle activator via the manifest entry).
 * Creates/upgrades the tables and grants the tile to eligible users.
 */
function zjob_activate() {
	ZJOB_DB::install();
	if ( class_exists( 'ZJOB_User_Log' ) && method_exists( 'ZJOB_User_Log', 'install' ) ) {
		ZJOB_User_Log::install();
	}
	zjob_grant_tile_to_all_eligible_users();

	if ( ! wp_next_scheduled( 'zjob_heartbeat' ) ) {
		wp_schedule_event( time() + 3600, 'hourly', 'zjob_heartbeat' );
	}
	if ( ! wp_next_scheduled( 'zjob_close_sweep' ) ) {
		wp_schedule_event( time() + 900, 'daily', 'zjob_close_sweep' );
	}

	update_option( 'zjob_db_version', ZJOB_DB::DB_VERSION );
}

/** Deactivation — clear our scheduled events (data is preserved; tables are NOT dropped). */
function zjob_deactivate() {
	wp_clear_scheduled_hook( 'zjob_heartbeat' );
	wp_clear_scheduled_hook( 'zjob_close_sweep' );
}

/**
 * Grant the Jobs tile to every user with `zdz_access_app` (unless explicitly denied).
 * Idempotent. Admin-class roles bypass the allowed-apps meta in the theme, so we skip them.
 */
function zjob_grant_tile_to_all_eligible_users() {
	$users = get_users( array(
		'fields'     => array( 'ID' ),
		'capability' => 'zdz_access_app',
		'number'     => -1,
	) );
	foreach ( $users as $u ) {
		zjob_grant_tile_to_user( (int) $u->ID );
	}
}

/** Grant to one user. Hooked on wp_login so users created/promoted later also get the tile. */
function zjob_grant_tile_to_user( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return;
	}
	$has_access = user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'zdz_access_app' );
	if ( ! $has_access ) {
		return;
	}
	$denied = get_user_meta( $user_id, 'zdz_denied_apps', true );
	if ( is_array( $denied ) && in_array( ZJOB_APP_ID, $denied, true ) ) {
		return; // admin explicitly denied — respect it.
	}
	$allowed = get_user_meta( $user_id, 'zdz_allowed_apps', true );
	if ( ! is_array( $allowed ) ) {
		$allowed = array();
	}
	if ( ! in_array( ZJOB_APP_ID, $allowed, true ) ) {
		$allowed[] = ZJOB_APP_ID;
		update_user_meta( $user_id, 'zdz_allowed_apps', $allowed );
	}
}

add_action( 'wp_login', function ( $user_login, $user ) {
	if ( $user instanceof WP_User ) {
		zjob_grant_tile_to_user( $user->ID );
	}
}, 10, 2 );

/** Admin view (Tools -> Activity Log). Guarded so a theme-level owner can supersede. */
add_action( 'admin_menu', function () {
	if ( class_exists( 'ZJOB_User_Log' ) && method_exists( 'ZJOB_User_Log', 'admin_menu' ) ) {
		ZJOB_User_Log::admin_menu();
	}
} );

// ── Boot ───────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
	// Self-heal the schema on any file-overwrite / fresh copy that skipped activation.
	ZJOB_DB::maybe_upgrade();
	if ( class_exists( 'ZJOB_User_Log' ) && method_exists( 'ZJOB_User_Log', 'maybe_install' ) ) {
		ZJOB_User_Log::maybe_install();
	}

	if ( class_exists( 'ZJOB_Photos' ) ) {
		ZJOB_Photos::init();
	}
	ZJOB_AJAX::init();

	// Publish the jobs.handoff capability to the orchestrator registry so the chat
	// verb is discoverable like the other bridges. Until the central resolver exists
	// this just adds a row nobody reads.
	add_filter( 'zdz_register_capabilities', function ( $caps ) {
		if ( class_exists( 'ZJOB_Chat_Bridge' ) ) {
			$caps['jobs.handoff'] = array(
				'callable'   => array( 'ZJOB_Chat_Bridge', 'handoff_from_chat' ),
				'descriptor' => ZJOB_Chat_Bridge::get_capability_descriptor(),
			);
		}
		return $caps;
	} );

	// Expose this module's marker token(s) to the chat engine via one map (never a
	// literal). Legacy '[TS_HANDOFF]' is recorded as the deprecated alias.
	add_filter( 'zdz_job_markers', function ( $markers ) {
		if ( ! is_array( $markers ) ) {
			$markers = array();
		}
		$markers['jobs.handoff'] = array(
			'token'      => ZJOB_MARKER,
			'deprecated' => array( '[TS_HANDOFF]' ),
		);
		return $markers;
	} );
}, 20 );

// ── Front-end nav + Estimates-app bridge injection ────────────────
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_user_logged_in() || ! is_front_page() ) {
		return;
	}
	// Depend on the theme app css/js so the nav markup + window.openApp() exist first.
	wp_enqueue_style(
		'zjob-nav',
		ZJOB_URL . 'assets/css/nav.css',
		array( 'zdz-app-css' ),
		zjob_asset_ver( 'assets/css/nav.css' )
	);
	wp_enqueue_script(
		'zjob-nav',
		ZJOB_URL . 'assets/js/nav.js',
		array( 'zdz-app-js' ),
		zjob_asset_ver( 'assets/js/nav.js' ),
		true
	);
	$uid      = get_current_user_id();
	$is_kiosk = class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $uid );
	wp_localize_script( 'zjob-nav', 'zjobNav', array(
		'canSeeJobs' => ! $is_kiosk,
		'appId'      => ZJOB_APP_ID,
	) );
	// Cross-app bridge so the Estimates app can call "create jobs from estimate lines".
	wp_localize_script( 'zjob-nav', 'zjob', array(
		'ajaxurl'    => admin_url( 'admin-ajax.php' ),
		'nonce'      => wp_create_nonce( ZJOB_NONCE ),
		'canHandoff' => class_exists( 'ZJOB_Jobs' ) ? ZJOB_Jobs::user_can_hand_off( $uid ) : false,
		'components' => function_exists( 'zjob_components' ) ? zjob_components() : array( 'other' => 'Other' ),
	) );
	wp_enqueue_style(
		'zjob-estimate-bridge',
		ZJOB_URL . 'assets/css/estimate-bridge.css',
		array( 'zjob-nav' ),
		zjob_asset_ver( 'assets/css/estimate-bridge.css' )
	);
	wp_enqueue_script(
		'zjob-estimate-bridge',
		ZJOB_URL . 'assets/js/estimate-bridge.js',
		array( 'zjob-nav' ),
		zjob_asset_ver( 'assets/js/estimate-bridge.js' ),
		true
	);
}, 20 );

// ── Dashboard app registration (theme interface — after_setup_theme) ──
add_action( 'after_setup_theme', function () {
	// ZJOB_App implements \Zorderz\Widget_App_Interface, so require it only once that
	// interface exists — degrade gracefully (never fatal) on a missing/older theme.
	if ( ! interface_exists( '\Zorderz\Widget_App_Interface' ) ) {
		return;
	}
	require_once ZJOB_DIR . 'includes/class-zjob-app.php';
	add_filter( 'zdz_register_apps', function ( $apps ) {
		if ( class_exists( 'ZJOB_App' ) ) {
			$apps[ ZJOB_APP_ID ] = new ZJOB_App();
		}
		return $apps;
	} );
}, 20 );

// ── Crons ──────────────────────────────────────────────────────────
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'zjob_heartbeat' ) ) {
		wp_schedule_event( time() + 3600, 'hourly', 'zjob_heartbeat' );
	}
	if ( ! wp_next_scheduled( 'zjob_close_sweep' ) ) {
		wp_schedule_event( time() + 900, 'daily', 'zjob_close_sweep' );
	}
} );

add_action( 'zjob_heartbeat', function () {
	error_log( 'Zorderz Jobs ACTIVE - version ' . ZJOB_VERSION );
} );

/**
 * Daily auto-close sweep. Auto-closes any pending_close job whose (possibly extended)
 * close deadline has passed — as the SYSTEM, attributed and logged as a disposition
 * (job_auto_closed / assurance system_auto), retiring its CRM child lead the same
 * not-a-sale way a manual close does. The window is a configurable rule (default 60
 * days); the deadline can be pushed out with a written reason, so this only fires on
 * jobs nobody chose to keep open.
 */
add_action( 'zjob_close_sweep', function () {
	if ( ! class_exists( 'ZJOB_Jobs' ) ) {
		return;
	}
	$due = ZJOB_Jobs::due_for_auto_close( 50 );
	foreach ( $due as $row ) {
		$res = ZJOB_Jobs::close_job( (int) $row['id'], 0, true );
		if ( ! empty( $res['ok'] ) && class_exists( 'ZJOB_CRM' ) ) {
			ZJOB_CRM::provider()->close_child_lead( $row, 0, true );
		}
	}
	if ( ! empty( $due ) ) {
		error_log( sprintf( 'Zorderz Jobs: auto-close sweep closed %d job(s) past the %d-day window.', count( $due ), ZJOB_Jobs::close_max_days() ) );
	}
} );

/**
 * Declare this module's legacy->current rename map to the platform migration. Plugins
 * DECLARE; the theme's ZDZ_Rename_Migration performs the renames in one place. A fresh
 * Zorderz install has no legacy rows, so every entry no-ops. Data is never seeded —
 * only renamed if present.
 */
add_filter( 'zdz_rename_map', function ( $map ) {
	$map['tables'] = array_merge( $map['tables'] ?? array(), array(
		'ts_handoffs'  => 'zdz_jobs',   // the jobs table
		'ts_user_log'  => 'zjob_user_log',
	) );
	$map['options'] = array_merge( $map['options'] ?? array(), array(
		'ts_jobs_db_version'                => 'zjob_db_version',
		'ts_user_log_db_version'            => 'zjob_user_log_db_version',
		'ts_jobs_media_share_token_healed'  => 'zjob_media_share_token_healed',
	) );
	$map['user_meta'] = array_merge( $map['user_meta'] ?? array(), array(
		'tsl_nutshell_user_id' => 'zdz_crm_user_id',
	) );
	$map['cron'] = array_merge( $map['cron'] ?? array(), array(
		'ts_jobs_heartbeat'   => 'zjob_heartbeat',
		'ts_jobs_close_sweep' => 'zjob_close_sweep',
	) );
	$map['app_ids'] = array_merge( $map['app_ids'] ?? array(), array(
		'ts-jobs' => ZJOB_APP_ID,
	) );
	return $map;
} );
