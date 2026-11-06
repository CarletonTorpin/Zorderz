<?php
/**
 * Module: Zorderz - Analytics (Chat)
 * Description: The conversational assistant — the "Chat" surface of the Zorderz dashboard. Ask a question in plain
 *   language; the assistant assembles its instructions at runtime from the Business Profile, the Item Engine catalog,
 *   the Party roster, the price book and the rendered rule set, asks the model through the platform's one Ai router
 *   (ZDZ_Core_LLM, customer details coded before anything leaves), and passes every reply through the single outbound
 *   gate (ZDZ_Answer_Authority) so it states facts only. What it may ask an app to do goes through the verb registry
 *   (ZDZ_Capabilities): a lookup is answered by the app, a change waits for a person's Confirm press.
 * Version:     1.3.0
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
 * SHIPS EMPTY. Activation creates its tables ONLY — no business facts, no roster, no price list, no persona. The
 * instructions are generated from whatever the business has configured in the Core services; on a fresh install that is
 * a neutral, honest assistant ("the assistant") with nothing to leak.
 *
 * WHAT IS HERE: the runtime instruction builder (with the router's cache marks), the router road with each person's
 * daily allowance, plain-text math, the names check, chat actions through the verb registry with the Confirm card, the
 * referral panel over Territories' cases, the Privacy View road (self-masking replies and the pseudonym route), the
 * session store, the synchronous turn and the async turn queue; the stored-data ledger (a read-only copy of the billing
 * system's and the CRM's records, kept by the kernel's billing and CRM connectors) and what reads it (home figures,
 * the ask bar's quick answers, sales and lead-source answers, the invoice view, measured sizes from the measurement
 * notes); the chat's rule routes and channels (mail, "About you", answer times, the Chat answers notification, Charts,
 * Hours, Route Planner, pages, library links, the message card, company facts). Not here (each a separate surface,
 * wired as a documented extension point rather than shipped half-built): token streaming, the scheduled digests, the
 * web-analytics and ad-spend feeds, deep research (it needs a page fetcher the platform does not have), voice and
 * picture input. The answer checks are the kernel's one outbound gate (ZDZ_Answer_Authority).
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ──────────────────────────────────────────────────────
define( 'ZANA_VERSION', '1.3.0' );
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
require_once ZANA_DIR . 'includes/class-zana-allowance.php';
require_once ZANA_DIR . 'includes/class-zana-llm-client.php';
require_once ZANA_DIR . 'includes/class-zana-plain-math.php';
require_once ZANA_DIR . 'includes/class-zana-actions.php';
require_once ZANA_DIR . 'includes/class-zana-pseudonym-route.php';
require_once ZANA_DIR . 'includes/class-zana-prompt-builder.php';
require_once ZANA_DIR . 'includes/class-zana-chat.php';
require_once ZANA_DIR . 'includes/class-zana-background.php';
require_once ZANA_DIR . 'includes/class-zana-rest.php';
require_once ZANA_DIR . 'includes/class-zana-ajax.php';
require_once ZANA_DIR . 'includes/class-zana-referrals.php';
require_once ZANA_DIR . 'includes/class-zana-identity.php';

// How the assistant sounds and each person's daily Ai allowance, from an Identity Pack (preview, apply, revert).
add_filter( 'zdz_identity_pack_consumers', array( 'ZANA_Identity', 'register' ) );

// ── The stored-data ledger (BEGIN) ──────────────────────────────────
// A copy of the billing system's invoices, estimates and payments and the CRM's leads and lead notes, kept in step by
// an hourly read-only sync (never on a staging copy), and what reads it: the home figures (verb ledger.home_figures),
// the ask bar's quick answers (verb ask.quick), sales / estimates / money owed / customers answers, lead sources,
// accepted estimates never invoiced, the chat's DATA LEDGER block, the invoice view and the shadow check. The contract other parts of the app use is
// listed on ZANA_Ledger, ZANA_Ledger_Answers, ZANA_Home_Figures, ZANA_Quick_Answers and ZANA_Invoice_Doc.
foreach ( array( 'ledger-rules', 'ledger-enrich', 'ledger-store', 'ledger', 'ledger-access', 'ledger-periods', 'ledger-figures',
	'lead-sources', 'ledger-answers', 'ledger-reports', 'billing-gaps', 'home-figures', 'quick-answers', 'ledger-shadow',
	'invoice-state', 'invoice-doc', 'ledger-admin', 'measurements' ) as $zana_ledger_part ) {
	require_once ZANA_DIR . 'includes/class-zana-' . $zana_ledger_part . '.php';
}
unset( $zana_ledger_part );
add_action(
	'plugins_loaded',
	function () {
		ZANA_Ledger::boot();          // tables, the hourly sync, the jobs app's invoice-number answer
		ZANA_Home_Figures::init();    // ledger.home_figures
		ZANA_Quick_Answers::init();   // ask.quick
		ZANA_Ledger_Reports::init();  // the chat's DATA LEDGER block (zdz_analytics_data_context)
		ZANA_Ledger_Admin::init();    // the Settings panel, "Sync now", the ledger's REST routes
		ZANA_Measurements::init();    // measured sizes from the measurement notes, on the ask bar (the chat asks it in ZANA_Routes)
	},
	20
);
add_filter(
	'zdz_rename_map',
	function ( $map ) {
		$map['tables']  = array_merge( $map['tables'] ?? array(), array(
			'tsa_ledger_invoices'   => 'zana_ledger_invoices',
			'tsa_ledger_estimates'  => 'zana_ledger_estimates',
			'tsa_ledger_leads'      => 'zana_ledger_leads',
			'tsa_ledger_lead_notes' => 'zana_ledger_lead_notes',
			'tsa_ledger_note_reads' => 'zana_ledger_note_reads',
		) );
		$map['options'] = array_merge( $map['options'] ?? array(), array(
			'tsa_ledger_state' => 'zana_ledger_state', // the sync's cursors: a carried-over history is not read again
		) );
		$map['cron']    = array_merge( $map['cron'] ?? array(), array(
			'tsa_ledger_sync' => 'zana_ledger_sync',
		) );
		return $map;
	}
);
// ── The stored-data ledger (END) ────────────────────────────────────
require_once ZANA_DIR . 'includes/class-zana-surface.php';

/**
 * Activation (called by the zorderz-apps bundle activator via the manifest).
 * Schema only — NEVER seeds business data.
 */
