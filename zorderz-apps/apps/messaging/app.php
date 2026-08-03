<?php
/**
 * Plugin Name: Zorderz Messaging
 * Plugin URI:  https://zorderz.org
 * Description: One-to-one DMs and department channels inside the Zorderz Field OS. @mentions, push notifications, inline FreshBooks preview cards. Minimum Effective Product.
 * Version:     1.1.3
 * Author:      Zorderz
 * Text Domain: zdz-internal-messaging
 * Requires PHP: 8.0
 *
 * v1.1.3 (DM-email cooldown — testable + resettable): The v1.1.2 diagnostics
 *   proved the DM→email path works; test DMs simply landed inside the 30-min
 *   per-conversation cooldown (working as designed) and were correctly held back.
 *   To make live testing possible without waiting out or removing the throttle:
 *   - The cooldown now routes through the `zim_email_cooldown_seconds` filter
 *     (default 30 min). Returning 0 disables it entirely — a DM then always emails.
 *   - NEW admin button (Messaging → Channels → "DM email testing"): "Reset DM
 *     email cooldown" clears every zim_last_email_convo_* stamp so the next DM
 *     emails the recipient immediately. Admin-only, nonce-protected, reset-only.
 *   No DB migration; no change to production throttling behaviour by default.
 * v1.1.2 (DM-email outbound diagnostics): Added greppable `ZIM_DM_EMAIL:` debug
 *   logging to the DM-email path — logs when fire_group runs (and which branch),
 *   when the 30-min per-conversation cooldown SUPPRESSES a send, when there's no
 *   email on file, and the wp_mail() result (SENT/FAILED) with recipient + token.
 *   A successful wp_mail() is otherwise silent, which made it impossible to tell
 *   "sent" from "suppressed" when a DM email didn't arrive. Log-only; no behaviour
 *   change; WP_DEBUG-gated. No DB change.
 * v1.1.1 (poll-load reduction — 502 / cache-hit-ratio mitigation): The widget's
 *   real-time polling of admin-ajax.php was the platform's single biggest source
 *   of UNCACHEABLE origin hits (admin-ajax is never page-cached), a top driver of
 *   WP Engine PHP-worker saturation and the frequent 502 Bad Gateways (the origin
 *   cache hit ratio was ~12%, well into WP Engine's "Poor" band). Changes, all
 *   client-cadence only (no behaviour change to messaging itself):
 *   - Poll cadence 3s → 10s (server-provided `pollIntervalMs`, filterable via
 *     `zim_poll_interval_ms`); hard client floor 8s.
 *   - NEW exponential backoff shared across both pollers: a 5xx/failed poll backs
 *     the cadence off ×2 each time up to a 120s cap, then snaps back to base on
 *     the first success — so a struggling origin is given room to recover instead
 *     of being hammered (the old fixed 3s cadence was a feedback loop under load).
 *   - Bottom-nav badge poll 45s → 60s.
 *   No DB change; version bump busts the JS/CSS asset cache.
 * v1.1.0 (two-way email ↔ DM bridge): A DM alert email now contains the ACTUAL
 *   message text (not just "you have a new message") plus a Reply-To address of
 *   app+dm-<token>@<mail-domain>. Replying to that email posts the reply straight
 *   back into the DM as the recipient's message. DMs email the recipient
 *   regardless of push-subscription status (channels keep the old push-only
 *   fallback); quiet hours and the 30-min per-conversation cooldown still apply.
 *   - New: includes/class-zim-email-reply.php (ZIM_Email_Reply) — opaque signed
 *     routing token (HMAC-SHA256, constant-time verify, INV-Token), inbound
 *     detection + handling, quote/signature stripping, and a guarded self-poll
 *     fallback for when the Knowledge Vault mailbox is inactive.
 *   - Inbound transport reuses the Knowledge Vault's Microsoft-Graph poller
 *     (TSKV_Mailbox): the vault reads the shared App@ mailbox and hands DM
 *     replies to us BEFORE turning them into documents, so there is only ever one
 *     reader of the inbox. Sender must equal the intended recipient; the write
 *     still funnels through ZIM_Messages::post() (kiosk refused, INV-1/INV-10).
 *   - Honest output (INV-12): an unroutable reply is dropped + logged and filed
 *     under a "Messaging Failed" mail folder; no backscatter, no faked delivery.
 *   - No DB migration (the token is stateless/self-verifying); version bumped for
 *     asset cache-bust + changelog.
 * v1.0.26 (chat-regression fix): The cron_schedules filter used __() for its
 *   schedule label, which fires before the `init` action and triggered WP 6.7+'s
 *   "_load_textdomain_just_in_time was called incorrectly" PHP notice on EVERY
 *   request. With WP_DEBUG_DISPLAY on, that notice leaked into the TSA chat's
 *   AJAX JSON responses, corrupting them → "Network error creating/loading
 *   session." Fix: the label is now a plain string (admin-only; no early
 *   translation needed). Paired with the analytics app's verify_ajax() JSON-output guard.
 * v1.0.25 (autocorrect): DM people-search field — autocorrect/autocapitalize/
 *   spellcheck off so typed names aren't altered.
 *
 * v1.0.24 (General Account Hardening — messaging lockdown): Read-only role
 *   support for the shared workshop-kiosk account `zdz_general`.
 *   - New predicates: zim_user_is_read_only(), zim_user_can_write(),
 *     zim_read_only_roles() (filterable). zim_user_has_access() now admits
 *     read-only roles so the kiosk can READ #announcements without holding the
 *     broad zdz_access_app capability.
 *   - Every write path refuses read-only users, server-side and deterministically:
 *       · AJAX mutations (post/edit/delete/bulk-delete/upload/dm-open) via the
 *         new gate_write(); admin mutations via gate_admin().
 *       · Model layer: ZIM_Messages::post() and
 *         ZIM_DMs::get_or_create_conversation() hard-refuse read-only actors —
 *         this is the single chokepoint that also closes the Brain-Bot
 *         "post to #channel" REST back-door (ZIM_REST::post_message), the
 *         surface behind the Session 406 autonomous-posting incident.
 *   - UI: the composer footer is NOT rendered into the DOM for the kiosk
 *     (removed, not merely disabled), the "New DM" affordance is hidden, and a
 *     read-only notice is shown where the composer would be. zimData carries
 *     isReadOnly. The widget also refuses the TSA embed auto-send / DM-route
 *     for read-only users (client-side defence-in-depth; the server blocks are
 *     the guarantee).
 *   No DB migration: read-only behaviour is role-derived; #announcements
 *   auto-join already covers the kiosk's read access via seed_defaults().
 *
 * v1.0.22 (Prompt 4C): Font sizing & desktop layout.
 *   M1: Font size bump — name 13→14, meta 12→13, avatar 12→13,
 *       time-sep 12→13, empty 15→16, loading 14→15, label 16→17,
 *       section-hdr 13→14, search 16→17, main-title 18→20.
 *   M3: Desktop breakpoint @820px — max-height 800px, sidebar 300px,
 *       desktop font bumps (bubble 18, name 15, meta 14, label 18,
 *       section-hdr 15, compose 18, logo 20). 1280px+ → 900px.
 *
 * ============================================================================
 * PROGRAMMER NOTES
 * ============================================================================
 * ROLE: Bootstrap. Constants, schema activation, class loading, theme hook.
 *
 * BUSINESS CONTEXT:
 * Zorderz Company (TSC) needed internal team messaging that lives
 * *inside* the Field OS rather than Slack/Teams. The MEP does one-to-one DMs
 * and department channels (#announcements, #sales, #ops, #mfg, #techs) with
 * @mentions, push notifications, and inline previews of FreshBooks references.
 *
 * DELIBERATELY NOT IN MEP (charter — see README "Not in this release"):
 *  - Threaded replies / reactions / typing indicators
 *  - External integrations (Slack bridge, Teams, etc.)
 *  - WebSockets — MEP uses 3-second HTTP polling (Trap 1)
 *  - Cross-conversation search — v1.1
 *
 * REAL-TIME MODEL:
 * HTTP long-polling. One in-flight poll per open conversation + one for the
 * sidebar badges. Total <= 2 concurrent AJAX requests per tab. Upgrade path
 * to WebSockets is v1.2+, not now.
 *
 * SELF-CONTAINED:
 * This plugin uses NO external API clients. FreshBooks preview cards proxy
 * through the analytics app's /zorderz/v1/freshbooks-preview/{id} endpoint when TSA is
 * installed; when not, #NNNNN auto-linking still works via a plain FB search
 * URL, and preview cards degrade gracefully. See Trap 2.
 *
 * CUSTOMER-FACING MODE:
 * If the analytics app's customer-facing mode is active for the current user, this plugin
 * hides entirely — widget returns null, full-page route renders the theme's
 * generic "not available" template. Never a visible refusal. See Trap 5.
 *
 * DB TABLES (see db/migrate-1.0.0.php):
 *  wp_zim_conversations       — channels + DMs, unified namespace
 *  wp_zim_members             — user-to-conversation memberships + read cursor
 *  wp_zim_messages            — message bodies (FULLTEXT on body)
 *  wp_zim_attachments         — file metadata linked to messages
 *  wp_zim_mentions            — mention rows (preserved on soft-delete)
 *  wp_zim_push_subscriptions  — Web Push endpoints, per user + UA
 *  wp_zim_notification_queue  — deferred pushes (quiet hours) + digest log
 * ============================================================================
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ──────────────────────────────────────────────────────
define( 'ZIM_VERSION', '1.1.3' );
define( 'ZIM_PLUGIN_FILE', __FILE__ );
define( 'ZIM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZIM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ZIM_NONCE', 'zim_nonce' );

// Attachment policy (Trap 9).
define( 'ZIM_MAX_UPLOAD_BYTES', 5 * 1024 * 1024 ); // 5 MB
define( 'ZIM_ATTACHMENT_RETENTION_DAYS', 30 );

if ( ! defined( 'ZIM_ALLOWED_MIMES' ) ) {
	define( 'ZIM_ALLOWED_MIMES', wp_json_encode( array(
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'webp'     => 'image/webp',
		'gif'      => 'image/gif',
		'heic'     => 'image/heic',
		'pdf'      => 'application/pdf',
	) ) );
}

// Message edit window (Trap 3 / acceptance #3).
define( 'ZIM_EDIT_WINDOW_SECONDS', 5 * MINUTE_IN_SECONDS );

// Push subscription rotation (Trap 6).
define( 'ZIM_PUSH_ROTATION_DAYS', 90 );

// Email fallback cooldown (acceptance #5).
define( 'ZIM_EMAIL_DIGEST_COOLDOWN_SECONDS', 30 * MINUTE_IN_SECONDS );

// Default quiet hours (user-overridable).
define( 'ZIM_DEFAULT_QUIET_START', '21:00' );
define( 'ZIM_DEFAULT_QUIET_END',   '07:00' );

/**
 * Helper — return the allowed-MIME whitelist as a PHP array every call site
 * can hand straight to wp_handle_upload( …, [ 'mimes' => … ] ).
 */
