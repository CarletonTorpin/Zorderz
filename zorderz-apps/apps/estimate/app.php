<?php
/**
 * Module: Zorderz - Estimates
 * Description: AI-assisted estimate creation — dictate, photograph or type an estimate;
 *   the app parses it, prices it from the Item Engine catalog, and drafts a billing
 *   estimate + CRM lead. Prompts are assembled at runtime from the Business Profile,
 *   Item Engine catalog/pricing, the Party roster and the rendered rule set — no typed
 *   products, prices, customers or people. House paperwork style (location line,
 *   "Submitted by" line, closing tax/installation line, price rounding, reference-code
 *   grammar) is applied ON OUTPUT through the theme's ZDZ_Doc_Conventions service, never
 *   compiled into the model prompt. Reads identity from ZDZ_Business_Profile,
 *   ZDZ_Item_Engine, ZDZ_Party, ZDZ_Core_FreshBooks, ZDZ_Core_Nutshell, ZDZ_Core_Poe and
 *   ZDZ_Core_Settings.
 * Version:     1.21.2
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
 * CORE-SERVICE BINDINGS (bound via the canonical static API when loaded, else the
 * mirrored filters — an EMPTY catalog / roster degrades to neutral, never a baked guess):
 *   - Item Engine       : product catalog, aliases/brand tokens, min/max sizes and the
 *                         three price books ALL live here now. Bind: `zdz_item_classify`,
 *                         `zdz_item_match`, `zdz_item_get`, `zdz_item_kinds`,
 *                         `zdz_item_count_categories`, `zdz_pricing_resolve`. No product,
 *                         brand, size or price literal in code or prompt.
 *   - Doc Conventions   : ZDZ_Doc_Conventions applied on OUTPUT (location line, provenance
 *                         line, closing line, rounding, reference grammar, casing).
 *   - Party             : selectable people + short codes (key `initials`, case-insensitive)
 *                         via ZDZ_Party — never a local roster constant. Per-user notation
 *                         profile is party meta.
 *   - Connections       : billing (ZDZ_Core_FreshBooks + ZDZ_Token_Service), CRM
 *                         (ZDZ_Core_Nutshell), AI (ZDZ_Core_Poe) — credentials/endpoints
 *                         owned by the theme; this module never stores a secret.
 *   - AI model roles    : call sites request a ROLE (parse/classify/fallback), resolved by
 *                         `zdz_ai_model_role` with a safe default — never a hardcoded model.
 *
 * SAFETY FLOOR: nothing silent — every drop/skip/fallback is logged. Never trust the model
 * for a side effect: chat verbs draft → preview → confirm → re-verify server-side, are
 * ownership-checked, and are hard-refused for the shared kiosk.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ── Identity ──────────────────────────────────────────────────────── */
define( 'ZEST_VERSION', '1.25.2' );
define( 'ZEST_FILE', __FILE__ );
define( 'ZEST_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZEST_URL', plugin_dir_url( __FILE__ ) );
define( 'ZEST_NONCE', 'zest_nonce' );
// App id matches the theme's role grants + plugin-api label map ('estimate-creator').
define( 'ZEST_APP_ID', 'estimate-creator' );

/**
 * Chat/orchestrator protocol markers. Protocol tokens live in ONE place, referenced
 * by the bridge + parser + JS through this map and published via `zdz_chat_markers`
 * — never typed twice. Each carries its deprecated legacy alias so an install upgraded
 * from the private lineage keeps working for one release.
 */
if ( ! defined( 'ZEST_MARKER_CREATE' ) ) {
	define( 'ZEST_MARKER_CREATE', '[ZDZ_EST_CREATE]' );
	define( 'ZEST_MARKER_SEND', '[ZDZ_EST_SEND]' );
	define( 'ZEST_MARKER_STUB', '[ZDZ_EST_STUB]' );
	define( 'ZEST_MARKER_LOOKUP', '[ZDZ_EST_LOOKUP]' );
	define( 'ZEST_MARKER_WIDGET', '[ZDZ_EST_WIDGET]' );
	define( 'ZEST_MARKER_LIST', '[ZDZ_EST_LIST]' );
	define( 'ZEST_MARKER_LEAD', '[ZDZ_EST_LEAD]' );
	define( 'ZEST_MARKER_ATTACH_EMAIL', '[ZDZ_EST_ATTACH_EMAIL]' );
}

