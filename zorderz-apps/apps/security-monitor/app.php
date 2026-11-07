<?php
/**
 * Module: Zorderz - Security Monitor
 * Description: A self-hosted dependency and vulnerability monitor for the site. It lists every
 *   installed plugin, theme and WordPress core version; flags what is out of date, what has
 *   drifted from the version this site last ran, and your own code reaching the public update
 *   channel; reads your own code once a day for the common security mistakes; and, when the
 *   optional vulnerability feed (Wordfence Intelligence, free tier) is connected, matches the
 *   installed third-party components and core against it. A severity-ranked digest goes to the
 *   chosen administrators through the alert router. Installs no third-party security plugin.
 * Version:     1.0.0
 * Author:      Zorderz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 8.0
 *
 * This is a bundled app module (loaded by zorderz-apps.php), not a standalone plugin.
 *
 * NO DASHBOARD TILE. It never registers with the `zdz_register_apps` filter. The monitor is an
 * administrator's tool: its console is a wp-admin screen (Zorderz → Security Monitor), its
 * summary is a wp-admin dashboard widget, and what needs a person reaches the chosen
 * administrators as an alert (the in-app bell and e-mail) through ZDZ_Alert_Router. A launcher
 * tile would sit in front of every role and on the shared device for a screen only an
 * administrator may open.
 *
 * WHAT IT SHIPS
 *   ZSM             settings, the feed key (through the credential layer), the schedule, the daily run
 *   ZSM_Policy      the decisions, as pure functions: host budget, download gate, backoffs,
 *                   freshness, scheduling, digest de-duplication, key redaction
 *   ZSM_Inventory   installed components from WordPress's own APIs; which of them are your own
 *   ZSM_Currency    out of date, version drift, supply chain (no feed needed)
 *   ZSM_Feed        the optional feed: streamed download, resumable reading, atomic table swap
 *   ZSM_Feed_Stream a reader for one very large JSON document, one record at a time
 *   ZSM_Matcher     version-range matching of installed components against the cached feed
 *   ZSM_Findings    the findings store (open / acknowledged / resolved, resolves by itself)
 *   ZSM_Codescan    the daily check of your own code (PHP tokenizer; no shell, no outside call)
 *   ZSM_Code        import of an outside code review report (Plugin Check, Semgrep)
 *   ZSM_Report      the dashboard widget and the digest
 *   ZSM_Admin       the console
 *   ZSM_CLI         wp zsm status | scan | sync | abort-sync | codescan | digest | baseline |
 *                   import-code | list-findings
 *
 * SAFETY FLOOR (never weakened): administrators only, never the shared device; every console
 * action nonce- and capability-checked; off until an administrator arms it; the feed key read
 * only through ZDZ_Core_Settings::get_credential(), stored sealed, never printed, logged or
 * returned, and sent only in the request header to the feed provider; the only outside address
 * ever contacted is the feed's; a failed or partial download never replaces the last good copy.
 *
 * @package Zorderz\Security_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZSM_DIR', plugin_dir_path( __FILE__ ) );

require_once ZSM_DIR . 'includes/class-zsm-policy.php';
require_once ZSM_DIR . 'includes/class-zsm.php';
require_once ZSM_DIR . 'includes/class-zsm-db.php';
require_once ZSM_DIR . 'includes/class-zsm-inventory.php';
require_once ZSM_DIR . 'includes/class-zsm-currency.php';
require_once ZSM_DIR . 'includes/class-zsm-feed-stream.php';
require_once ZSM_DIR . 'includes/class-zsm-feed.php';
require_once ZSM_DIR . 'includes/class-zsm-matcher.php';
require_once ZSM_DIR . 'includes/class-zsm-findings.php';
require_once ZSM_DIR . 'includes/class-zsm-report.php';
require_once ZSM_DIR . 'includes/class-zsm-code.php';
require_once ZSM_DIR . 'includes/class-zsm-codescan.php';
require_once ZSM_DIR . 'includes/class-zsm-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once ZSM_DIR . 'includes/class-zsm-cli.php';
}

add_action( 'plugins_loaded', array( 'ZSM', 'boot' ), 20 );

/** Activation (delegated by the apps bundle): create the tables. Nothing is seeded or scheduled. */
function zsm_activate() {
	ZSM::activate();
}

/** Deactivation: unschedule everything and drop an in-progress download. Findings and settings stay. */
function zsm_deactivate() {
	ZSM::deactivate();
}

/**
 * The feed key's row is a credential: never part of a data export, never written by an import.
 */
add_filter(
	'zdz_export_secret_option_names',
	function ( $names ) {
		$names   = is_array( $names ) ? $names : array();
		$names[] = ZSM::KEY_OPTION;
		return $names;
	}
);

