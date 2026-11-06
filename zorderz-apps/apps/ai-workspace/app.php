<?php
/**
 * Module: Zorderz - Ai Workspace
 * Description: A conversation with the business's assistant, run by the assistant engine beside the Charts hub. Each
 *   message is one turn: this site codes every customer detail, books the most the answer may cost in the platform's
 *   Ai ledger BEFORE anything is sent, and hands the hub a single-use link to the sealed turn; the hub pulls it once,
 *   asks this site's own apps through sealed read-only tool calls (run as the person who asked), and reports back its
 *   coded answer and its real cost. Only people who hold the workspace use it; never the shared device or an account
 *   in Privacy View; a staging copy never calls the hub.
 * Version:     1.0.0
 * Author:      Zorderz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 8.0
 *
 * This is a bundled app module (loaded by zorderz-apps.php), not a standalone plugin. It registers with the theme
 * through the `zdz_register_apps` filter on after_setup_theme and declines cleanly when the theme is absent.
 *
 * CORE-SERVICE BINDINGS
 *   - Coding      : ZDZ_Private::for( 'assistant' ) (customer details), ZDZ_Sealed::cut (gate codes). No coder: no turn.
 *   - Money       : ZDZ_LLM_Ledger holds and rows; the business's budgets and the router's models, prices and region
 *                   from ZDZ_Core_LLM (nothing here names a model or a price). No budget: no turn.
 *   - The link    : the Charts app's hub client and envelope (ZCH_Hub_Client, ZCH_Envelope, ZCH_Config), guarded:
 *                   without Charts, or off the live host, the workspace says so and sends nothing.
 *   - Who         : ZAW_Grant (owner-level self-grant; ZDZ_User_Roles / ZDZ_Hierarchy / ZDZ_Capabilities for the shared
 *                   device; ZDZ_Privacy_View), ZDZ_Consent for the per-person usage record.
 *   - Site tools  : ZDZ_Capabilities read verbs, run as the turn's person.
 *   - Staging     : ZDZ_Staging_Guard: a copy never calls the hub.
 *
 * ── What lives where ──────────────────────────────────────────────────
 *   includes/class-zaw-db.php        the two tables (conversations, turns)
 *   includes/class-zaw-settings.php  the per-answer cap, how many may hold it, effort, web search
 *   includes/class-zaw-grant.php     who may use it
 *   includes/class-zaw-hub.php       the relay through the Charts hub client; the envelope for the hub's calls here
 *   includes/class-zaw-money.php     holds, the turn's ledger row, the engine's month
 *   includes/class-zaw-tools.php     site tools and the endpoint where the hub asks them
 *   includes/class-zaw-turns.php     one turn, from the page to the hub and back
 *   includes/class-zaw-rest.php      the routes
 *   includes/class-zaw-admin.php     Zorderz → Ai Workspace (the screen and its settings)
 *   includes/class-zaw-app.php       the dashboard tile (required after the theme)
 *
 * Ships EMPTY: no grants, no instructions, no conversations. Nothing is sent until an owner-level account turns the
 * workspace on for itself and the business has a Charts hub, an Ai budget, a model with prices and a key.
 *
 * @package Zorderz\AiWorkspace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZAW_VERSION', '1.0.0' );
define( 'ZAW_DB_VERSION', '1' );
define( 'ZAW_FILE', __FILE__ );
define( 'ZAW_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZAW_URL', plugin_dir_url( __FILE__ ) );
define( 'ZAW_APP_ID', 'ai-workspace' );

/** Content-keyed asset version: module version + file mtime. */
function zaw_asset_ver( $rel ) {
	$mtime = @filemtime( ZAW_DIR . ltrim( (string) $rel, '/' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	return $mtime ? ZAW_VERSION . '.' . $mtime : ZAW_VERSION;
}

require_once ZAW_DIR . 'includes/class-zaw-db.php';
require_once ZAW_DIR . 'includes/class-zaw-settings.php';
require_once ZAW_DIR . 'includes/class-zaw-grant.php';
require_once ZAW_DIR . 'includes/class-zaw-hub.php';
require_once ZAW_DIR . 'includes/class-zaw-money.php';
require_once ZAW_DIR . 'includes/class-zaw-tools.php';
require_once ZAW_DIR . 'includes/class-zaw-turns.php';
require_once ZAW_DIR . 'includes/class-zaw-rest.php';
require_once ZAW_DIR . 'includes/class-zaw-admin.php';

/* ── The two schedules: the sweep that finishes turns whose page was closed, and the daily prune ── */

add_filter(
	'cron_schedules',
	function ( $s ) {
		if ( ! isset( $s['zaw_5min'] ) ) {
			$s['zaw_5min'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 minutes (Ai Workspace)' );
		}
		return $s;
	}
);

function zaw_schedule() {
	if ( ! wp_next_scheduled( 'zaw_sweep' ) ) {
		wp_schedule_event( time() + 300, 'zaw_5min', 'zaw_sweep' );
	}
	if ( ! wp_next_scheduled( 'zaw_prune' ) ) {
		wp_schedule_event( time() + 3600, 'daily', 'zaw_prune' );
	}
}

/** Activation (called by the apps bundle's activator through the manifest entry). Never seeds business data. */
function zaw_activate() {
	ZAW_DB::install();
	zaw_schedule();
}

/** Deactivation: stop the schedules. */
function zaw_deactivate() {
	wp_clear_scheduled_hook( 'zaw_sweep' );
	wp_clear_scheduled_hook( 'zaw_prune' );
}

add_action(
	'plugins_loaded',
	function () {
		ZAW_DB::maybe_install();             // an app replaced by upload is not re-activated
		ZAW_REST::init();
		ZAW_Admin::init();
		ZAW_Money::init();
		add_action( 'zaw_sweep', array( 'ZAW_Turns', 'sweep' ) );
		add_action( 'zaw_prune', array( 'ZAW_Turns', 'prune' ) );
	},
	20
);
add_action( 'init', 'zaw_schedule' );

/**
 * The per-person usage on each turn (its cost and tokens) is a usage record under the platform's one consent. While
 * the consent card is off it is kept; once it is on, only for people who agreed. The Ai ledger's row for the turn
 * (numbers, no person) is always written: it is how the monthly budget sees the spend.
 */
add_filter(
	'zdz_consent_scopes',
	function ( $s ) {
		if ( is_array( $s ) && ! isset( $s['assistant_usage'] ) ) {
			$s['assistant_usage'] = __( 'What each Ai Workspace answer cost you (dollars and tokens only, never the words).', 'zorderz' );
		}
		return $s;
	}
);

/* ── Dashboard tile (theme interface: after_setup_theme) ── */
add_action(
	'after_setup_theme',
	function () {
		if ( ! interface_exists( '\Zorderz\Widget_App_Interface' ) ) {
			return; // a missing or older theme: degrade, never fatal.
		}
		require_once ZAW_DIR . 'includes/class-zaw-app.php';
		ZAW_App::init();
	},
	20
);

/**
 * Earlier option, table and cron names, carried across by the platform's rename migration. A fresh install has none
 * of them, so every entry is a no-op there.
 */
add_filter(
	'zdz_rename_map',
	function ( $map ) {
		$map['tables']  = array_merge( $map['tables'] ?? array(), array( 'tsaw_convs' => 'zaw_convs', 'tsaw_turns' => 'zaw_turns' ) );
		$map['options'] = array_merge(
			$map['options'] ?? array(),
			array(
				'tsaw_grants'     => 'zaw_grants',
				'tsaw_db_version' => 'zaw_db_version',
			)
		);
		$map['cron']    = array_merge( $map['cron'] ?? array(), array( 'tsaw_sweep' => 'zaw_sweep', 'tsaw_prune' => 'zaw_prune' ) );
		return $map;
	}
);
