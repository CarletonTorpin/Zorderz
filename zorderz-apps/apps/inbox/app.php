<?php
/**
 * Module: Zorderz — Inbox
 * Description: Per-user mailbox connect + assistant for the Zorderz dashboard; dark by default.
 * Version:     0.9.12
 * Author:      Zorderz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 8.0
 *
 * This is a bundled app module (loaded by zorderz-apps.php), not a standalone
 * plugin. It registers with the theme through the `zdz_register_apps` filter
 * and declines cleanly when the theme is absent.
 *
 * PORTING NOTE: generalized from a private single-tenant "mailbox connect"
 * plugin (through its v0.9.12) into the open-source Zorderz distribution.
 * Behavior carries forward unchanged from that source; only identifiers were
 * renamed to the Zorderz scheme and tenant-owned values (branding, the
 * default internal mail domain) were neutralized. The source's long
 * version-by-version changelog is not reproduced here.
 *
 * WHAT IT DOES: each user connects their own Microsoft 365 mailbox (delegated,
 * read-only Mail.Read — nothing is ever sent or changed); mail is classified
 * internal/external and selectively indexed per the user's own chosen mode
 * (none / employee-employee / customer-employee / all); an owner-scoped,
 * audited Gatekeeper answers search / chat / compute questions over that
 * user's OWN indexed mail only. A mailbox is PERSONAL: unlike some other apps
 * in this bundle, the shared kiosk role is denied here everywhere — there is
 * no read-only seat for mail (see zib_user_has_access() below). Ingestion,
 * search and enrichment are each gated behind their own dark-by-default flag,
 * so installing this module changes nothing until an admin turns it on.
 *
 * DB TABLES (see db/migrate-1.0.0.php .. migrate-1.3.0.php):
 *   wp_zib_accounts       — one row per user's connected mailbox (encrypted token vault)
 *   wp_zib_messages       — indexed messages (body ciphertext; subject/snippet/parties plaintext + FULLTEXT)
 *   wp_zib_participants   — from/to/cc parties per message
 *   wp_zib_seen           — dedupe ledger, incl. excluded (out-of-scope) messages
 *   wp_zib_access_log     — immutable audit trail of every Gatekeeper read
 *   wp_zib_extracts       — enrichment: SUM-able facts (product+qty, money, order refs)
 *   wp_zib_tags           — enrichment: the tag registry (global + per-user, PII-screened)
 *   wp_zib_message_tags   — enrichment: owner-scoped tag assignments
 *
 * @since 0.1.0 (P0 — Connect)
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ──────────────────────────────────────────────────────
define( 'ZIB_VERSION', '0.9.12' );
define( 'ZIB_PLUGIN_FILE', __FILE__ );
define( 'ZIB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZIB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ZIB_NONCE', 'zib_nonce' );

// The dock-tile / app id used by the theme's springboard + role grants.
define( 'ZIB_APP_ID', 'inbox' );

// ── Access / kiosk helpers (STRICT mail posture) ───────────────────
//
// Unlike the Scheduler — which admits the read-only kiosk for busy-only reads —
// Inbox admits NOBODY who cannot write. A mailbox is personal; the shared
// kiosk (zdz_general) is a device, not a person (INV-10). So zib_user_has_access
// itself excludes the kiosk, and there is no read-only path anywhere.

/** Roles that may see the Email tile (non-kiosk). Filterable. */
function zib_roles() {
	return (array) apply_filters( 'zib_roles', array(
		'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_mfg', 'zdz_tech',
	) );
}

/** The read-only (kiosk) roles. */
function zib_read_only_roles() {
	return (array) apply_filters( 'zib_read_only_roles', array( 'zdz_general' ) );
}

/** Is this user a read-only (kiosk) role? */
function zib_user_is_read_only( $user_id = null ) {
	$user_id = ( $user_id && (int) $user_id > 0 ) ? (int) $user_id : get_current_user_id();
	if ( $user_id <= 0 ) {
		return false;
	}
	$u = get_userdata( $user_id );
	if ( ! $u ) {
		return false;
	}
	$ro = array_map( 'strtolower', zib_read_only_roles() );
	foreach ( (array) $u->roles as $role ) {
		if ( in_array( strtolower( (string) $role ), $ro, true ) ) {
			return true;
		}
	}
	return false;
}

/** May this user perform write actions? (Inverse of read-only.) */
function zib_user_can_write( $user_id = null ) {
	return ! zib_user_is_read_only( $user_id );
}

/**
 * Does the user have access to Inbox? STRICT: the kiosk is denied outright
 * (no read-only seat for mail). Otherwise WP admin OR the Zorderz custom cap.
 */