function zana_activate() {
	ZANA_DB::install();
	ZANA_Referrals::maybe_install();
	zana_grant_tile_to_all_eligible_users();
	update_option( 'zana_db_version', ZANA_DB::DB_VERSION, false );
}

/** Deactivation — nothing scheduled to clear; data is preserved. */
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
		ZANA_Ajax::init();          // the admin-ajax road (a Privacy View account's only road), self-masking replies
		ZANA_Allowance::boot();     // each person's daily Ai allowance (Zorderz → Settings)
		ZANA_Referrals::init();     // "Needs a referral" over Territories' cases; its verbs on the registry
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
		// Depend on the theme app css/js so the nav markup + sub-view machinery exist, and on the kit (its buttons,
		// sheets, toasts, requests and the document paper) and the phone-notification switch when the theme has them.
		wp_enqueue_style(
			'zana-chat',
			ZANA_URL . 'assets/css/chat.css',
			wp_style_is( 'zdz-kit-css', 'registered' ) ? array( 'zdz-app-css', 'zdz-kit-css' ) : array( 'zdz-app-css' ),
			zana_asset_ver( 'assets/css/chat.css' )
		);
		// The chat's pure helpers (markdown subset, the server's cards, the small chart): no DOM, tested on their own.
		wp_enqueue_script(
			'zana-chat-kit',
			ZANA_URL . 'assets/js/chat-kit.js',
			array(),
			zana_asset_ver( 'assets/js/chat-kit.js' ),
			true
		);
		$chat_deps = array( 'zdz-app-js', 'zana-chat-kit' );
		foreach ( array( 'zdz-bridge-js', 'zdz-kit-js', 'zdz-push-js' ) as $theme_js ) {
			if ( wp_script_is( $theme_js, 'registered' ) ) {
				$chat_deps[] = $theme_js;
			}
		}
		wp_enqueue_script(
			'zana-chat',
			ZANA_URL . 'assets/js/chat.js',
			$chat_deps,
			zana_asset_ver( 'assets/js/chat.js' ),
			true
		);
		$uid      = get_current_user_id();
		$is_kiosk = ZANA_LLM_Client::is_shared_device( (int) $uid );
		$pv       = class_exists( 'ZANA_Pseudonym_Route' ) && ZANA_Pseudonym_Route::is_on( (int) $uid );
		$surface  = ZANA_Surface::config( (int) $uid, (bool) $is_kiosk, (bool) $pv );
		// The referral panel: for someone who may work the cases (never the shared device, Demo Mode or Privacy View).
		$referrals = class_exists( 'ZANA_Referrals' ) && '' === ZANA_Referrals::refusal( (int) $uid );
		if ( $referrals ) {
			wp_enqueue_script(
				'zana-referrals',
				ZANA_URL . 'assets/js/referrals.js',
				wp_script_is( 'zdz-kit-js', 'registered' ) ? array( 'zana-chat', 'zdz-kit-js' ) : array( 'zana-chat' ),
				zana_asset_ver( 'assets/js/referrals.js' ),
				true
			);
		}
		wp_localize_script(
			'zana-chat',
			'zanaChat',
			array(
				'apiUrl'      => esc_url_raw( rest_url( ZDZ_REST_NS . '/analytics' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'appId'       => ZANA_APP_ID,
				'isKiosk'     => (bool) $is_kiosk,
				// A Privacy View account uses the admin-ajax road (its replies mask themselves) and gets no button that writes.
				'privacyView' => (bool) $pv,
				'ajaxUrl'     => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'ajaxNonce'   => wp_create_nonce( ZANA_NONCE ),
				'referrals'   => (bool) $referrals,
				'allowancePct' => class_exists( 'ZANA_Allowance' ) ? ZANA_Allowance::used_percent( (int) $uid ) : 0,
				// Async turn queue: submit → poll, so a slow turn cannot 502. The client
				// falls back to the sync /chat route if this is false or if enqueue fails.
				'async'       => ! $pv && (bool) apply_filters( 'zana_async_enabled', true ),
				'pollMs'      => 1500,
				// Headroom over the turn's time budget so a slow-but-completing turn still resolves via a poll.
				'maxPollMs'   => 210000,
				// The surface's own settings: which of its features the server answers, the starter questions, the hold's
				// life for a Confirm card, and where the docked message thread lives (ZANA_Surface::config()).
				'features'      => $surface['features'],
				'starters'      => $surface['starters'],
				'promptWeights' => $surface['weights'],
				'confirmTtl'    => $surface['confirm_ttl'],
				'homeUrl'       => esc_url_raw( home_url( '/' ) ),
				'i18n'        => $surface['i18n'] + array(
					'title'        => __( 'Chat', 'zorderz' ),
					/* translators: %s: the assistant's name ("the assistant" unless the business named it) */
					'placeholder'  => sprintf( __( 'Ask %s…', 'zorderz' ), class_exists( 'ZANA_Prompt_Builder' ) ? ZANA_Prompt_Builder::assistant_name() : __( 'the assistant', 'zorderz' ) ),
					'send'         => __( 'Send', 'zorderz' ),
					'thinking'     => __( 'Thinking…', 'zorderz' ),
					'kioskNote'    => __( 'The assistant is not available on the shared device.', 'zorderz' ),
					'pvNote'       => __( 'Privacy View: names and numbers are shown as codes, and nothing can be changed from here.', 'zorderz' ),
					'empty'        => __( 'Ask a question to get started.', 'zorderz' ),
					'timeout'      => __( 'This is taking longer than usual — your answer will appear in this conversation when it\'s ready.', 'zorderz' ),
					'error'        => __( 'Something went wrong. Please try again.', 'zorderz' ),
					'confirm'      => __( 'Confirm', 'zorderz' ),
					'cancel'       => __( 'Cancel', 'zorderz' ),
					'working'      => __( 'Working…', 'zorderz' ),
					'expired'      => __( 'That preview has expired. Ask again and I\'ll show it again.', 'zorderz' ),
					'allowance'    => __( 'You have used most of today\'s Ai allowance for the chat.', 'zorderz' ),
					'refTitle'     => __( 'Needs a referral', 'zorderz' ),
					'refEmpty'     => __( 'Nothing needs a referral right now.', 'zorderz' ),
					'refFind'      => __( 'Find a provider', 'zorderz' ),
					'refTryAgain'  => __( 'Try again', 'zorderz' ),
					'refSearching' => __( 'Searching…', 'zorderz' ),
					'refVet'       => __( 'Mark Vetted', 'zorderz' ),
					'refEmail'     => __( 'Press to email this customer', 'zorderz' ),
					'refClose'     => __( 'Close without emailing', 'zorderz' ),
					'refCloseAsk'  => __( 'The case closes without an email to the customer. Territories keeps a record that it was closed.', 'zorderz' ),
					'refKeep'      => __( 'Keep it open', 'zorderz' ),
					'refUnread'    => __( 'The answer could not be read; try again.', 'zorderz' ),
					'refNone'      => __( 'The search found no company with a website or phone. You can search again.', 'zorderz' ),
					'refHint'      => __( 'One web search, with only the ZIP, the place and the kind of work.', 'zorderz' ),
					'refCheck'     => __( 'Found by a web search: call or look before you trust it, then mark the one you checked Vetted.', 'zorderz' ),
					'refNote'      => __( 'Note (optional): who you spoke to, what they do', 'zorderz' ),
					'refVetted'    => __( 'Vetted', 'zorderz' ),
					'refNotSaved'  => __( 'The estimate app has not saved this stub yet, so there is no customer to write to.', 'zorderz' ),
					'refStillRun'  => __( 'The search may still be running. Close this panel and open it again in a minute to see what it found.', 'zorderz' ),
					'refWhere'     => __( 'where it was found', 'zorderz' ),
					'refShadow'    => __( 'Shadow', 'zorderz' ),
					'refTest'      => __( 'Test', 'zorderz' ),
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
				'tsa_referrals'     => 'zana_referrals',     // the referral panel's record of each case
			)
		);
		$map['options'] = array_merge(
			$map['options'] ?? array(),
			array(
				'tsa_version'          => 'zana_version',
				'tsa_db_version'       => 'zana_db_version',
				'tsa_ai_model'         => 'zdz_model_slot_chat', // model routing now lives in ZDZ_Model_Registry
				'tsa_rule_manifest_mode' => 'zana_rule_manifest_mode',
				'tsa_referrals_db'     => 'zana_referrals_db',
				'tsa_ai_allowance_work' => 'zana_ai_allowance',    // each person's daily Ai allowance for the chat
			)
		);
		$map['user_meta'] = array_merge(
			$map['user_meta'] ?? array(),
			array(
				'tsa_ai_day'         => 'zana_ai_day',
				'tsa_ai_day_milli'   => 'zana_ai_day_milli',
				'tsa_pv_shown_tags'  => 'zana_pv_shown_tags',   // the Privacy View tags each chat was shown
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

/* ── The chat's routes and channels (BEGIN) ─────────────────────────────────────────────────────────────────────────
 * Mailbox questions answered by the inbox app, "About you", answer times, the "Chat answers" notification channel,
 * Charts as the calculator, the Hours / Route Planner / page / Team Roles routes, library links, access codes cut,
 * direct-message cards, email drafts opened in the Email app, the business's facts and the chat's settings page.
 * Their tables are made (and remade after an upload) by zana_more_install(); nothing is ever seeded.
 */
foreach ( array(
	'mail-compose', 'mail-relevance', 'mail-recall', 'mail-followup', 'mail-crossuser', 'mail-continuation', 'mail-guard',
	'email-turn', 'email-cooldown', 'mail-route', 'compose', 'user-memory', 'turn-timings', 'push', 'charts-tool',
	'hours-route', 'plan-routes', 'page-nav', 'vault-links', 'label-clean', 'sealed', 'app-reads', 'dm-card',
	'company-facts', 'starters', 'web-note', 'routes', 'rest-more', 'settings',
) as $zana_more ) {
	require_once ZANA_DIR . 'includes/class-zana-' . $zana_more . '.php';
}
unset( $zana_more );

define( 'ZANA_MORE_DB_VERSION', '1' );

/** The tables of the chat's routes and channels. Idempotent (dbDelta); no data is seeded. */
function zana_more_install() {
	ZANA_User_Memory::install();
	ZANA_Turn_Timings::install();
	ZANA_Push::install();
	ZANA_Email_Cooldown::install();
	ZANA_Company_Facts::install();
	update_option( 'zana_more_db_version', ZANA_MORE_DB_VERSION, false );
}

add_action(
	'plugins_loaded',
	function () {
		if ( get_option( 'zana_more_db_version' ) !== ZANA_MORE_DB_VERSION ) {
			zana_more_install();   // activation, or a bundle replaced by upload (never re-activated)
		}
		ZANA_REST_More::init();
		ZANA_Push::boot();
		ZANA_Sealed::boot();
		ZANA_DM_Card::boot();
		ZANA_Compose::boot();
		ZANA_Settings::boot();
		add_action( ZANA_User_Memory::HOOK_WEEKLY, array( 'ZANA_User_Memory', 'weekly_start' ) );
		add_action( ZANA_User_Memory::HOOK_STEP, array( 'ZANA_User_Memory', 'step' ) );
		add_action( ZANA_Turn_Timings::CRON, array( 'ZANA_Turn_Timings', 'prune' ) );
		if ( is_admin() || wp_doing_cron() ) {
			ZANA_User_Memory::schedule();
			ZANA_Turn_Timings::schedule();
		}
	},
	22
);

/** An install that ran this app under its earlier names is carried across by the platform's rename migration. */
add_filter(
	'zdz_rename_map',
	function ( $map ) {
		$map['tables']    = array_merge( $map['tables'] ?? array(), array(
			'tsa_push_subscriptions' => 'zana_push_subscriptions',
			'tsa_email_cooldown'     => 'zana_email_cooldown',
			'tsa_turn_timings'       => 'zana_turn_timings',
		) );
		$map['options']   = array_merge( $map['options'] ?? array(), array(
			'tsa_vapid_public_key'      => 'zana_vapid_public_key',   // the chat channel's key: existing devices keep working
			'tsa_vapid_private_pem'     => 'zana_vapid_private_pem',
			'tsa_umem_enabled'          => 'zana_umem_enabled',
			'tsa_umem_queue'            => 'zana_umem_queue',
			'tsa_facts_version'         => 'zana_facts_version',
			'tsa_role_prompts_owner'    => 'zana_role_prompts_owner',
			'tsa_role_prompts_admin'    => 'zana_role_prompts_admin',
			'tsa_role_prompts_sales'    => 'zana_role_prompts_sales',
			'tsa_role_prompts_operator' => 'zana_role_prompts_operator',
			'tsa_role_prompts_foreman'  => 'zana_role_prompts_shop',
			'tsa_role_prompts_tech'     => 'zana_role_prompts_field',
		) );
		$map['user_meta'] = array_merge( $map['user_meta'] ?? array(), array(
			'tsa_umem_last_week' => 'zana_umem_last_week',
			'tsa_push_tests'     => 'zana_push_tests',
		) );
		$map['cron']      = array_merge( $map['cron'] ?? array(), array(
			'tsa_umem_weekly' => 'zana_umem_weekly',
			'tsa_umem_step'   => 'zana_umem_step',
		) );
		return $map;
	}
);
/* ── The chat's routes and channels (END) ─────────────────────────────────────────────────────────────────────── */
