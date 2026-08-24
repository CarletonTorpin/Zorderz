<?php
/**
 * Module: Zorderz - Dot Plot
 * Description: A dictation-driven dot plot of any REGISTERED Flow event, for any row entity,
 *   over any window. The app is a thin shell: the grid renderer, the dictation UI, and an AI
 *   binder onto the shared gateway. All of the security-bearing work lives in the theme's
 *   ZDZ_Report_Sources / ZDZ_Report_Spec — an un-allow-listed source/entity/filter is refused
 *   before any query runs, a money source is never gathered for an unentitled viewer, and the
 *   grid reads only the Flow event outbox (never a provider API on the read path). Attribution
 *   is reused from Commission so the plot and the ledger agree by construction.
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
 * ── CORE-SERVICE BINDINGS (identity is READ from the theme, never hardcoded) ──
 *   - Report sources : ZDZ_Report_Sources (registry + spec validator + outbox reader).
 *   - Flow outbox    : wp_zdz_flow_outbox (the Wave-B Zdz_Flow writer's event outbox).
 *   - Entitlement    : ZDZ_Data_Permissions::can(..,'view_company_revenue') for money sources.
 *   - App gate       : ZDZ_Plugin_API::user_can_access_app.
 *   - AI             : ZDZ_Model_Registry::gateway() (shared ZDZ_Core_Poe) — bound BY NAME,
 *                      the app never passes an API key.
 *
 * ── PORTED AS A DELETION (S8-03) ──────────────────────────────────────
 * The TS five-Poe-key rotation is GONE: no key list, no rotation on 402, no remembered-option,
 * no upgrade-clear. Single-credential is the Zorderz invariant; the app just calls the gateway.
 * Only the honest-diagnostic discipline (option NAMES / lengths / 8-char digests, never values)
 * is kept — see ZDP_Ai::diagnostics().
 *
 * SOURCE VOCABULARY: this app ships NONE. The sale chain / stage names are Identity (flows), and
 * a source is contributed via the theme's `zdz_report_sources` filter by the Connections
 * importer or an Identity pack — so the grid renders whatever is registered, and nothing is baked.
 *
 * @package Zorderz\DotPlot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ──────────────────────────────────────────────────────
define( 'ZDP_VERSION', '1.0.0' );
define( 'ZDP_FILE', __FILE__ );
define( 'ZDP_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZDP_URL', plugin_dir_url( __FILE__ ) );
define( 'ZDP_NONCE', 'zdp_nonce' );
define( 'ZDP_APP_ID', 'dotplot' );

/**
 * Content-keyed asset version: module version + file mtime, so a byte change to a CSS/JS asset
 * busts caches even within one version.
 */
function zdp_asset_ver( $rel ) {
	$abs   = ZDP_DIR . ltrim( (string) $rel, '/' );
	$mtime = @filemtime( $abs );
	return $mtime ? ZDP_VERSION . '.' . $mtime : ZDP_VERSION;
}

// ── Load the plumbing (this app OWNS its own require block) ─────────
// class-zdp-app.php implements the theme interface and is required later, inside
// after_setup_theme, once \Zorderz\Widget_App_Interface exists.
require_once ZDP_DIR . 'includes/class-zdp-ai.php';
require_once ZDP_DIR . 'includes/class-zdp-rest.php';

/**
 * Activation (called by the zorderz-apps bundle activator via the manifest entry). The dot-plot
 * owns NO tables — it reads the jobs app's Flow outbox — so activation only records the version.
 * No business data is ever seeded.
 */
function zdp_activate() {
	update_option( 'zdp_version', ZDP_VERSION, false );
}

/** Deactivation — nothing scheduled, nothing to tear down. */
function zdp_deactivate() {}

// ── Boot ───────────────────────────────────────────────────────────
add_action(
	'plugins_loaded',
	function () {
		ZDP_Rest::init();
	},
	20
);

// ── REST routes (zorderz/v1 via ZDZ_REST_NS) ───────────────────────
add_action( 'rest_api_init', array( 'ZDP_Rest', 'register_routes' ) );

// ── Dashboard app registration (theme interface — after_setup_theme) ──
add_action(
	'after_setup_theme',
	function () {
		if ( ! interface_exists( '\Zorderz\Widget_App_Interface' ) ) {
			return; // degrade gracefully on a missing/older theme — never fatal.
		}
		require_once ZDP_DIR . 'includes/class-zdp-app.php';
		add_filter(
			'zdz_register_apps',
			function ( $apps ) {
				if ( class_exists( 'ZDP_App' ) ) {
					$apps[ ZDP_APP_ID ] = new ZDP_App();
				}
				return $apps;
			}
		);
	},
	20
);

/**
 * Declare this module's legacy TS -> current renames to the platform migration. A fresh Zorderz
 * install has no legacy rows, so every entry no-ops; the map documents the TS lineage and — by
 * carrying NO poe-key option — records that the five-key rotation apparatus was deleted, not
 * ported. Plugins DECLARE; the theme's ZDZ_Rename_Migration performs the renames in one place.
 */
add_filter(
	'zdz_rename_map',
	function ( $map ) {
		$map['options'] = array_merge(
			$map['options'] ?? array(),
			array(
				'tsdotplot_version' => 'zdp_version',
			)
		);
		$map['app_ids'] = array_merge(
			$map['app_ids'] ?? array(),
			array( 'ts-dot-plot' => ZDP_APP_ID )
		);
		return $map;
	}
);