function zib_user_has_access( $user_id = null ) {
	if ( zib_user_is_read_only( $user_id ) ) {
		return false; // kiosk never touches mail
	}
	if ( $user_id && (int) $user_id > 0 ) {
		return user_can( (int) $user_id, 'manage_options' ) || user_can( (int) $user_id, 'zdz_access_app' );
	}
	return current_user_can( 'manage_options' ) || current_user_can( 'zdz_access_app' );
}

// ── Activation ─────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'zib_activate' );

function zib_activate() {
	require_once ZIB_PLUGIN_DIR . 'db/migrate-1.0.0.php';
	ZIB_Migrate_1_0_0::run();
	require_once ZIB_PLUGIN_DIR . 'db/migrate-1.1.0.php';
	ZIB_Migrate_1_1_0::run();
	require_once ZIB_PLUGIN_DIR . 'db/migrate-1.2.0.php';
	ZIB_Migrate_1_2_0::run();
	require_once ZIB_PLUGIN_DIR . 'db/migrate-1.3.0.php';
	ZIB_Migrate_1_3_0::run();
	require_once ZIB_PLUGIN_DIR . 'db/migrate-1.4.0.php';
	ZIB_Migrate_1_4_0::run();
	require_once ZIB_PLUGIN_DIR . 'db/migrate-1.5.0.php';
	ZIB_Migrate_1_5_0::run();
	require_once ZIB_PLUGIN_DIR . 'db/migrate-1.6.0.php';
	ZIB_Migrate_1_6_0::run();
	require_once ZIB_PLUGIN_DIR . 'db/migrate-1.7.0.php';
	ZIB_Migrate_1_7_0::run();
	require_once ZIB_PLUGIN_DIR . 'db/migrate-1.8.0.php';
	ZIB_Migrate_1_8_0::run();
	require_once ZIB_PLUGIN_DIR . 'db/migrate-1.9.0.php';
	ZIB_Migrate_1_9_0::run();
	require_once ZIB_PLUGIN_DIR . 'db/migrate-1.10.0.php';
	ZIB_Migrate_1_10_0::run();
	zib_grant_tile_to_all_eligible_users();
	if ( ! wp_next_scheduled( 'zib_cron_sync' ) ) {
		wp_schedule_event( time() + 120, 'zib_every_ten_minutes', 'zib_cron_sync' );
	}
	update_option( 'zib_db_version', ZIB_VERSION );
}

/** Grant the Email tile to every eligible (non-kiosk) user. Idempotent. */
function zib_grant_tile_to_all_eligible_users() {
	$users = get_users( array( 'fields' => array( 'ID' ), 'capability' => 'zdz_access_app', 'number' => -1 ) );
	foreach ( $users as $u ) {
		zib_grant_tile_to_user( (int) $u->ID );
	}
}

function zib_grant_tile_to_user( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 || ! zib_user_has_access( $user_id ) ) {
		return;
	}
	$denied = get_user_meta( $user_id, 'zdz_denied_apps', true );
	if ( is_array( $denied ) && in_array( ZIB_APP_ID, $denied, true ) ) {
		return;
	}
	$allowed = get_user_meta( $user_id, 'zdz_allowed_apps', true );
	if ( ! is_array( $allowed ) ) {
		$allowed = array();
	}
	if ( ! in_array( ZIB_APP_ID, $allowed, true ) ) {
		$allowed[] = ZIB_APP_ID;
		update_user_meta( $user_id, 'zdz_allowed_apps', $allowed );
	}
}

add_action( 'wp_login', function ( $user_login, $user ) {
	if ( $user instanceof WP_User ) {
		zib_grant_tile_to_user( $user->ID );
	}
}, 10, 2 );

// ── Deactivation (preserve data; stop the sync cron) ───────────────
register_deactivation_hook( __FILE__, 'zib_deactivate' );

function zib_deactivate() {
	wp_clear_scheduled_hook( 'zib_cron_sync' );
	wp_clear_scheduled_hook( 'zib_prime_sync' );
}

// 10-minute cadence for the ingestion puller (plain string label — the
// cron_schedules filter fires before init; __() there trips WP 6.7+ notices).
add_filter( 'cron_schedules', function ( $schedules ) {
	if ( empty( $schedules['zib_every_ten_minutes'] ) ) {
		$schedules['zib_every_ten_minutes'] = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => 'Every 10 minutes (Zorderz Inbox)',
		);
	}
	return $schedules;
} );

// The ingestion tick. Self-gates on the feature flag AND the dark ingest switch.
add_action( 'zib_cron_sync', array( 'ZIB_Ingest', 'cron_all' ) );
add_action( 'zib_prime_sync', array( 'ZIB_Ingest', 'prime_all' ) ); // v0.9.4: fast priming bursts

// ── DB self-heal on load (folder-overwrite installs skip activation) ─
add_action( 'plugins_loaded', 'zib_maybe_upgrade', 5 );

