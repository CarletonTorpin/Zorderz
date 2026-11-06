<?php
/**
 * Module: Zorderz - Surveys
 * Description: Satisfaction-survey follow-up for the Zorderz dashboard. Pulls settled
 *   invoices from the configured billing provider, screens them, opens a follow-up per
 *   customer as a CRM lead, tracks a configurable SURVEY OPERATOR's call outcomes, sends
 *   a review invite routed to the tenant's review destination, and closes the loop — with
 *   a non-overridable SAFETY FLOOR: a survey may never auto-close as "Won" without human
 *   review. Consumes the theme's Business Profile (senders + review dests), Party roster
 *   (operator + salesperson resolution), and the Core billing / CRM / AI / review-bridge
 *   services. No business data is shipped; every drop is a logged disposition.
 * Version:     2.13.0
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
 * ── CORE-SERVICE BINDINGS ─────────────────────────────────────────────
 * Identity is READ from the theme, never hardcoded:
 *   - Business Profile : sender identity (`ZDZ_Business_Profile::sender('surveys')`) and
 *                        review destinations (`web.review_google` / `web.review_page`).
 *   - Party            : the survey OPERATOR user + the salesperson roster
 *                        (`ZDZ_Party::selectable_people()`) — never a local roster const.
 *   - Connections      : billing (`ZDZ_Core_Freshbooks`), CRM (`ZDZ_Core_Nutshell`),
 *                        AI (`ZDZ_Core_Poe`), review bridge (`ZDZ_Core_ReviewBridge`).
 *   - Item Engine      : work-category vocabulary via the `zdz_survey_work_categories`
 *                        filter with a NEUTRAL fallback (no product taxonomy invented).
 *   - Flow             : states stay in-app for now; every drop/skip/close is fired on
 *                        `do_action('zdz_flow_disposition', ...)` for the Flow ledger.
 *
 * ── SAFETY FLOOR (non-overridable) ────────────────────────────────────
 * The auto-closer may NEVER close a survey as "Won" without human review. Statuses that
 * still need a human (needs_attention / excluded / follow-up / not-contacted / NULL) are
 * ESCALATED with a logged disposition, never system-Won — even if a tenant enables the
 * optional `zsv_allow_system_close_won` policy (which only ever reaches genuinely
 * satisfied outcomes). This closes the "excluded customer auto-Won at 96h" defect.
 *
 * ── 2.13.0 ────────────────────────────────────────────────────────────
 * - Review e-mails go one customer per request (the widget shows "Sending 2 of 6"), so a slow
 *   CRM on one customer never stops the rest; one log line per press (numbers and reason codes).
 * - CRM work that could not be done is OWED, never lost (ZSV_Owed): a note not posted now is
 *   posted by the next sweep (each carries a Ref line and is never posted twice); a Won mark
 *   that fails after an e-mail is made by the daily sweep while the CRM still reads the lead open.
 * - The auto-close sweep and the review check run under a time budget (ZSV_Budget over the
 *   kernel's request guard and circuit breaker) and say what they left for the next run.
 * - `survey.counts` on the capability registry (ZSV_Counts): a month's counts in the site's time
 *   zone plus the call list as it stands now. Staff and admins; never the shared device.
 * - Review dates from the bridge, the 6-hour recheck window and the month's e-mail count are in
 *   the site's time. A full voice mailbox is "not reached"; an unclear note no longer outranks a
 *   newer voicemail. Two staff actions are counted in the Usage view.
 *
 * @package Zorderz\Surveys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ──────────────────────────────────────────────────────
define( 'ZSV_VERSION', '2.13.0' );
define( 'ZSV_FILE', __FILE__ );
define( 'ZSV_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZSV_URL', plugin_dir_url( __FILE__ ) );
define( 'ZSV_NONCE', 'zsv_nonce' );
define( 'ZSV_APP_ID', 'surveys' );

/**
 * Chat/orchestrator marker tokens. Protocol strings live in ONE constant each,
 * referenced by the bridge and exposed via `zdz_survey_markers` — never typed twice.
 */
if ( ! defined( 'ZSV_MARKER_LOOKUP' ) ) {
	define( 'ZSV_MARKER_LOOKUP', '[ZDZ_SURVEY_LOOKUP]' );
}
if ( ! defined( 'ZSV_MARKER_EXCLUDE' ) ) {
	define( 'ZSV_MARKER_EXCLUDE', '[ZDZ_SURVEY_EXCLUDE]' );
}
if ( ! defined( 'ZSV_MARKER_EXCLUDE_DRAFT' ) ) {
	define( 'ZSV_MARKER_EXCLUDE_DRAFT', '[ZDZ_SURVEY_EXCLUDE_DRAFT]' );
}