function zim_allowed_mimes() {
	return json_decode( ZIM_ALLOWED_MIMES, true );
}

/**
 * Roles that get *read-only* access to messaging.
 *
 * v1.0.24 — The shared workshop-kiosk account (`zdz_general`, see the theme's
 * class-zdz-user-roles.php and the "General Account Hardening" how-to) must be
 * able to READ #announcements but must never be able to send, post, DM, edit,
 * delete, upload, create a channel, or create a conversation. The platform's
 * least-privilege design says: define the behaviour once at the role layer and
 * let every surface inherit it. This list is that single source of truth on
 * the messaging side. Filterable so future read-only roles need no code edit.
 *
 * @return string[] lowercase role slugs
 */
function zim_read_only_roles() {
	return (array) apply_filters( 'zim_read_only_roles', array( 'zdz_general' ) );
}

/**
 * Is this user one of the messaging read-only roles (e.g. the shared kiosk)?
 *
 * Read-only users can load the widget and read conversations they belong to
 * (in practice: #announcements, which auto-joins everyone), but every write
 * path refuses them — deterministically, server-side. See zim_user_can_write().
 *
 * @param int|null $user_id  Defaults to current user when null/0.
 * @return bool
 */
function zim_user_is_read_only( $user_id = null ) {
	$user_id = ( $user_id && (int) $user_id > 0 ) ? (int) $user_id : get_current_user_id();
	if ( $user_id <= 0 ) {
		return false;
	}
	$u = get_userdata( $user_id );
	if ( ! $u ) {
		return false;
	}
	$ro = array_map( 'strtolower', zim_read_only_roles() );
	foreach ( (array) $u->roles as $role ) {
		if ( in_array( strtolower( (string) $role ), $ro, true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * May this user perform write actions (post/send/DM/edit/delete/upload/create)?
 *
 * Inverse of zim_user_is_read_only(). Every mutation entry point — AJAX
 * handlers, the REST /post route, and the model-layer ZIM_Messages::post()
 * and ZIM_DMs::get_or_create_conversation() — consults this so that no single
 * forgotten check can re-open a write path for the shared account.
 *
 * @param int|null $user_id  Defaults to current user when null/0.
 * @return bool
 */
function zim_user_can_write( $user_id = null ) {
	return ! zim_user_is_read_only( $user_id );
}

/**
 * Does the given user have access to the messaging plugin?
 *
 * Dual capability check — matches the analytics app's pattern (since v1.9.27):
 *   WordPress admin (manage_options) OR Zorderz custom (zdz_access_app).
 *
 * Use this everywhere instead of raw `current_user_can('zdz_access_app')` so
 * admins who haven't been explicitly granted the custom cap still get access.
 *
 * v1.0.24 — Read-only roles (the shared kiosk `zdz_general`) are admitted here
 * too. The hardening role is defined with only `read` caps and deliberately
 * does NOT carry `zdz_access_app`, yet it must still be able to *read*
 * #announcements. Admitting it at the access gate (while every write path is
 * separately blocked via zim_user_can_write()) is what makes "read the
 * announcements, send nothing" possible without granting the broad app cap.
 * It also lets seed_defaults() auto-join the kiosk to #announcements.
 *
 * @param int|null $user_id  Defaults to current user when null/0.
 * @return bool
 */
function zim_user_has_access( $user_id = null ) {
	if ( zim_user_is_read_only( $user_id ) ) {
		return true;
	}
	if ( $user_id && (int) $user_id > 0 ) {
		return user_can( (int) $user_id, 'manage_options' )
		    || user_can( (int) $user_id, 'zdz_access_app' );
	}
	return current_user_can( 'manage_options' )
	    || current_user_can( 'zdz_access_app' );
}

// ── Activation ─────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'zim_activate' );

function zim_activate() {
	require_once ZIM_PLUGIN_DIR . 'db/migrate-1.0.0.php';
	ZIM_Migrate_1_0_0::run();

	// Seed default channels + auto-join existing users by role.
	require_once ZIM_PLUGIN_DIR . 'includes/class-zim-channels.php';
	ZIM_Channels::seed_defaults();

	// v1.0.2 — make the tile visible to every eligible user on first install.
	// The theme (2.13.x) gates tile visibility behind the per-user
	// `zdz_allowed_apps` meta for non-admin roles. Without this grant, sales /
	// operator / mfg / tech users could activate the plugin and still never
	// see the "Messages" tile. Admin-class roles (zdz_owner, zdz_admin,
	// manage_options) bypass that meta entirely, so they don't need a grant.
	zim_grant_tile_to_all_eligible_users();

	// Schedule crons.
	if ( ! wp_next_scheduled( 'zim_cron_dispatch_notifications' ) ) {
		wp_schedule_event( time() + 60, 'zim_every_minute', 'zim_cron_dispatch_notifications' );
	}
	if ( ! wp_next_scheduled( 'zim_cron_purge_attachments' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'zim_cron_purge_attachments' );
	}
	if ( ! wp_next_scheduled( 'zim_cron_rotate_push_keys' ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'zim_cron_rotate_push_keys' );
	}

	update_option( 'zim_db_version', ZIM_VERSION );
}

/**
 * Grant the `internal-messaging` app to every user with `zdz_access_app`
 * (unless they've been explicitly denied it). Idempotent — safe to call
 * repeatedly. Invoked on activation and on login.
 *
 * Design decision: we do NOT touch users whose role already grants
 * admin-class app visibility (zdz_owner / zdz_admin / manage_options) because
 * the theme's plugin-api shortcircuits them past the allowed_apps check.
 * Adding to their meta is wasted metadata.
 */
function zim_grant_tile_to_all_eligible_users() {
	$users = get_users( array(
		'fields'   => array( 'ID', 'roles' ),
		'capability' => 'zdz_access_app', // honoured on WP 5.9+
		'number'   => -1,
	) );
	foreach ( $users as $u ) {
		zim_grant_tile_to_user( (int) $u->ID );
	}
}

/**
 * Grant to one user. Hooked on wp_login so users created/promoted after
 * messaging was activated also get the tile without a manual re-run.
 */
function zim_grant_tile_to_user( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) return;
	if ( ! zim_user_has_access( $user_id ) ) return;

	$denied = get_user_meta( $user_id, 'zdz_denied_apps', true );
	if ( is_array( $denied ) && in_array( 'internal-messaging', $denied, true ) ) {
		// Admin has explicitly denied messaging for this user — respect that.
		return;
	}
	$allowed = get_user_meta( $user_id, 'zdz_allowed_apps', true );
	if ( ! is_array( $allowed ) ) $allowed = array();
	if ( ! in_array( 'internal-messaging', $allowed, true ) ) {
		$allowed[] = 'internal-messaging';
		update_user_meta( $user_id, 'zdz_allowed_apps', $allowed );
	}
}

// On login, top up the grant for any user who doesn't yet have it.
add_action( 'wp_login', function( $user_login, $user ) {
	if ( $user instanceof WP_User ) {
		zim_grant_tile_to_user( $user->ID );
	}
}, 10, 2 );

// Custom minute cadence for the notification dispatcher.
add_filter( 'cron_schedules', function( $schedules ) {
	if ( empty( $schedules['zim_every_minute'] ) ) {
		$schedules['zim_every_minute'] = array(
			'interval' => 60,
			// v1.0.25: plain string — NOT __(). The cron_schedules filter fires before
			// the `init` action, so a translation call here triggered WP 6.7+'s
			// "_load_textdomain_just_in_time was called incorrectly" PHP notice on EVERY
			// request. That notice was leaking into the TSA chat's AJAX JSON responses
			// (DOING_AJAX guard runs too late to catch a load-time notice), corrupting
			// them → "Network error creating/loading session." The label is admin-only
			// (the WP-Cron schedules screen); it does not need early translation.
			'display'  => 'Every minute (TSIM)',
		);
	}
	return $schedules;
} );

// ── Media Library exclusion ─────────────────────────────────────────
// TSIM chat attachments are stored as private WP attachment posts with
// meta key `_tsim_chat_attachment`. We exclude them from the admin Media
// Library so they don't clutter the shared media grid. Users can still
// view their images through the chat thread where they were shared.
add_action( 'pre_get_posts', function ( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) return;
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'upload' !== $screen->id ) return;
	$meta_query = $query->get( 'meta_query' ) ?: array();
	$meta_query[] = array(
		'key'     => '_tsim_chat_attachment',
		'compare' => 'NOT EXISTS',
	);
	$query->set( 'meta_query', $meta_query );
} );
// Also filter AJAX media queries (used by the Media Library modal/grid).
add_filter( 'ajax_query_attachments_args', function ( $args ) {
	$args['meta_query'] = $args['meta_query'] ?? array();
	$args['meta_query'][] = array(
		'key'     => '_tsim_chat_attachment',
		'compare' => 'NOT EXISTS',
	);
	return $args;
} );

// ── Deactivation ───────────────────────────────────────────────────
register_deactivation_hook( __FILE__, 'zim_deactivate' );

function zim_deactivate() {
	// "Deactivating preserves data" (acceptance #1). Do NOT drop tables.
	wp_clear_scheduled_hook( 'zim_cron_dispatch_notifications' );
	wp_clear_scheduled_hook( 'zim_cron_purge_attachments' );
	wp_clear_scheduled_hook( 'zim_cron_rotate_push_keys' );
	// v1.1.0 — the email↔DM bridge's guarded self-poll fallback.
	wp_clear_scheduled_hook( 'zim_email_reply_self_poll' );
}

// ── DB upgrade on version bump ────────────────────────────────────
add_action( 'plugins_loaded', 'zim_maybe_upgrade', 5 );

function zim_maybe_upgrade() {
	$db_ver = get_option( 'zim_db_version', '0' );
	if ( version_compare( $db_ver, ZIM_VERSION, '<' ) ) {
		require_once ZIM_PLUGIN_DIR . 'db/migrate-1.0.0.php';
		ZIM_Migrate_1_0_0::run();
		update_option( 'zim_db_version', ZIM_VERSION );
		// Flag so the includes-loader re-seeds after classes are available.
		update_option( 'zim_needs_reseed', '1' );
	}
}

// ── Load all class files ───────────────────────────────────────────
add_action( 'plugins_loaded', 'zim_load_includes' );

function zim_load_includes() {
	$dir = ZIM_PLUGIN_DIR . 'includes/';
	foreach ( glob( $dir . '*.php' ) as $file ) {
		require_once $file;
	}

	// Wire cron callbacks after classes are loaded.
	add_action( 'zim_cron_dispatch_notifications', array( 'ZIM_Notifications', 'dispatch_due' ) );
	add_action( 'zim_cron_purge_attachments',      array( 'ZIM_Attachments', 'cron_purge' ) );
	add_action( 'zim_cron_rotate_push_keys',       array( 'ZIM_Push', 'rotate_keys_if_due' ) );

	// v1.0.27 (security): membership-gated attachment streamer. Chat files used to
	// sit at public, enumerable wp-uploads URLs with no access check (IDOR). They are
	// now served ONLY through this login+member+token gate; the browser never receives
	// the raw file path. See ZIM_Attachments::maybe_serve().
	add_action( 'template_redirect', array( 'ZIM_Attachments', 'maybe_serve' ) );

	// v1.1.0: the two-way email ↔ DM bridge. Registers the guarded self-poll
	// fallback (a no-op whenever the Knowledge Vault mailbox is active — the
	// vault is the primary reader and hands DM replies to us). Outbound minting
	// and inbound handling are static and need no wiring here.
	if ( class_exists( 'ZIM_Email_Reply' ) ) {
		ZIM_Email_Reply::boot();
	}

	// Boot the AJAX router + admin page.
	if ( class_exists( 'ZIM_Dashboard' ) ) {
		new ZIM_Dashboard();
	}
	if ( is_admin() && class_exists( 'ZIM_Admin' ) ) {
		new ZIM_Admin();
	}

	// Deferred re-seed: runs after classes are loaded when a version bump
	// flagged the need. Both seed_defaults and tile-grant are idempotent.
	if ( get_option( 'zim_needs_reseed' ) === '1' ) {
		delete_option( 'zim_needs_reseed' );
		if ( class_exists( 'ZIM_Channels' ) ) {
			ZIM_Channels::seed_defaults();
		}
		zim_grant_tile_to_all_eligible_users();
	}

	// Invalidate Web Push subscriptions on logout (Trap 6).
	add_action( 'clear_auth_cookie', array( 'ZIM_Push', 'on_logout' ) );

	// Full-page route — ?zim_page=1 renders the inline widget full-viewport.
	add_action( 'template_redirect', 'zim_maybe_render_full_page' );

	// v1.0.9 — Team bottom-nav tab injector.
	// Enqueues only on the theme's front page (is_front_page), where the
	// .bnav bottom nav lives. Skipped on admin, on the messaging full-page
	// route itself (would cause recursion), and for logged-out users.
	add_action( 'wp_enqueue_scripts', 'zim_enqueue_nav_inject' );
}

function zim_enqueue_nav_inject() {
	if ( is_admin() ) return;
	if ( ! is_user_logged_in() ) return;
	if ( ! empty( $_GET['zim_page'] ) ) return; // don't inject into ourselves
	// v1.0.21: Removed the is_front_page()/is_home() check. On some WP
	// configurations (no static front page set, or index.php fallback),
	// these conditionals return false even though the SPA is rendering.
	// The JS itself guards on (#view-main + .bnav) existing, so the
	// enqueue is safe on any front-end page — the script no-ops if the
	// SPA shell isn't in the DOM.
	if ( ! function_exists( 'zim_user_has_access' ) || ! zim_user_has_access() ) return;

	wp_enqueue_style(
		'zim-nav-inject',
		ZIM_PLUGIN_URL . 'assets/css/nav-inject.css',
		array(),
		ZIM_VERSION
	);
	wp_enqueue_script(
		'zim-nav-inject',
		ZIM_PLUGIN_URL . 'assets/js/nav-inject.js',
		array(),
		ZIM_VERSION,
		true
	);
	wp_localize_script( 'zim-nav-inject', 'zimNavData', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( ZIM_NONCE ),
	) );
}