/** Content-keyed asset version: module version + file mtime, so a byte change busts caches. */
function zest_asset_ver( $rel ) {
	$abs   = ZEST_DIR . ltrim( (string) $rel, '/' );
	$mtime = @filemtime( $abs );
	return $mtime ? ZEST_VERSION . '.' . $mtime : ZEST_VERSION;
}

/**
 * Resolve an AI model for a job ROLE with a safe default.
 *
 * The central model registry is owned by the analytics module; this module never
 * hardcodes a model name. Call sites ask for a role ('parse' | 'classify' | 'fallback'
 * | 'transcribe'); the `zdz_ai_model_role` filter resolves it, and the theme's Poe
 * client falls back to its own default when nothing answers. The empty-string default
 * here means "let the provider client choose" — no model literal ships in this module.
 *
 * @param string $role One of parse|classify|fallback|transcribe.
 * @return string Model id, or '' to defer to the provider client default.
 */
function zest_ai_model( string $role ): string {
	return (string) apply_filters( 'zdz_ai_model_role', '', $role, ZEST_APP_ID );
}

/**
 * The AI markers this module speaks, published to the chat engine as one map (never a
 * literal), each with its deprecated legacy alias.
 *
 * @return array<string,array{token:string,deprecated:array}>
 */
function zest_markers(): array {
	return array(
		'estimate.create'       => array( 'token' => ZEST_MARKER_CREATE, 'deprecated' => array( '[TSEC_CREATE]' ) ),
		'estimate.send'         => array( 'token' => ZEST_MARKER_SEND, 'deprecated' => array( '[TSEC_SEND]' ) ),
		'estimate.stub'         => array( 'token' => ZEST_MARKER_STUB, 'deprecated' => array( '[TSEC_STUB]' ) ),
		'estimate.lookup'       => array( 'token' => ZEST_MARKER_LOOKUP, 'deprecated' => array( '[TSEC_LOOKUP]' ) ),
		'estimate.widget'       => array( 'token' => ZEST_MARKER_WIDGET, 'deprecated' => array( '[TSEC_WIDGET]' ) ),
		'estimate.list'         => array( 'token' => ZEST_MARKER_LIST, 'deprecated' => array( '[TSEC_LIST]' ) ),
		'estimate.lead'         => array( 'token' => ZEST_MARKER_LEAD, 'deprecated' => array( '[TSEC_LEAD]' ) ),
		'estimate.attach_email' => array( 'token' => ZEST_MARKER_ATTACH_EMAIL, 'deprecated' => array( '[TSEC_ATTACH_EMAIL]' ) ),
	);
}

/* ── Load classes with no theme-interface dependency ─────────────────── */
require_once ZEST_DIR . 'includes/interface-zest-ai-provider.php';
require_once ZEST_DIR . 'includes/class-zest-db.php';
require_once ZEST_DIR . 'includes/class-zest-catalog.php';
require_once ZEST_DIR . 'includes/class-zest-poe-client.php';
require_once ZEST_DIR . 'includes/class-zest-freshbooks.php';
require_once ZEST_DIR . 'includes/class-zest-nutshell.php';
require_once ZEST_DIR . 'includes/class-zest-engine.php';
require_once ZEST_DIR . 'includes/class-zest-progress.php';
require_once ZEST_DIR . 'includes/class-zest-background.php';
require_once ZEST_DIR . 'includes/class-zest-tsa-bridge.php';
require_once ZEST_DIR . 'includes/class-zest-admin.php';
require_once ZEST_DIR . 'includes/class-zest-dashboard.php';
require_once ZEST_DIR . 'includes/class-zest-doc-renderer.php';

/**
 * Activation (called by the zorderz-apps bundle activator via the manifest entry).
 * Creates/upgrades the schema ONLY — no catalog, price, customer or estimate is ever
 * seeded — and grants the tile to eligible users.
 */
function zest_activate() {
	ZEST_DB::install();
	zest_grant_tile_to_all_eligible_users();

	// The zero-pricing capability (an operator creates $0 pre-estimates). Trait-based:
	// granted to any role carrying the operator seat, matched by the theme's role config.
	$operator = get_role( 'zdz_operator' );
	if ( $operator && ! $operator->has_cap( 'zest_create_zero_estimates' ) ) {
		$operator->add_cap( 'zest_create_zero_estimates' );
	}

	if ( ! wp_next_scheduled( 'zest_daily_sync' ) ) {
		wp_schedule_event( time() + 3600, 'twicedaily', 'zest_daily_sync' );
	}
	update_option( 'zest_db_version', ZEST_DB::DB_VERSION );
}

