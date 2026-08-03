<?php
/**
 * Module: Zorderz - Analytics (Chat)
 * Description: The conversational analytics assistant — the "Chat" surface of the
 *   Zorderz dashboard. Ask a question in plain language; the assistant assembles its
 *   prompt at runtime from the Business Profile, the Item Engine catalog, the Party
 *   roster and the rendered rule set, answers from the business's own systems of
 *   record, and passes every reply through the single outbound gate (ZDZ_Answer_
 *   Authority) so it states facts only. Consumes the theme Core services:
 *   ZDZ_Business_Profile, ZDZ_Item_Engine, ZDZ_Party, ZDZ_Rule_Governance,
 *   ZDZ_Model_Registry, ZDZ_Answer_Authority, ZDZ_Core_Poe, ZDZ_Data_Permissions,
 *   ZDZ_Hierarchy.
 * Version:     1.2.0
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
 * SHIPS EMPTY. Activation creates the session/message schema ONLY — no company
 * facts, no roster, no price list, no supplier corpus. The prompt is generated from
 * whatever the tenant has configured in the Core services; on a fresh install that
 * is a neutral, honest assistant with nothing to leak.
 *
 * WHAT IS PORTED vs DEFERRED (honest scope — see README): the runtime prompt
 * builder, the model routing, the outbound gate, the rendered rule set, the session
 * store, the synchronous chat turn and (1.2.0) the async turn queue that runs a slow
 * turn in a background loopback so it cannot 502 behind a managed host's gateway
 * timeout are ported. The token-streaming channel, the scheduled digests, the
 * per-provider data connectors (billing/CRM/analytics), the self-check auditor pass
 * and the memory extractor are DEFERRED — each is a separate surface and is wired as a
 * documented extension point (a filter with a neutral fallback) rather than shipped
 * half-built.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ──────────────────────────────────────────────────────
define( 'ZANA_VERSION', '1.2.0' );
define( 'ZANA_FILE', __FILE__ );
define( 'ZANA_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZANA_URL', plugin_dir_url( __FILE__ ) );
define( 'ZANA_NONCE', 'zana_nonce' );

/**
 * KEEP the app id 'sales-analytics'. The theme already grants this id in
 * ZDZ_User_Roles, labels it in ZDZ_Plugin_API, and the dashboard KPI tiles +
 * digest deep-link route to it. Renaming the id would break all three.
 */
define( 'ZANA_APP_ID', 'sales-analytics' );

/** Content-keyed asset version so a byte change busts caches within a version. */
function zana_asset_ver( $rel ) {
	$abs   = ZANA_DIR . ltrim( (string) $rel, '/' );
	$mtime = @filemtime( $abs );
	return $mtime ? ZANA_VERSION . '.' . $mtime : ZANA_VERSION;
}

// ── Load includes ──────────────────────────────────────────────────
require_once ZANA_DIR . 'includes/class-zana-markers.php';
require_once ZANA_DIR . 'includes/class-zana-db.php';
require_once ZANA_DIR . 'includes/class-zana-prompt-builder.php';
require_once ZANA_DIR . 'includes/class-zana-chat.php';
require_once ZANA_DIR . 'includes/class-zana-background.php';
require_once ZANA_DIR . 'includes/class-zana-rest.php';

/**
 * Activation (called by the zorderz-apps bundle activator via the manifest).
 * Schema only — NEVER seeds business data.
 */
function zana_activate() {
	ZANA_DB::install();
	zana_grant_tile_to_all_eligible_users();
	update_option( 'zana_db_version', ZANA_DB::DB_VERSION, false );
}

/** Deactivation — nothing scheduled to clear in the ported core; data is preserved. */
function zana_deactivate() {
	// The async digest/heartbeat crons are a deferred surface; nothing to unschedule.
}

/** Grant the tile to every eligible user (idempotent). Admin-class roles bypass the meta. */
function zana_grant_tile_to_all_eligible_users() {
	$users = get_users(
		array(
			'fields'     => array( 'ID' ),
			'capability' => 'zdz_access_app',
			'number'     => -1,
		)
	);
	foreach ( $users as $u ) {
		zana_grant_tile_to_user( (int) $u->ID );
	}
}

/** Grant to one user. */
function zana_grant_tile_to_user( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return;
	}
	if ( ! ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'zdz_access_app' ) ) ) {
		return;
	}
	$denied = get_user_meta( $user_id, 'zdz_denied_apps', true );
	if ( is_array( $denied ) && in_array( ZANA_APP_ID, $denied, true ) ) {
		return;
	}
	$allowed = get_user_meta( $user_id, 'zdz_allowed_apps', true );
	if ( ! is_array( $allowed ) ) {
		$allowed = array();
	}
	if ( ! in_array( ZANA_APP_ID, $allowed, true ) ) {
		$allowed[] = ZANA_APP_ID;
		update_user_meta( $user_id, 'zdz_allowed_apps', $allowed );
	}
}

add_action(
	'wp_login',
	function ( $user_login, $user ) {
		if ( $user instanceof WP_User ) {
			zana_grant_tile_to_user( $user->ID );
		}
	},
	10,
	2
);