/**
 * Content-keyed asset version: module version + file mtime, so a byte change to a
 * CSS/JS asset busts caches even within one version.
 */
function zsv_asset_ver( $rel ) {
	$abs   = ZSV_DIR . ltrim( (string) $rel, '/' );
	$mtime = @filemtime( $abs );
	return $mtime ? ZSV_VERSION . '.' . $mtime : ZSV_VERSION;
}

// ── Load the plumbing (no theme-interface dependency) ──────────────
// class-zsv-app.php implements the theme interface and is required later, inside
// after_setup_theme, once \Zorderz\Widget_App_Interface exists.
require_once ZSV_DIR . 'includes/class-zsv-db.php';
require_once ZSV_DIR . 'includes/class-zsv-budget.php';
require_once ZSV_DIR . 'includes/class-zsv-owed.php';
require_once ZSV_DIR . 'includes/class-zsv-counts.php';
require_once ZSV_DIR . 'includes/class-zsv-settings.php';
require_once ZSV_DIR . 'includes/class-zsv-survey-manager.php';
require_once ZSV_DIR . 'includes/class-zsv-review-checker.php';
require_once ZSV_DIR . 'includes/class-zsv-auto-closer.php';
require_once ZSV_DIR . 'includes/class-zsv-admin.php';
require_once ZSV_DIR . 'includes/class-zsv-dashboard.php';
require_once ZSV_DIR . 'includes/class-zsv-chat-bridge.php';

/**
 * Activation (called by the zorderz-apps bundle activator via the manifest entry).
 * Creates/upgrades the tables (schema migration only — NO business data seeded) and
 * schedules the review + auto-close crons.
 */
function zsv_activate() {
	ZSV_DB::install();
	ZSV_Review_Checker::schedule();
	ZSV_Auto_Closer::schedule();
	update_option( 'zsv_db_version', ZSV_DB::DB_VERSION, false );
}

/** Deactivation — clear scheduled events. Data + tables are preserved. */
function zsv_deactivate() {
	ZSV_Review_Checker::unschedule();
	ZSV_Auto_Closer::unschedule();
}

// ── Boot ───────────────────────────────────────────────────────────
add_action(
	'plugins_loaded',
	function () {
		// Self-heal the schema (and run the operator-column migration) on any
		// file-overwrite / fresh copy that skipped activation.
		ZSV_DB::maybe_upgrade();

		ZSV_Dashboard::init();
		ZSV_Review_Checker::init();
		ZSV_Auto_Closer::init();
		ZSV_Chat_Bridge::init();
		ZSV_Counts::init();

		if ( is_admin() ) {
			ZSV_Admin::init();
		}

		// Publish this module's chat verbs to the orchestrator registry so they are
		// discoverable like the other bridges. Until the central resolver exists this
		// just adds rows nobody reads.
		add_filter(
			'zdz_register_capabilities',
			function ( $caps ) {
				if ( class_exists( 'ZSV_Chat_Bridge' ) && method_exists( 'ZSV_Chat_Bridge', 'get_capability_descriptor' ) ) {
					foreach ( ZSV_Chat_Bridge::get_capability_descriptor() as $verb => $descriptor ) {
						$caps[ $verb ] = $descriptor;
					}
				}
				return $caps;
			}
		);

		// Expose this module's marker tokens via one map (never a literal).
		add_filter(
			'zdz_survey_markers',
			function ( $markers ) {
				if ( ! is_array( $markers ) ) {
					$markers = array();
				}
				$markers['survey.lookup']  = array( 'token' => ZSV_MARKER_LOOKUP, 'deprecated' => array() );
				$markers['survey.exclude'] = array(
					'token'      => ZSV_MARKER_EXCLUDE,
					'draft'      => ZSV_MARKER_EXCLUDE_DRAFT,
					'deprecated' => array(),
				);
				return $markers;
			}
		);
	},
	20
);