/** Deactivation — clear scheduled events + our transients (data + tables preserved). */
function zest_deactivate() {
	wp_clear_scheduled_hook( 'zest_daily_sync' );
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_zest\_%' OR option_name LIKE '\_transient\_timeout\_zest\_%'" );
}

/** Grant the tile to every user with app access unless explicitly denied. Idempotent. */
function zest_grant_tile_to_all_eligible_users() {
	$users = get_users( array( 'fields' => array( 'ID' ), 'capability' => 'zdz_access_app', 'number' => -1 ) );
	foreach ( $users as $u ) {
		zest_grant_tile_to_user( (int) $u->ID );
	}
}

function zest_grant_tile_to_user( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return;
	}
	if ( ! user_can( $user_id, 'manage_options' ) && ! user_can( $user_id, 'zdz_access_app' ) ) {
		return;
	}
	$denied = get_user_meta( $user_id, 'zdz_denied_apps', true );
	if ( is_array( $denied ) && in_array( ZEST_APP_ID, $denied, true ) ) {
		return;
	}
	$allowed = get_user_meta( $user_id, 'zdz_allowed_apps', true );
	if ( ! is_array( $allowed ) ) {
		$allowed = array();
	}
	if ( ! in_array( ZEST_APP_ID, $allowed, true ) ) {
		$allowed[] = ZEST_APP_ID;
		update_user_meta( $user_id, 'zdz_allowed_apps', $allowed );
	}
}

add_action( 'wp_login', function ( $user_login, $user ) {
	if ( $user instanceof WP_User ) {
		zest_grant_tile_to_user( $user->ID );
	}
}, 10, 2 );

/* ── Boot ────────────────────────────────────────────────────────────── */
add_action( 'plugins_loaded', function () {
	// Self-heal the schema on any file-overwrite / fresh copy that skipped activation.
	ZEST_DB::maybe_upgrade();

	// Build the engine from the theme's shared Core services (never a private client).
	$engine = new ZEST_Estimate_Engine();
	ZEST_Dashboard::boot( $engine );
	ZEST_Background::boot();
	ZEST_TSA_Bridge::init();
	ZEST_Admin::init();

	// Publish this module's chat markers to the orchestrator as one map.
	add_filter( 'zdz_chat_markers', function ( $markers ) {
		if ( ! is_array( $markers ) ) {
			$markers = array();
		}
		return array_merge( $markers, zest_markers() );
	} );

	// Publish estimate chat capabilities to the orchestrator registry (rows a central
	// resolver reads; harmless until it exists).
	add_filter( 'zdz_register_capabilities', function ( $caps ) {
		if ( ! class_exists( 'ZEST_TSA_Bridge' ) ) {
			return $caps;
		}
		foreach ( array(
			'estimate.create' => 'create_from_chat',
			'estimate.send'   => 'send_from_chat',
			'estimate.lookup' => 'lookup_for_chat',
			'estimate.stub'   => 'stub_from_lead',
		) as $verb => $method ) {
			if ( method_exists( 'ZEST_TSA_Bridge', $method ) ) {
				$caps[ $verb ] = array( 'callable' => array( 'ZEST_TSA_Bridge', $method ) );
			}
		}
		return $caps;
	} );

	// Contribute estimate-creation dates to the theme's activity-streak calculator.
	add_filter( 'zdz_user_active_dates', function ( $dates, $user_id ) {
		return ZEST_DB::active_dates( (array) $dates, (int) $user_id );
	}, 10, 2 );
}, 20 );

/* ── Front-end nav ────────────────────────────────────────────────────── */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_user_logged_in() || ! is_front_page() ) {
		return;
	}
	// The widget assets are enqueued by the app class when it renders; nothing global here.
}, 20 );

/* ── Daily billing→CRM reconcile ──────────────────────────────────────── */
add_action( 'zest_daily_sync', function () {
	if ( class_exists( 'ZEST_Dashboard' ) && method_exists( 'ZEST_Dashboard', 'cron_sync_estimates' ) ) {
		ZEST_Dashboard::cron_sync_estimates();
	}
} );

