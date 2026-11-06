<?php
/**
 * Plugin Name: Zorderz Messaging
 * Plugin URI:  https://zorderz.org
 * Description: One-to-one direct messages and team channels inside the Zorderz Field OS. @mentions, phone notifications held to quiet hours, estimate and invoice cards shared one document at a time.
 * Version:     1.9.0
 * Author:      Zorderz
 * Text Domain: zdz-internal-messaging
 * Requires PHP: 8.0
 *
 * 1.9.0
 *   - zim_queue_notice (ZIM_Notices): another app's notice, queued as one push and released after the person's quiet
 *     hours (their own, else the business's, else Core's: ZDZ_Quiet_Hours); several released together become one
 *     digest. Never the shared device, a read-only role, someone without Messages, or an account in Privacy View.
 *   - Push belongs to a device: signing out removes only that device's subscription (session_hash). The read verb
 *     messaging.push_setup answers Settings -> Notifications; zim_push_test sends a test; the widget's bell runs the
 *     theme's shared routine.
 *   - Estimates and invoices in a message (ZIM_Doc_Shares, wp_zim_doc_grants): a #NNNNN opens the document, read
 *     only, for its own salesperson, an administrator, or someone its sender shared that one document with. "invoice
 *     #N" means the invoice; any other #N the estimate. Cards never link out to the billing system.
 *   - Editing in place; shared-media gallery cards (ZIM_Media_Cards); vault documents open inside the app; a link in
 *     a message never navigates the messenger frame.
 *   - ZIM_Privacy_View: an account in Privacy View messages only the administrator chosen for it and sees only that
 *     thread. zim_dm_send (ZIM_DM_Send): the seam another app sends a direct message through. The compact one-thread
 *     view the chat docks (?zim_compact=1). messaging.unread_counts. Usage actions through ZDZ_Usage::record().
 *   - Core seeds #announcements only; the business's channels come from an administrator or its Identity Pack
 *     (org.yml messaging.channels).
 * 1.1.8 (asset cache refresh): no behaviour change; the version bump refreshes the JS/CSS asset cache.
 * 1.1.4 (video chapter embed inside the messenger): a trusted-origin Vimeo player with a clickable chapter list
 *   renders inside a message WITHOUT weakening either sanitizer. The server strips <iframe>/<script>/<style> at the
 *   write chokepoint (wp_kses_post) and the client re-strips on render (DOMPurify), so a passed-through embed's
 *   seeking script never runs. Instead, ZIM_Messages::extract_video_embeds() runs FIRST in post()/edit(), recognises
 *   ONLY a trusted-origin player.vimeo.com embed, and distils it to a plain-text [zdz-video]<hex>[/zdz-video] token
 *   carrying only the numeric video id and the chapter list (which survives kses untouched); the client's
 *   wireVideoEmbeds() rebuilds the player with the iframe origin FIXED to player.vimeo.com and seeks host-side via
 *   the Vimeo postMessage API (with a #t= reload fallback); chapter clicks never navigate. The trusted-origin
 *   allow-list is a Core constant (Vimeo) exposed via the `zim_video_embed_hosts` filter. edit() runs the same
 *   extract + wp_kses_post() the post() path does.
 * 1.1.3 (DM-email cooldown, testable and resettable): the per-conversation cooldown routes through the
 *   `zim_email_cooldown_seconds` filter (default 30 min; 0 disables it). An admin button (Messaging -> Channels ->
 *   "DM email testing") clears every zim_last_email_convo_* stamp so the next direct message e-mails at once.
 * 1.1.2 (DM-email diagnostics): greppable `ZIM_DM_EMAIL:` debug lines on the direct-message e-mail path (sent,
 *   suppressed by the cooldown, no e-mail on file), WP_DEBUG only.
 * 1.1.1 (poll-load reduction): the widget polls every 10 s (server-provided `pollIntervalMs`, filterable via
 *   `zim_poll_interval_ms`; client floor 8 s), backs off x2 per failed poll up to 120 s and snaps back on success,
 *   so a struggling host is given room to recover. Bottom-nav badge poll 60 s.
 * 1.1.0 (two-way e-mail <-> direct message bridge): a direct-message e-mail carries the message text and a
 *   Reply-To of <mailbox>+dm-<token>@<mail-domain>; replying posts straight back into the conversation as the
 *   recipient. The token is opaque and signed (HMAC-SHA256, constant-time verify); the sender must be the person the
 *   e-mail went to, and the write still goes through ZIM_Messages::post() (read-only roles refused). The knowledge
 *   vault's mailbox reader (ZKV_Mailbox) hands such replies here before turning mail into documents, so the inbox
 *   has one reader; an unroutable reply is dropped, logged and filed under "Messaging Failed".
 * 1.0.26: the cron_schedules label is a plain string (a translation call before `init` raised a notice on every
 *   request, which could corrupt AJAX JSON with WP_DEBUG_DISPLAY on).
 * 1.0.25: the people-search field turns off autocorrect, autocapitalize and spellcheck.
 * 1.0.24 (shared-device hardening): read-only role support for the shared account (`zdz_general`):
 *   zim_user_is_read_only(), zim_user_can_write(), zim_read_only_roles() (filterable). Every write path refuses
 *   read-only users server-side (AJAX gate_write()/gate_admin(); ZIM_Messages::post() and
 *   ZIM_DMs::get_or_create_conversation() at the model layer, which also closes the REST "post to #channel" route);
 *   the composer is not rendered for them. Read access to #announcements comes from the seed's auto-join.
 * 1.0.22: font sizes and the desktop layout (820 px and 1280 px breakpoints).
 *
 * ============================================================================
 * PROGRAMMER NOTES
 * ============================================================================
 * ROLE: Bootstrap. Constants, schema activation, class loading, theme hook.
 *
 * BUSINESS CONTEXT:
 * A business needs internal team messaging that lives *inside* the Field OS
 * rather than in a separate chat product: one-to-one direct messages and
 * channels (Core's #announcements, plus whatever the business creates) with
 * @mentions, push notifications, and inline estimate and invoice cards.
 *
 * DELIBERATELY NOT INCLUDED (see README "Not in this release"):
 *  - Threaded replies / reactions / typing indicators
 *  - External integrations (chat bridges)
 *  - WebSockets: HTTP polling (Trap 1)
 *  - Cross-conversation search
 *
 * REAL-TIME MODEL:
 * HTTP polling. One in-flight poll per open conversation + one for the
 * sidebar badges. Total <= 2 concurrent AJAX requests per tab.
 *
 * SELF-CONTAINED:
 * This plugin uses NO external API clients. Estimate and invoice cards read
 * through the Estimates app's document reader (zdz_fb_document_for_viewer)
 * or, without it, the analytics app's /zorderz/v1/freshbooks-preview/{id}
 * endpoint; with neither, a #NNNNN chip shows its number only. See Trap 2.
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
 *  wp_zim_push_subscriptions  — Web Push endpoints, per user + UA + session
 *  wp_zim_notification_queue  — deferred pushes (quiet hours) + digest log
 *  wp_zim_doc_grants          — one document shared with a conversation's people
 * ============================================================================
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ──────────────────────────────────────────────────────
define( 'ZIM_VERSION', '1.9.0' );
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
	if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $user_id ) ) {
		return true;   // the shared device, by the platform's trait, whichever role carries it (no filter lifts this)
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
			// request. That notice was leaking into the Analytics chat's AJAX JSON responses
			// (DOING_AJAX guard runs too late to catch a load-time notice), corrupting
			// them → "Network error creating/loading session." The label is admin-only
			// (the WP-Cron schedules screen); it does not need early translation.
			'display'  => 'Every minute (Messaging)',
		);
	}
	return $schedules;
} );

// ── Media Library exclusion ─────────────────────────────────────────
// Messaging chat attachments are stored as private WP attachment posts with
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
	if ( class_exists( 'ZIM_Notices' ) ) {
		ZIM_Notices::init();   // zim_queue_notice: another app's notice, held to quiet hours (the theme's alert router)
	}
	if ( class_exists( 'ZIM_DM_Send' ) ) {
		ZIM_DM_Send::init();   // zim_dm_send: Messages' own seam for a direct message pressed in another app (the chat)
	}
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

	// Invalidate Web Push subscriptions on logout (Trap 6): only the device signing out (its session's rows).
	add_action( 'clear_auth_cookie', array( 'ZIM_Push', 'on_logout' ) );
	// messaging.push_setup, the read verb Settings -> Notifications asks (ZDZ_Push_Setup).
	add_filter( 'zdz_register_capabilities', array( 'ZIM_Push', 'register_capabilities' ) );
	// messaging.unread_counts: this person's unread messages as Messages' own badge counts them (counts only).
	add_filter( 'zdz_register_capabilities', array( 'ZIM_Unread_Counts', 'register_capabilities' ) );
	// The business's own channels, from its Identity Pack (org.yml messaging.channels).
	add_filter( 'zdz_identity_pack_consumers', array( 'ZIM_Channels', 'pack_consumer' ) );

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
			// Versioned like the theme's own enqueue: an unversioned, cached style.css could leave the type roles
			// (--zdz-type-*) undefined in this frame.
			echo '<link rel="stylesheet" href="' . esc_url( add_query_arg( 'ver', zim_theme_css_ver(), $theme_css_url ) ) . '">';
		}
		// The app's own Inter (the theme's self-hosted faces) when the theme ships them; the system font otherwise.
		if ( function_exists( 'get_template_directory' ) && is_readable( get_template_directory() . '/assets/css/fonts.css' ) ) {
			echo '<link rel="stylesheet" href="' . esc_url( add_query_arg( 'ver', zim_theme_css_ver(), get_template_directory_uri() . '/assets/css/fonts.css' ) ) . '">';
		}
		wp_print_styles( array( 'zim-widget-css' ) );
		wp_print_scripts( array( 'marked-js', 'dompurify', 'zim-widget-js' ) );
	} else {
		// Standalone full-page: include theme assets so tokens resolve.
		wp_head();
	}
	echo '</head><body class="zim-fullpage' . ( $is_embed ? ' zim-embed' : '' ) . ( zim_is_compact() ? ' zim-compact' : '' ) . '">';
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	if ( ! $is_embed ) {
		wp_footer();
	}
	echo '</body></html>';
	exit;
}

/**
 * The theme stylesheet's cache key: the theme's version (ZDZ_THEME_VERSION / ZDZ_THEME_VER_FLOOR / wp_get_theme) and
 * the file's own time, as the theme's own asset version does.
 */