/**
 * v1.0.2 — Register REST routes (cross-plugin coordination surfaces).
 * Runs only on REST requests — zero cost on other page loads.
 */
add_action( 'rest_api_init', function () {
	if ( class_exists( 'ZIM_REST' ) ) {
		ZIM_REST::register_routes();
	}
} );

/**
 * Full-page view handler for the Tier 1 fallback and direct deep-links.
 *
 * Pattern: /?zim_page=1 — theme bridge opens this in its iframe/app shell.
 * Applies the same customer-facing hide as the inline widget (Trap 5).
 */
function zim_maybe_render_full_page() {
	if ( empty( $_GET['zim_page'] ) ) {
		return;
	}
	if ( ! is_user_logged_in() || ! zim_user_has_access() ) {
		zim_render_unavailable();
		exit;
	}

	// Hard-block under customer-facing mode. Never leak existence.
	if ( ! ZIM_Widget::should_render() ) {
		zim_render_unavailable();
		exit;
	}

	// Delegate to the widget renderer, but in full-page chrome.
	$widget = new ZIM_Widget();
	status_header( 200 );
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );

	$html = $widget->render_dashboard_widget( get_current_user_id(), 'fullpage' );
	if ( null === $html ) {
		zim_render_unavailable();
		exit;
	}

	$is_embed = ! empty( $_GET['zdz_embed'] );

	// v1.0.9 — respect user's theme preference even in embed mode.
	// The theme stores preference in localStorage.zdz_theme (client-side only),
	// so we can't read it from PHP. Instead we render a tiny inline script
	// that reads localStorage on iframe boot and sets data-theme before the
	// stylesheet paints, avoiding a flash of wrong colors.
	$initial_theme = 'system'; // default fallback

	echo '<!doctype html><html data-theme="' . esc_attr( $initial_theme ) . '"><head><meta charset="utf-8">';
	echo '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">';
	echo '<title>Messaging</title>';
	// Inline theme-sync script — runs before any CSS paint, no FOUC.
	echo '<script>(function(){try{var t=localStorage.getItem("zdz_theme");if(t){document.documentElement.setAttribute("data-theme",t);}}catch(e){}})();</script>';
	if ( $is_embed ) {
		// Embed mode: skip theme admin bar/footer. Include theme token CSS
		// for dark mode, but NOT app.css (which sets body{visibility:hidden}
		// pending a zdz-ready class our code never adds).
		echo '<style>';
		echo "html,body{margin:0;padding:0;height:100%;overflow:hidden;background:var(--sys-bg,transparent);";
		echo "font-family:'Inter',system-ui,-apple-system,sans-serif;";
		echo "-webkit-font-smoothing:antialiased;color:var(--sys-text,#e2e8f0);}";
		echo '.zim-fullpage .zim-w{height:100vh;max-height:100vh;border:0;border-radius:0;}';
		echo '</style>';
		$theme_css_url = get_stylesheet_uri();
		if ( $theme_css_url ) {
			echo '<link rel="stylesheet" href="' . esc_url( $theme_css_url ) . '">';
		}
		wp_print_styles( array( 'zim-widget-css' ) );
		wp_print_scripts( array( 'marked-js', 'dompurify', 'zim-widget-js' ) );
	} else {
		// Standalone full-page: include theme assets so tokens resolve.
		wp_head();
	}
	echo '</head><body class="zim-fullpage' . ( $is_embed ? ' zim-embed' : '' ) . '">';
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	if ( ! $is_embed ) {
		wp_footer();
	}
	echo '</body></html>';
	exit;
}