// ── Usage records (the theme's Usage view: what people do inside the apps) ──
// Recorded on the server once an action has succeeded, never for a click that failed, through
// ZDZ_Usage::record(), which applies the usage-records consent, keeps no network address and
// stores the item's number only, never a name. Customers' answers are never counted.
add_filter(
	'zdz_register_usage_actions',
	function ( $a ) {
		if ( ! is_array( $a ) ) {
			$a = array();
		}
		$a[ ZSV_APP_ID ] = array(
			'survey_batch_created' => __( 'Created a survey batch', 'zorderz' ),
			'survey_emails_sent'   => __( 'Sent survey emails', 'zorderz' ),
		);
		return $a;
	}
);

if ( ! function_exists( 'zsv_usage' ) ) {
	/** Record one of this app's actions in the Usage view (no-op without the theme's usage records). */
	function zsv_usage( string $key, $ref = 0, array $opts = array() ): void {
		if ( class_exists( 'ZDZ_Usage' ) && method_exists( 'ZDZ_Usage', 'record' ) ) {
			ZDZ_Usage::record( ZSV_APP_ID, $key, $ref, $opts );
		}
	}
}

// Pending CRM work (owed notes and Won marks) belongs to this install's CRM connection: it never
// travels in a Company Data export or import.
add_filter(
	'zdz_export_non_portable_option_patterns',
	function ( $p ) {
		$p   = is_array( $p ) ? $p : array();
		$p[] = '/^zsv_owed_/';
		$p[] = '/^zsv_won_owed$/';
		return $p;
	}
);

// ── Ensure HTML content-type only for our own outbound survey mail ──
add_filter(
	'wp_mail_content_type',
	function ( $content_type ) {
		return ! empty( $GLOBALS['zsv_sending_html'] ) ? 'text/html' : $content_type;
	}
);

// ── Dashboard app registration (theme interface — after_setup_theme) ──
add_action(
	'after_setup_theme',
	function () {
		if ( ! interface_exists( '\Zorderz\Widget_App_Interface' ) ) {
			return; // degrade gracefully on a missing/older theme — never fatal.
		}
		require_once ZSV_DIR . 'includes/class-zsv-app.php';
		add_filter(
			'zdz_register_apps',
			function ( $apps ) {
				if ( class_exists( 'ZSV_App' ) ) {
					$apps[ ZSV_APP_ID ] = new ZSV_App();
				}
				return $apps;
			}
		);
	},
	20
);

/**
 * Declare this module's legacy->current rename map to the platform migration. Plugins
 * DECLARE; the theme's ZDZ_Rename_Migration performs the renames in one place. A fresh
 * Zorderz install has no legacy rows, so every entry no-ops. Data is never seeded — only
 * renamed if present. (The legacy operator-COLUMN rename lives in ZSV_DB, a real ALTER
 * guarded by zsv_db_version, because the platform map only renames whole tables/options,
 * not columns; person-named legacy columns come from a private pack via the
 * zsv_legacy_operator_column_renames filter, so Core names no person.)
 */
add_filter(
	'zdz_rename_map',
	function ( $map ) {
		$map['tables'] = array_merge(
			$map['tables'] ?? array(),
			array(
				'ts_survey_batches'  => 'zdz_survey_batches',
				'ts_survey_leads'    => 'zdz_survey_leads',
				'ts_invoice_memory'  => 'zdz_survey_invoice_memory',
				'ts_survey_forwards' => 'zdz_survey_forwards',
			)
		);
		$map['options'] = array_merge(
			$map['options'] ?? array(),
			array(
				'ts_surveys_db_version'          => 'zsv_db_version',
				'ts_surveys_survey_count'        => 'zsv_batch_size',
				'ts_surveys_ai_model'            => 'zsv_ai_model',
				'ts_surveys_dns_tag_name'        => 'zsv_do_not_survey_tag',
				'ts_surveys_ns_assignee_user_id' => 'zsv_operator_user_id',
				'ts_surveys_ns_assignee_name'    => 'zsv_operator_name',
				'ts_surveys_sync_window_days'    => 'zsv_sync_window_days',
			)
		);
		$map['cron'] = array_merge(
			$map['cron'] ?? array(),
			array(
				'ts_surveys_review_check' => ZSV_Review_Checker::CRON_HOOK,
				'ts_surveys_auto_close'  => ZSV_Auto_Closer::CRON_HOOK,
			)
		);
		$map['app_ids'] = array_merge(
			$map['app_ids'] ?? array(),
			array( 'satisfaction-surveys' => ZSV_APP_ID )
		);
		return $map;
	}
);
