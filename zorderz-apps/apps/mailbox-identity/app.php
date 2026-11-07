<?php
/**
 * Module: Zorderz - Mailbox Identity
 * Description: One authoritative per-account Exchange identity — primary mailbox + email
 *   aliases + shared/service mailboxes — read by the Scheduler, the Inbox, and login so
 *   every address a person owns resolves to their one account. Additive; dark until
 *   consumers adopt it. NO dashboard tile: this module is a background resolver plus an
 *   admin settings screen only.
 * Version:     1.0.0
 * Author:      Zorderz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 8.0
 *
 * This is a bundled app module (loaded by zorderz-apps.php), not a standalone plugin. It
 * ships NO dashboard tile and never registers with the `zdz_register_apps` filter — there
 * is nothing here for a person to open. It is read by other apps through the resolver's
 * public API and configured through its own Settings screen.
 *
 * WHAT THIS SHIPS:
 *   • ZDZ_Mailbox_Identity — the resolver (address → account; account → mailbox + aliases).
 *                            A clean, platform-level class name — other apps call
 *                            ZDZ_Mailbox_Identity::mailbox_for_user() etc. directly.
 *   • ZMI_Store            — identity user-meta + service-mailbox registry + settings.
 *   • ZMI_Admin            — the backend: profile-screen fields (extends the alias concept),
 *                            a settings page (internal domains, service-mailbox reader
 *                            roles, service mailboxes), and the "Pull from Microsoft" action.
 *   • ZMI_Graph_Fill       — reads proxyAddresses via the REUSED app-only Scheduler token.
 *   • ZMI_Import           — bulk-load identities from a pasted roster; matches each line to
 *                            a WP user (by email, then exact display name), dry-run preview,
 *                            then apply matched only. Ships with an EMPTY default roster —
 *                            no person is pre-loaded for any tenant.
 *   • Write-through mirrors — on save/seed, mirrors the primary into `zsch_mailbox` and
 *     aliases into `zdz_login_aliases`, so per-account CALENDAR targeting and alias LOGIN
 *     work with NO edits to the Scheduler or theme once those consumers read them. The
 *     resolver stays the source of truth. (The Scheduler already reads `zsch_mailbox`;
 *     login does not read `zdz_login_aliases` yet in this build — the mirror write is
 *     harmless and ready for when it does.)
 *
 * WHAT IT DOES NOT DO YET (deliberate):
 *   • It does not change any consumer's behaviour on its own. The Scheduler graph + intake,
 *     Inbox classifier + ingest, and the login bridge each read this resolver behind their
 *     own class_exists() guard, adopted independently — nothing shifts until they do.
 *   • It does not read mail. An app-only service-mailbox reader is a later, separately
 *     flagged step; this module only MODELS a shared/service address as company-owned, and
 *     ships its service-mailbox registry EMPTY — no address is pre-registered.
 *
 * Safe to activate now: it adds an admin surface and a read-only resolver API. No mailbox
 * is touched, no behaviour changes, nothing is sent.
 *
 * @package Zorderz\Mailbox_Identity
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ──────────────────────────────────────────────────────
define( 'ZMI_VERSION', '1.0.0' );
define( 'ZMI_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZMI_URL', plugin_dir_url( __FILE__ ) );

// class_exists-guarded so that if the theme later ships ZDZ_Mailbox_Identity itself as a
// Core service, the theme's copy wins and this module quietly steps aside — no redeclare
// fatal, nothing to coordinate. (require_once alone would still fatal on a same-named class
// loaded from a different path.)
if ( ! class_exists( 'ZDZ_Mailbox_Identity' ) ) {
	require_once ZMI_DIR . 'includes/class-zdz-mailbox-identity.php';
}
if ( ! class_exists( 'ZMI_Store' ) ) {
	require_once ZMI_DIR . 'includes/class-zmi-store.php';
}
if ( ! class_exists( 'ZMI_Graph_Fill' ) ) {
	require_once ZMI_DIR . 'includes/class-zmi-graph-fill.php';
}
if ( ! class_exists( 'ZMI_Import' ) ) {
	require_once ZMI_DIR . 'includes/class-zmi-import.php';
}
if ( ! class_exists( 'ZMI_Admin' ) ) {
	require_once ZMI_DIR . 'includes/class-zmi-admin.php';
}

add_action( 'plugins_loaded', static function () {
	if ( is_admin() && class_exists( 'ZMI_Admin' ) ) {
		ZMI_Admin::register();
	}
} );