function zib_maybe_upgrade() {
	global $wpdb;
	$db_ver       = get_option( 'zib_db_version', '0' );
	$needs_by_ver = version_compare( $db_ver, ZIB_VERSION, '<' );
	// NOTE: mirrors the source exactly — wp_zib_participants is not in this
	// existence check (only accounts/messages/seen/access_log/extracts/tags/
	// message_tags are), so a missing participants table alone will not
	// trigger this self-heal. Preserved as-is rather than "fixed".
	$need         = array( $wpdb->prefix . 'zib_accounts', $wpdb->prefix . 'zib_messages', $wpdb->prefix . 'zib_seen', $wpdb->prefix . 'zib_access_log', $wpdb->prefix . 'zib_extracts', $wpdb->prefix . 'zib_tags', $wpdb->prefix . 'zib_message_tags' );
	$needs_tables = false;
	foreach ( $need as $t ) {
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
			$needs_tables = true;
			break;
		}
	}

	if ( $needs_by_ver || $needs_tables ) {
		require_once ZIB_PLUGIN_DIR . 'db/migrate-1.0.0.php';
		ZIB_Migrate_1_0_0::run();
		require_once ZIB_PLUGIN_DIR . 'db/migrate-1.1.0.php';
		ZIB_Migrate_1_1_0::run();
		require_once ZIB_PLUGIN_DIR . 'db/migrate-1.2.0.php';
		ZIB_Migrate_1_2_0::run();
		require_once ZIB_PLUGIN_DIR . 'db/migrate-1.3.0.php';
		ZIB_Migrate_1_3_0::run();
		require_once ZIB_PLUGIN_DIR . 'db/migrate-1.4.0.php';
		ZIB_Migrate_1_4_0::run();
		require_once ZIB_PLUGIN_DIR . 'db/migrate-1.5.0.php';
		ZIB_Migrate_1_5_0::run();
		require_once ZIB_PLUGIN_DIR . 'db/migrate-1.6.0.php';
		ZIB_Migrate_1_6_0::run();
		require_once ZIB_PLUGIN_DIR . 'db/migrate-1.7.0.php';
		ZIB_Migrate_1_7_0::run();
		require_once ZIB_PLUGIN_DIR . 'db/migrate-1.8.0.php';
		ZIB_Migrate_1_8_0::run();
		require_once ZIB_PLUGIN_DIR . 'db/migrate-1.9.0.php';
		ZIB_Migrate_1_9_0::run();
		require_once ZIB_PLUGIN_DIR . 'db/migrate-1.10.0.php';
		ZIB_Migrate_1_10_0::run();
		update_option( 'zib_db_version', ZIB_VERSION );
		if ( ! wp_next_scheduled( 'zib_cron_sync' ) ) {
			wp_schedule_event( time() + 120, 'zib_every_ten_minutes', 'zib_cron_sync' );
		}
		if ( $needs_tables && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Zorderz Inbox: tables missing — ran schema migrations on load.' );
		}
	}
}

// ── Load all class files ───────────────────────────────────────────
//
// Glob-loaded, mirroring the house pattern (zsch_load_includes) — EXCEPT
// class-zib-app.php, which is deliberately skipped here and required lazily
// instead (see ZIB_Widget::register_app()). That file `implements
// \Zorderz\Widget_App_Interface`, which does not exist yet this early
// (plugins_loaded fires before the theme's functions.php is even loaded) —
// requiring it here would make it silently no-op AND, because require_once
// dedupes by resolved path, permanently block the later lazy require from
// ever loading it for real. Skipping it here is what keeps the lazy load
// meaningful.
add_action( 'plugins_loaded', 'zib_load_includes' );

function zib_load_includes() {
	$dir = ZIB_PLUGIN_DIR . 'includes/';
	foreach ( glob( $dir . '*.php' ) as $file ) {
		$base = basename( $file );
		// class-zib-app.php and class-zib-connector-microsoft.php each `implement`
		// a \Zorderz\ interface the theme defines later than plugins_loaded, so
		// both are required lazily once the interface exists (see below /
		// ZIB_Widget::register_app()). Requiring them here would no-op them AND,
		// because require_once dedupes by resolved path, permanently block the
		// real lazy require.
		if ( 'class-zib-app.php' === $base || 'class-zib-connector-microsoft.php' === $base ) {
			continue;
		}
		require_once $file;
	}
}

// ── Boot ───────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'zib_boot', 20 );