/* ── Phase 1: printable FreshBooks-parity document view + generic import ── */
// A logged-in, access-gated printable page for an estimate: /?zest_doc=<id>.
// No rewrite rules needed. The renderer escapes its own inputs.
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['zest_doc'] ) ) {
		return;
	}
	$id = (int) $_GET['zest_doc'];
	if ( ! is_user_logged_in() ) {
		auth_redirect();
	}
	$ok = current_user_can( 'manage_options' );
	if ( ! $ok && is_callable( array( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) ) {
		$ok = ZDZ_Plugin_API::user_can_access_app( get_current_user_id(), ZEST_APP_ID );
	}
	if ( ! $ok ) {
		wp_die( esc_html__( 'Access denied.', 'zorderz' ), 403 );
	}
	if ( ! class_exists( 'ZEST_Doc_Renderer' ) ) {
		wp_die( 'Document renderer unavailable.' );
	}
	$html = ZEST_Doc_Renderer::render_estimate( $id );
	if ( '' === $html ) {
		status_header( 404 );
		wp_die( 'Document not found.' );
	}
	header( 'Content-Type: text/html; charset=utf-8' );
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput — renderer escapes inputs
	exit;
} );

// Generic document importer (admin). The seam the manual PDF-import pipeline feeds:
// POST a normalized document JSON, get an estimate row that preserves its number,
// date and totals verbatim. POST {ns}/estimate/import  (wp_rest nonce).
add_action( 'rest_api_init', function () {
	$ns = defined( 'ZDZ_REST_NS' ) ? ZDZ_REST_NS : 'zorderz/v1';
	register_rest_route( $ns, '/estimate/import', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'callback'            => function ( WP_REST_Request $req ) {
			if ( ! class_exists( 'ZEST_Doc_Renderer' ) ) {
				return new WP_Error( 'zest_no_renderer', 'Renderer unavailable.', array( 'status' => 500 ) );
			}
			$doc = $req->get_json_params();
			if ( ! is_array( $doc ) || empty( $doc ) ) {
				$maybe = $req->get_param( 'doc' );
				if ( is_string( $maybe ) ) {
					$doc = json_decode( $maybe, true );
				}
			}
			if ( ! is_array( $doc ) || empty( $doc ) ) {
				return new WP_Error( 'zest_bad_doc', 'Missing or invalid document.', array( 'status' => 400 ) );
			}
			$id = ZEST_Doc_Renderer::import_estimate( $doc );
			if ( ! $id ) {
				return new WP_Error( 'zest_insert_failed', 'Insert failed.', array( 'status' => 500 ) );
			}
			return array( 'ok' => true, 'id' => $id, 'view' => home_url( '/?zest_doc=' . $id ) );
		},
	) );

	// Sync alternative to the async parse queue (mode:'import'): parse extracted PDF text
	// into the canonical $doc VERBATIM (no pricing, no catalog). A thin wrapper over the
	// engine's parse_document(); the async queue is the primary path. Admin only. This is
	// NOT a side effect — a human still reviews + confirms before /estimate|invoice/import.
	register_rest_route( $ns, '/estimate/parse', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'callback'            => function ( WP_REST_Request $req ) {
			$text = (string) $req->get_param( 'text' );
			if ( '' === trim( $text ) ) {
				return new WP_Error( 'zest_no_text', 'No text to parse.', array( 'status' => 400 ) );
			}
			$kind   = sanitize_key( (string) $req->get_param( 'kind' ) );
			$engine = new ZEST_Estimate_Engine();
			return $engine->parse_document( $text, array(
				'kind'    => in_array( $kind, array( 'estimate', 'invoice' ), true ) ? $kind : '',
				'user_id' => get_current_user_id(),
			) ); // { ok, doc, warnings, error }
		},
	) );

	// Render a draft $doc to printable HTML for the import review panel. Pure render —
	// no DB write, no import. Admin only.
	register_rest_route( $ns, '/estimate/preview', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'callback'            => function ( WP_REST_Request $req ) {
			if ( ! class_exists( 'ZEST_Doc_Renderer' ) ) {
				return new WP_Error( 'zest_no_renderer', 'Renderer unavailable.', array( 'status' => 500 ) );
			}
			$params = $req->get_json_params();
			$params = is_array( $params ) ? $params : array();
			$doc    = ( isset( $params['doc'] ) && is_array( $params['doc'] ) ) ? $params['doc'] : $params;
			if ( ! is_array( $doc ) || empty( $doc ) ) {
				return new WP_Error( 'zest_bad_doc', 'Missing or invalid document.', array( 'status' => 400 ) );
			}
			return array( 'ok' => true, 'html' => ZEST_Doc_Renderer::preview_html( $doc ) );
		},
	) );
} );