function zim_theme_css_ver() {
	$v = defined( 'ZDZ_THEME_VERSION' ) ? (string) ZDZ_THEME_VERSION : ( defined( 'ZDZ_THEME_VER_FLOOR' ) ? (string) ZDZ_THEME_VER_FLOOR : (string) wp_get_theme()->get( 'Version' ) );
	$f = function_exists( 'get_stylesheet_directory' ) ? get_stylesheet_directory() . '/style.css' : '';
	$t = ( $f && is_readable( $f ) ) ? (string) filemtime( $f ) : '';
	$k = trim( $v . '.' . $t, '.' );
	return '' !== $k ? $k : ZIM_VERSION;
}

/**
 * The one-thread compact view the chat docks under its answer: /?zim_page=1&zdz_embed=dock&zim_compact=1&c=<id>.
 * Messages draws its own thread (no data crosses apps; membership is checked by the same fetch as always): no
 * sidebar, no header, the last messages and the composer.
 */
function zim_is_compact() {
	return isset( $_GET['zim_compact'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['zim_compact'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display switch for the page; it changes nothing.
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
			'tsim_doc_grants' => 'zim_doc_grants',
		] );
		$map['options'] = array_merge( $map['options'] ?? [], [
			'tsim_db_version' => 'zim_db_version',
			'tsim_needs_reseed' => 'zim_needs_reseed',
			// the push keys (a new key would silently invalidate every device's subscription) and the e-mail reply
			// bridge (its secret keeps reply addresses already sent working)
			'tsim_vapid_private_pem' => 'zim_vapid_private_pem',
			'tsim_vapid_public_b64' => 'zim_vapid_public_b64',
			'tsim_vapid_created_at' => 'zim_vapid_created_at',
			'tsim_vapid_subject' => 'zim_vapid_subject',
			'tsim_email_reply_secret' => 'zim_email_reply_secret',
			'tsim_email_reply_config' => 'zim_email_reply_config',
			'tsim_email_reply_self_seen' => 'zim_email_reply_self_seen',
		] );
		$map['user_meta'] = array_merge( $map['user_meta'] ?? [], [
			'tsim_quiet_hours_' => 'zim_quiet_hours_',     // a person's own quiet hours (the whole key, despite its name)
			'tsim_chat_sources' => 'zim_chat_sources',     // "From your chat" on the person's own messages
		] );
		$map['cron'] = array_merge( $map['cron'] ?? [], [
			'tsim_cron_dispatch_notifications' => 'zim_cron_dispatch_notifications',
			'tsim_cron_purge_attachments' => 'zim_cron_purge_attachments',
			'tsim_cron_rotate_push_keys' => 'zim_cron_rotate_push_keys',
			'tsim_email_reply_self_poll' => 'zim_email_reply_self_poll',
		] );
	return $map;
} );


/*
 * This app's main actions, counted in the theme's Usage view (wp-admin -> User Management -> Usage, "What people do
 * inside the apps"). Each is recorded on the server where the action succeeded, never on a click that failed, through
 * ZDZ_Usage::record(), which applies the usage-records consent (ZDZ_Consent::may_record), keeps no IP and stores the
 * item's number only, never a name or a message.
 */
add_filter( 'zdz_register_usage_actions', function ( $a ) {
	$a['internal-messaging'] = array(
		'message_sent'    => 'Sent a message',
		'message_edited'  => 'Edited a message',
		'document_shared' => 'Shared an estimate or invoice',
		'channel_created' => 'Created a channel',
	);
	return $a;
} );
if ( ! function_exists( 'zim_usage' ) ) {
	/** Record one of this app's actions; $ref a number (the item's id) or 0. Nothing at all without the usage service. */
	function zim_usage( $key, $ref = 0, $opts = array() ) {
		if ( class_exists( 'ZDZ_Usage' ) && method_exists( 'ZDZ_Usage', 'record' ) ) {
			ZDZ_Usage::record( 'internal-messaging', (string) $key, $ref, (array) $opts );
		}
	}
}