function zib_boot() {
	// OAuth routes + connection card + REST are runtime-guarded; registering the
	// hooks is free even with the feature flag down.
	if ( class_exists( 'ZIB_OAuth' ) ) {
		ZIB_OAuth::register();
	}
	if ( class_exists( 'ZIB_REST' ) ) {
		ZIB_REST::register();
	}
	if ( class_exists( 'ZIB_Widget' ) ) {
		ZIB_Widget::register();
	}
	if ( is_admin() && class_exists( 'ZIB_Admin' ) ) {
		ZIB_Admin::register();
	}

	// Expose the Microsoft Graph mailbox connector on the Core seam. Deferred to
	// after_setup_theme because \Zorderz\Mailbox_Connector (the theme interface it
	// implements) is not loaded at plugins_loaded. The inbox app IS the Microsoft
	// implementation; registering it on `zdz_mailbox_connectors` lets a future
	// Gmail / IMAP connector slot in beside it with no change to the consumer.
	add_action( 'after_setup_theme', function () {
		if ( ! interface_exists( '\\Zorderz\\Mailbox_Connector' ) ) {
			return;
		}
		require_once ZIB_PLUGIN_DIR . 'includes/class-zib-connector-microsoft.php';
		add_filter( 'zdz_mailbox_connectors', function ( $connectors ) {
			if ( ! is_array( $connectors ) ) {
				$connectors = array();
			}
			if ( class_exists( 'ZIB_Connector_Microsoft' ) ) {
				$connectors['microsoft_graph'] = new ZIB_Connector_Microsoft();
			}
			return $connectors;
		} );
	}, 20 );

	// Per-user "Connected Email" card in the theme's App Authorizations section
	// (mirrors the Scheduler's own `zdz_app_authorizations` card; on a theme
	// that never fires the filter this is a clean no-op). Feature-gated and
	// kiosk-excluded, exactly like the widget.
	add_filter( 'zdz_app_authorizations', function ( $data, $user_id ) {
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		if ( ! class_exists( 'ZIB_Settings' ) || ! ZIB_Settings::feature_enabled() || zib_user_is_read_only( (int) $user_id ) ) {
			return $data;
		}
		$conn          = class_exists( 'ZIB_Connections' ) ? ZIB_Connections::get_for_user( (int) $user_id ) : array();
		$data['email'] = array(
			'connected' => ! empty( $conn ),
			'status'    => $conn['status'] ?? null,
			'mode'      => $conn['index_mode'] ?? null,
		);
		return $data;
	}, 10, 2 );

	// Throttled "ACTIVE — vX" beacon in debug.log (house pattern).
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! get_transient( 'zib_active_logged' ) ) {
		error_log( 'Zorderz Inbox: ACTIVE — v' . ZIB_VERSION );
		set_transient( 'zib_active_logged', 1, HOUR_IN_SECONDS );
	}

	// NOTE: if the theme exposes a hardcoded component-version beacon (some
	// predecessor themes did, as a plain array with no plugin filter), add
	//   'inbox' => 'ZIB_VERSION'
	// to it. ZIB_VERSION is defined above.
}

/**
 * Declare this module's legacy→current rename map to the platform migration.
 *
 * Plugins DECLARE; the theme's ZDZ_Rename_Migration performs the table renames,
 * option-key moves and cron-hook renames in one place. This is what lets a
 * migrating install (tsib_* / TSIB_*) upgrade cleanly in place to the zib_*
 * names. A fresh Zorderz install has no legacy rows, so every entry simply
 * no-ops. Data itself is never seeded here — only renamed if present.
 */
add_filter( 'zdz_rename_map', function ( $map ) {
	$map['tables'] = array_merge( $map['tables'] ?? array(), array(
		'tsib_accounts'      => 'zib_accounts',
		'tsib_messages'      => 'zib_messages',
		'tsib_participants'  => 'zib_participants',
		'tsib_seen'          => 'zib_seen',
		'tsib_access_log'    => 'zib_access_log',
		'tsib_extracts'      => 'zib_extracts',
		'tsib_tags'          => 'zib_tags',
		'tsib_message_tags'  => 'zib_message_tags',
	) );
	$map['options'] = array_merge( $map['options'] ?? array(), array(
		'tsib_config'           => 'zib_config',
		'tsib_ms_secret'        => 'zib_ms_secret',
		'tsib_enabled'          => 'zib_enabled',
		'tsib_ingest_enabled'   => 'zib_ingest_enabled',
		'tsib_enrich_enabled'   => 'zib_enrich_enabled',
		'tsib_internal_domains' => 'zib_internal_domains',
	) );
	// Not explicitly required by the port spec, but same shape/purpose as the
	// entries above (a migrating install's scheduled cron and stored
	// allowed/denied-app grants would otherwise silently orphan under the old
	// names) — see this module's port report for the rationale.
	$map['cron'] = array_merge( $map['cron'] ?? array(), array(
		'tsib_cron_sync'  => 'zib_cron_sync',
		'tsib_prime_sync' => 'zib_prime_sync',
	) );
	$map['app_ids'] = array_merge( $map['app_ids'] ?? array(), array(
		'ts-inbox' => 'inbox',
	) );
	return $map;
} );