// ── Boot ───────────────────────────────────────────────────────────
add_action(
	'plugins_loaded',
	function () {
		ZANA_DB::maybe_upgrade();
		ZANA_REST::init();
		// The background turn runner (loopback + cleanup cron). Registered on every
		// load so the loopback admin-ajax handler exists when that request arrives.
		ZANA_Background::boot();
	},
	20
);

// ── Front-end: inject the Chat nav item + surface ──────────────────
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! is_user_logged_in() || ! is_front_page() ) {
			return;
		}
		// Depend on the theme app css/js so the nav markup + sub-view machinery exist.
		wp_enqueue_style(
			'zana-chat',
			ZANA_URL . 'assets/css/chat.css',
			array( 'zdz-app-css' ),
			zana_asset_ver( 'assets/css/chat.css' )
		);
		wp_enqueue_script(
			'zana-chat',
			ZANA_URL . 'assets/js/chat.js',
			array( 'zdz-app-js' ),
			zana_asset_ver( 'assets/js/chat.js' ),
			true
		);
		$uid      = get_current_user_id();
		$is_kiosk = class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $uid );
		wp_localize_script(
			'zana-chat',
			'zanaChat',
			array(
				'apiUrl'    => esc_url_raw( rest_url( ZDZ_REST_NS . '/analytics' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'appId'     => ZANA_APP_ID,
				'isKiosk'   => (bool) $is_kiosk,
				// Async turn queue: submit → poll, so a slow turn cannot 502. The client
				// falls back to the sync /chat route if this is false or if enqueue fails.
				'async'     => (bool) apply_filters( 'zana_async_enabled', true ),
				'pollMs'    => 1500,
				// Headroom over the model gateway's worst-case 180s blocking timeout so a
				// slow-but-completing turn still resolves via a poll before the client caps.
				'maxPollMs' => 210000,
				'i18n'    => array(
					'title'       => __( 'Chat', 'zorderz' ),
					'placeholder' => __( 'Ask about your data…', 'zorderz' ),
					'send'        => __( 'Send', 'zorderz' ),
					'thinking'    => __( 'Thinking…', 'zorderz' ),
					'kioskNote'   => __( 'Read-only on this shared device.', 'zorderz' ),
					'empty'       => __( 'Ask a question to get started.', 'zorderz' ),
					'timeout'     => __( 'This is taking longer than usual — your answer will appear in this conversation when it\'s ready.', 'zorderz' ),
					'error'       => __( 'Something went wrong. Please try again.', 'zorderz' ),
				),
			)
		);
	},
	20
);

// ── Dashboard app registration (theme interface — after_setup_theme) ──
add_action(
	'after_setup_theme',
	function () {
		if ( ! interface_exists( '\Zorderz\App_Interface' ) ) {
			return;
		}
		require_once ZANA_DIR . 'includes/class-zana-app.php';
		add_filter(
			'zdz_register_apps',
			function ( $apps ) {
				if ( class_exists( 'ZANA_App' ) ) {
					$apps[ ZANA_APP_ID ] = new ZANA_App();
				}
				return $apps;
			}
		);
	},
	20
);

// ── Expose this module's marker tokens via one map (never a literal) ──
add_action(
	'plugins_loaded',
	function () {
		add_filter(
			'zdz_chat_markers',
			function ( $markers ) {
				if ( ! is_array( $markers ) ) {
					$markers = array();
				}
				return array_merge( $markers, ZANA_Markers::map() );
			}
		);
	},
	21
);

/**
 * Declare this module's legacy→current rename map to the platform migration. Plugins
 * DECLARE; the theme's ZDZ_Rename_Migration performs the renames in one place. A
 * fresh Zorderz install has no legacy rows, so every entry no-ops. Data is never
 * seeded — only renamed if present.
 */
add_filter(
	'zdz_rename_map',
	function ( $map ) {
		$map['tables'] = array_merge(
			$map['tables'] ?? array(),
			array(
				'tsa_sessions'      => 'zana_sessions',
				'tsa_messages'      => 'zana_messages',
				'tsa_memory'        => 'zana_memory',
				'tsa_cache'         => 'zana_cache',
				'tsa_company_facts' => 'zana_company_facts',
			)
		);
		$map['options'] = array_merge(
			$map['options'] ?? array(),
			array(
				'tsa_version'          => 'zana_version',
				'tsa_db_version'       => 'zana_db_version',
				'tsa_ai_model'         => 'zdz_model_slot_chat', // model routing now lives in ZDZ_Model_Registry
				'tsa_rule_manifest_mode' => 'zana_rule_manifest_mode',
			)
		);
		$map['cron'] = array_merge(
			$map['cron'] ?? array(),
			array(
				'tsa_heartbeat' => 'zana_heartbeat',
			)
		);
		$map['app_ids'] = array_merge(
			$map['app_ids'] ?? array(),
			array(
				'ts-sales-analytics' => ZANA_APP_ID, // the folder renamed; the app id is unchanged
			)
		);
		return $map;
	}
);