/**
 * Render the theme's "not available" fallback template. Identical to the
 * dashboard's empty-apps state — reveals NOTHING about this plugin's
 * existence. Used for customer-facing hide and for unauthenticated access.
 */
function zim_render_unavailable() {
	status_header( 200 ); // NOT 403 — 403 leaks existence (Trap 5 / acceptance #4).
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><html><head><meta charset="utf-8"><title>Not available</title>';
	echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
	echo '<style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;color:#64748b;background:#f8fafc}p{max-width:28ch;text-align:center}</style>';
	echo '</head><body><p>This feature isn\'t available right now.</p></body></html>';
}

/**
 * ────────────────────────────────────────────────────────
 * ZORDERZ THEME INTEGRATION — Tier 2 widget + Tier 1 iframe fallback.
 * ────────────────────────────────────────────────────────
 *
 * MUST run inside after_setup_theme — the theme's interfaces aren't defined
 * until then (Gotcha #1 of the ecosystem ref; Theme v2.10.1 convention).
 */
add_action( 'after_setup_theme', function() {

	// Ensure widget class is available for registration.
	if ( ! class_exists( 'ZIM_Widget' ) ) {
		// Classes are normally loaded by zim_load_includes() on plugins_loaded(5),
		// but after_setup_theme fires after plugins_loaded, so they are already in.
		return;
	}

	// ── TIER 2 — inline widget (theme v2.0+) ──
	if ( interface_exists( '\Zorderz\Widget_App_Interface' ) ) {

		class ZIM_App implements \Zorderz\Widget_App_Interface {

			public function get_config(): array {
				return array(
					'id'          => 'internal-messaging',
					'nm'          => 'Messages',
					'icon'        => 'message-square',
					'cat'         => 'Team',
					'cc'          => '#3B82F6',
					'desc'        => 'Team messaging, channels, @mentions.',
					// v1.0.24 — zdz_general (shared kiosk) is included so the
					// Messages tile is visible on the workshop iPad. Its access
					// is read-only: it can open the widget and read
					// #announcements, but every write path refuses it
					// (see zim_user_can_write()).
					'roles'       => array( 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_mfg', 'zdz_tech', 'zdz_general' ),
					'bridge_type' => 'inline_widget',
					'admin_url'   => home_url( '/?zim_page=1' ),
				);
			}

			public function render_mobile_view( int $user_id ): void {
				// Tier-2 deployments use the inline widget; mobile view is only hit
				// if the theme bridge routes here directly. Redirect to full-page.
				echo '<iframe src="' . esc_url( home_url( '/?zim_page=1&zdz_mobile=1' ) ) . '" style="width:100%;height:100%;border:none;" title="Messages"></iframe>';
			}

			public function render_dashboard_widget( int $user_id ): ?string {
				// Customer-facing hard-block (Trap 5 / acceptance #4).
				if ( ! ZIM_Widget::should_render() ) {
					return null;
				}
				$widget = new ZIM_Widget();
				return $widget->render_dashboard_widget( $user_id, 'inline' );
			}
		}

		add_filter( 'zdz_register_apps', function( $apps ) {
			$apps['internal-messaging'] = new ZIM_App();
			return $apps;
		} );

	// ── TIER 1 — standard iframe tile (theme v1.x) ──
	} elseif ( interface_exists( '\Zorderz\App_Interface' ) ) {

		class ZIM_App implements \Zorderz\App_Interface {

			public function get_config(): array {
				return array(
					'id'          => 'internal-messaging',
					'nm'          => 'Messages',
					'icon'        => 'message-square',
					'cat'         => 'Team',
					'cc'          => '#3B82F6',
					'desc'        => 'Team messaging.',
					// v1.0.24 — see Tier 2 note: kiosk gets read-only access.
					'roles'       => array( 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_mfg', 'zdz_tech', 'zdz_general' ),
					'bridge_type' => 'iframe',
					'admin_url'   => home_url( '/?zim_page=1' ),
				);
			}

			public function render_mobile_view( int $user_id ): void {
				echo '<iframe src="' . esc_url( home_url( '/?zim_page=1&zdz_mobile=1' ) ) . '" style="width:100%;height:100%;border:none;" title="Messages"></iframe>';
			}
		}

		add_filter( 'zdz_register_apps', function( $apps ) {
			$apps['internal-messaging'] = new ZIM_App();
			return $apps;
		} );
	}
} );

/**
 * Register this plugin's rename map with the platform migration.
 * Plugins declare; the kernel migrates. No per-plugin migration code.
 */
add_filter( 'zdz_rename_map', function ( $map ) {
		$map['tables'] = array_merge( $map['tables'] ?? [], [
			'tsim_conversations' => 'zim_conversations',
			'tsim_members' => 'zim_members',
			'tsim_messages' => 'zim_messages',
			'tsim_attachments' => 'zim_attachments',
			'tsim_mentions' => 'zim_mentions',
			'tsim_push_subscriptions' => 'zim_push_subscriptions',
			'tsim_notification_queue' => 'zim_notification_queue',
		] );
		$map['options'] = array_merge( $map['options'] ?? [], [
			'tsim_db_version' => 'zim_db_version',
			'tsim_needs_reseed' => 'zim_needs_reseed',
		] );
		$map['cron'] = array_merge( $map['cron'] ?? [], [
			'tsim_cron_dispatch_notifications' => 'zim_cron_dispatch_notifications',
			'tsim_cron_purge_attachments' => 'zim_cron_purge_attachments',
			'tsim_cron_rotate_push_keys' => 'zim_cron_rotate_push_keys',
			'tsim_email_reply_self_poll' => 'zim_email_reply_self_poll',
		] );
	return $map;
} );