/* ── Phase 2: invoice document view + estimate→invoice convert + payments ── */
// Printable invoice at /?zest_inv=<id> (logged-in + access-gated), same renderer.
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['zest_inv'] ) ) {
		return;
	}
	$id = (int) $_GET['zest_inv'];
	if ( ! is_user_logged_in() ) {
		auth_redirect();
	}
	$ok = current_user_can( 'manage_options' );
	if ( ! $ok && is_callable( array( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) ) {
		$ok = ZDZ_Plugin_API::user_can_access_app( get_current_user_id(), ZEST_APP_ID );
	}
	if ( ! $ok ) {
		wp_die( esc_html__( 'Access denied.', 'zorderz' ), 403 );
	}
	if ( ! class_exists( 'ZEST_Doc_Renderer' ) ) {
		wp_die( 'Document renderer unavailable.' );
	}
	$html = ZEST_Doc_Renderer::render_invoice( $id );
	if ( '' === $html ) {
		status_header( 404 );
		wp_die( 'Invoice not found.' );
	}
	header( 'Content-Type: text/html; charset=utf-8' );
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput — renderer escapes inputs
	exit;
} );

// Invoice endpoints (admin, wp_rest nonce): import an invoice, convert an estimate,
// record a payment. Kept intentionally minimal — document + payment tracking only.
add_action( 'rest_api_init', function () {
	$ns    = defined( 'ZDZ_REST_NS' ) ? ZDZ_REST_NS : 'zorderz/v1';
	$admin = function () { return current_user_can( 'manage_options' ); };

	register_rest_route( $ns, '/invoice/import', array(
		'methods'             => 'POST',
		'permission_callback' => $admin,
		'callback'            => function ( WP_REST_Request $req ) {
			if ( ! class_exists( 'ZEST_Doc_Renderer' ) ) {
				return new WP_Error( 'zest_no_renderer', 'Renderer unavailable.', array( 'status' => 500 ) );
			}
			$doc = $req->get_json_params();
			if ( ! is_array( $doc ) || empty( $doc ) ) {
				return new WP_Error( 'zest_bad_doc', 'Missing or invalid document.', array( 'status' => 400 ) );
			}
			$id = ZEST_Doc_Renderer::import_invoice( $doc );
			return $id ? array( 'ok' => true, 'id' => $id, 'view' => home_url( '/?zest_inv=' . $id ) )
				: new WP_Error( 'zest_insert_failed', 'Insert failed.', array( 'status' => 500 ) );
		},
	) );

	register_rest_route( $ns, '/estimate/(?P<id>\d+)/convert', array(
		'methods'             => 'POST',
		'permission_callback' => $admin,
		'callback'            => function ( WP_REST_Request $req ) {
			if ( ! class_exists( 'ZEST_Doc_Renderer' ) ) {
				return new WP_Error( 'zest_no_renderer', 'Renderer unavailable.', array( 'status' => 500 ) );
			}
			$eid  = (int) $req->get_param( 'id' );
			$opts = (array) ( $req->get_json_params() ?: array() );
			$inv  = ZEST_Doc_Renderer::convert_estimate_to_invoice( $eid, $opts );
			return $inv ? array( 'ok' => true, 'estimate_id' => $eid, 'invoice_id' => $inv, 'view' => home_url( '/?zest_inv=' . $inv ) )
				: new WP_Error( 'zest_convert_failed', 'Conversion failed (estimate not found?).', array( 'status' => 400 ) );
		},
	) );

	register_rest_route( $ns, '/invoice/(?P<id>\d+)/payment', array(
		'methods'             => 'POST',
		'permission_callback' => $admin,
		'callback'            => function ( WP_REST_Request $req ) {
			if ( ! class_exists( 'ZEST_Doc_Renderer' ) ) {
				return new WP_Error( 'zest_no_renderer', 'Renderer unavailable.', array( 'status' => 500 ) );
			}
			$iid = (int) $req->get_param( 'id' );
			$b   = (array) ( $req->get_json_params() ?: array() );
			$res = ZEST_Doc_Renderer::record_payment( $iid, $b['amount'] ?? 0, $b['method'] ?? 'other', $b['note'] ?? '', $b['received_at'] ?? '' );
			return ! empty( $res['ok'] ) ? $res : new WP_Error( 'zest_payment_failed', 'Payment failed (invoice not found?).', array( 'status' => 400 ) );
		},
	) );
} );

/* ── Estimates & Invoices management console: /?zdz_docs=1 ────────────── */
// Staff-usable page: lists estimates + invoices with View/Print, Convert, and
// Record-Payment, wired to the Phase 2 REST endpoints. Self-contained (does not
// touch the app widget JS). View is app-access-gated; the write actions themselves
// re-check manage_options server-side at the REST layer.
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['zdz_docs'] ) ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		auth_redirect();
	}
	$ok = current_user_can( 'manage_options' );
	if ( ! $ok && is_callable( array( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) ) {
		$ok = ZDZ_Plugin_API::user_can_access_app( get_current_user_id(), ZEST_APP_ID );
	}
	if ( ! $ok ) {
		wp_die( esc_html__( 'Access denied.', 'zorderz' ), 403 );
	}
	if ( ! class_exists( 'ZEST_Doc_Renderer' ) ) {
		wp_die( 'Document renderer unavailable.' );
	}
	header( 'Content-Type: text/html; charset=utf-8' );
	echo ZEST_Doc_Renderer::render_console(); // phpcs:ignore WordPress.Security.EscapeOutput — renderer escapes inputs
	exit;
} );

/* ── Dashboard app registration (theme interface — after_setup_theme) ─── */
add_action( 'after_setup_theme', function () {
	if ( ! interface_exists( '\Zorderz\Widget_App_Interface' ) ) {
		return; // older/absent theme — decline, never fatal
	}
	require_once ZEST_DIR . 'includes/class-zest-app.php';
	add_filter( 'zdz_register_apps', function ( $apps ) {
		if ( class_exists( 'ZEST_App' ) ) {
			$apps[ ZEST_APP_ID ] = new ZEST_App();
		}
		return $apps;
	} );
}, 20 );

/**
 * Declare this module's legacy→current rename map to the platform migration. Plugins
 * DECLARE; the theme's ZDZ_Rename_Migration performs the renames in one place. A fresh
 * Zorderz install has no legacy rows, so every entry no-ops — data is never seeded, only
 * renamed if present.
 */
add_filter( 'zdz_rename_map', function ( $map ) {
	$map['tables'] = array_merge( $map['tables'] ?? array(), array(
		'tsec_estimates'  => 'zest_estimates',
		'tsec_parse_jobs' => 'zest_parse_jobs',
	) );
	$map['options'] = array_merge( $map['options'] ?? array(), array(
		'tsec_db_version'      => 'zest_db_version',
		'tsec_blind_pricing'   => 'zest_blind_pricing',
		'tsec_pricing_guide'   => 'zest_legacy_pricing_guide', // superseded by the Item Engine
		'tsec_model_parse'     => 'zest_model_parse',
		'tsec_model_classify'  => 'zest_model_classify',
		'tsec_model_fallback'  => 'zest_model_fallback',
		'tsec_ai_model'        => 'zest_ai_model',
		'tsec_model_registry'  => 'zest_model_registry',
		'tsec_territory_rules' => 'zest_legacy_territory_rules', // superseded by Service Area
	) );
	$map['user_meta'] = array_merge( $map['user_meta'] ?? array(), array(
		'ts_user_initials'      => 'zdz_user_initials',      // Party short code
		'ts_user_parenthetical' => 'zdz_user_parenthetical', // Party document pref
		'tsec_notation_profile' => 'zdz_notation_profile',   // Party document pref (D15)
		'tsec_can_create_estimate' => 'zest_can_create_estimate',
		'tsec_can_create_invoice'  => 'zest_can_create_invoice',
	) );
	$map['cron'] = array_merge( $map['cron'] ?? array(), array(
		'tsec_daily_sync' => 'zest_daily_sync',
	) );
	$map['caps'] = array_merge( $map['caps'] ?? array(), array(
		'tsec_create_zero_estimates' => 'zest_create_zero_estimates',
	) );
	$map['app_ids'] = array_merge( $map['app_ids'] ?? array(), array(
		'estimate-creator' => ZEST_APP_ID, // id unchanged; recorded for completeness
	) );
	return $map;
} );
