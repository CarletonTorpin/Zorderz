<?php
/**
 * Plugin Name: Zorderz - TS - Invoice Creator
 * Description: Optional (beta) invoicing app: create a pay-by-card invoice, take payment through a Stripe account, and optionally append the pay link to a FreshBooks invoice. An optional, clearly-disclosed platform fee can be retained via Stripe Connect. Ships with no billing data and no credentials.
 * Version: 0.2.0-beta
 * Author: Zorderz
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 7.4
 *
 * ── What this is ──────────────────────────────────────────────────────
 * A self-contained invoicing module for the Zorderz apps bundle. An admin
 * creates an invoice; the customer opens a hosted pay page (/pay/<token>) and
 * pays by card via a Stripe account; a webhook marks the invoice paid and
 * emails a receipt. When a FreshBooks connection is configured, the pay link is
 * appended to the matching FreshBooks invoice. Refunds and a CSV export round it
 * out. It registers a dashboard tile through the theme's `zdz_register_apps`
 * filter and defers all work past plugin load.
 *
 * ── Core-clean port (from the internal, dormant invoice creator) ───────
 * Generalized for the public release:
 *   - The baked 0.5% platform-fee CONSTANT is gone. The fee is now a disclosed
 *     admin option (Settings → "Platform fee (%)"), DEFAULT 0 / off, applied as
 *     a Stripe Connect application fee only when a connected account is set.
 *   - The hardcoded production thank-you URL is gone. The return destination is
 *     an admin option; blank shows the built-in on-site thank-you page. No
 *     production hostname anywhere — customer-facing URLs derive from site + the
 *     Business Profile.
 *   - Stripe Connect is now OPTIONAL: with no connected account the module makes
 *     a plain charge on the site's own Stripe account (and no platform fee is
 *     possible), so a single business can invoice without a Connect setup.
 *   - Renamed off the old ts/tsic identifiers to the short `zic` prefix; REST is
 *     registered under the theme's single ZDZ_REST_NS constant (never typed
 *     twice). Tables and options carry deprecated-alias rename-map entries so an
 *     existing install upgrades in place.
 *   - Dropped the delegation to the shared cross-plugin token service: this app
 *     keeps its OWN FreshBooks OAuth credentials and refreshes them in isolation
 *     (a separate connection instance), so a primary-account refresh can never
 *     clobber the invoicing app's tokens.
 *   - Ships EMPTY: no invoices, no payments, no credentials, no seeds. The
 *     schema is created on activation; business data only ever arrives by an
 *     admin's own action.
 *
 * ── Architecture ──────────────────────────────────────────────────────
 * PREFIX:  zic_  ·  CLASSES: ZIC_* (module-local, not theme services)
 * TABLES:  wp_zic_invoices, wp_zic_payments, wp_zic_webhook_events (ship empty)
 * REST:    ZDZ_REST_NS/invoicing/webhook, ZDZ_REST_NS/invoicing/payment-intent
 * ROUTE:   /pay/<token>  (hosted pay page + on-site thank-you)
 * THEME:   registers via `zdz_register_apps` (Zorderz\Widget_App_Interface) on
 *          after_setup_theme; declines to register when the theme is absent.
 *
 * Kill switch: define('ZIC_DISABLE', true) in wp-config.php to load nothing.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Kill switch (optional/beta module). Returning from the included file leaves
// the rest of the bundle untouched.
if ( defined( 'ZIC_DISABLE' ) && ZIC_DISABLE ) {
	return;
}

define( 'ZIC_VERSION', '0.2.0-beta' );
define( 'ZIC_DB_VERSION', '1.0.0' );
define( 'ZIC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZIC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * Core default for the platform fee (Core stays empty/neutral): 0 percent, off.
 * The live value is the disclosed `zic_platform_fee_percent` admin option; this
 * constant is only the fallback default, NOT a baked business rate.
 */
define( 'ZIC_DEFAULT_PLATFORM_FEE_PERCENT', 0 );

/* ── Small helpers ──────────────────────────────────────────────────── */

if ( ! function_exists( 'zic_log' ) ) {
	function zic_log( $msg ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[ZIC] ' . ( is_string( $msg ) ? $msg : print_r( $msg, true ) ) );
		}
	}
}

if ( ! function_exists( 'zic_safe' ) ) {
	/** Wrap a callable so a throw is logged rather than fatal (beta safety). */
	function zic_safe( $callable ) {
		return function () use ( $callable ) {
			$args = func_get_args();
			try {
				return call_user_func_array( $callable, $args );
			} catch ( \Throwable $e ) {
				zic_log( 'safe-catch: ' . $e->getMessage() );
				return null;
			}
		};
	}
}

/**
 * The platform-fee rate as a fraction (e.g. 0.005 for 0.5%).
 *
 * Reads the disclosed admin option, defaulting to the neutral Core default (0).
 * Clamped to [0, 1] so a mis-entered value can never exceed 100%.
 */
function zic_platform_fee_rate() {
	$percent = (float) get_option( 'zic_platform_fee_percent', ZIC_DEFAULT_PLATFORM_FEE_PERCENT );
	if ( $percent < 0 ) {
		$percent = 0.0;
	}
	if ( $percent > 100 ) {
		$percent = 100.0;
	}
	return $percent / 100;
}

/**
 * Resolve the post-payment return destination for a hosted pay token.
 *
 * 1. The admin `zic_return_url` option, when set to a valid absolute URL.
 * 2. Otherwise the on-site pay route for this token — once the invoice is paid,
 *    that route renders the built-in on-site thank-you page.
 *
 * No production hostname is ever baked in; the fallback derives from the site.
 *
 * @param string $token Hosted pay token (may be empty).
 * @return string Absolute URL.
 */
function zic_return_url( $token = '' ) {
	$configured = trim( (string) get_option( 'zic_return_url', '' ) );
	if ( '' !== $configured && wp_http_validate_url( $configured ) ) {
		return $configured;
	}
	if ( '' !== $token ) {
		return home_url( '/pay/' . rawurlencode( $token ) );
	}
	return home_url( '/' );
}

/* ── Load module classes ────────────────────────────────────────────── */

$zic_includes = array(
	'includes/class-zic-db.php',
	'includes/class-zic-stripe.php',
	'includes/class-zic-freshbooks.php',
	'includes/class-zic-freshbooks-oauth.php',
	'includes/class-zic-mailer.php',
	'includes/class-zic-refunds.php',
	'includes/class-zic-exports.php',
	'includes/class-zic-payment-engine.php',
	'includes/class-zic-rest.php',
	'includes/class-zic-dashboard.php',
	'includes/class-zic-admin.php',
);
foreach ( $zic_includes as $rel ) {
	$path = ZIC_PLUGIN_DIR . $rel;
	try {
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	} catch ( \Throwable $e ) {
		zic_log( 'require failed: ' . $rel . ' :: ' . $e->getMessage() );
	}
}

/* ═══════════════════════════════════════════════════════════════════════════
 * ACTIVATION / DEACTIVATION (exposed to the bundle manifest)
 * ═══════════════════════════════════════════════════════════════════════════
 * The bundle's zorderz-apps.php calls these by name on plugin activation and on
 * a version bump. Schema only — the tables ship EMPTY and are never seeded.
 */

/** Create/upgrade the schema. Idempotent (dbDelta) — safe to re-run. */
function zic_install_tables() {
	if ( class_exists( 'ZIC_DB' ) ) {
		ZIC_DB::install();
	}
	update_option( 'zic_db_version', ZIC_DB_VERSION, false );
}

function zic_activate() {
	zic_install_tables();
	if ( class_exists( 'ZIC_FreshBooks_OAuth' ) ) {
		ZIC_FreshBooks_OAuth::schedule_cron();
	}
	// Route persistence (the /pay/<token> rewrite) is handled by the version-
	// guarded one-time flush on `init` below, NOT here: the bundle may call this
	// during plugins_loaded (before `init`), where a flush would regenerate the
	// rewrite set WITHOUT the theme's and other plugins' init-registered rules.
	delete_option( 'zic_rewrite_flushed' );
}

function zic_deactivate() {
	if ( class_exists( 'ZIC_FreshBooks_OAuth' ) ) {
		ZIC_FreshBooks_OAuth::unschedule_cron();
	}
	// Re-flush on the next re-activation so the /pay route is re-persisted.
	delete_option( 'zic_rewrite_flushed' );
}

/**
 * Self-heal: create/upgrade the schema on load when the module is added to an
 * already-active bundle (no activation hook fires in that case). Cheap: a single
 * option read on the fast path. Mirrors the other bundled modules.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( get_option( 'zic_db_version' ) !== ZIC_DB_VERSION ) {
			zic_install_tables();
		}
	},
	5
);

/* ── Deprecated-alias rename map (kernel migrates; plugins declare) ──── */
add_filter(
	'zdz_rename_map',
	function ( $map ) {
		$map['tables'] = array_merge(
			$map['tables'] ?? array(),
			array(
				'tsic_invoices'       => 'zic_invoices',
				'tsic_payments'       => 'zic_payments',
				'tsic_webhook_events' => 'zic_webhook_events',
			)
		);
		$map['options'] = array_merge(
			$map['options'] ?? array(),
			array(
				'tsic_stripe_secret'            => 'zic_stripe_secret',
				'tsic_stripe_publishable'       => 'zic_stripe_publishable',
				'tsic_stripe_webhook_secret'    => 'zic_stripe_webhook_secret',
				'tsic_stripe_connected_account' => 'zic_stripe_connected_account',
				'tsic_freshbooks_token'         => 'zic_freshbooks_token',
				'tsic_freshbooks_account_id'    => 'zic_freshbooks_account_id',
				'tsic_fb_client_id'             => 'zic_fb_client_id',
				'tsic_fb_client_secret'         => 'zic_fb_client_secret',
				'tsic_fb_redirect_uri'          => 'zic_fb_redirect_uri',
				'tsic_fb_access_token'          => 'zic_fb_access_token',
				'tsic_fb_refresh_token'         => 'zic_fb_refresh_token',
				'tsic_fb_token_expires_at'      => 'zic_fb_token_expires_at',
				'tsic_fb_oauth_state'           => 'zic_fb_oauth_state',
				'tsic_notify_email'             => 'zic_notify_email',
				'tsic_rewrite_flushed'          => 'zic_rewrite_flushed',
			)
		);
		$map['cron'] = array_merge(
			$map['cron'] ?? array(),
			array( 'tsic_fb_token_refresh_cron' => 'zic_fb_token_refresh_cron' )
		);
		return $map;
	}
);

/* ── Hosted pay route + shortcode ───────────────────────────────────── */
add_action(
	'init',
	zic_safe(
		function () {
			if ( class_exists( 'ZIC_REST' ) ) {
				ZIC_REST::add_rewrites();
			}
			if ( class_exists( 'ZIC_Dashboard' ) ) {
				ZIC_Dashboard::register_shortcode();
			}
		}
	)
);

// One-time, version-guarded flush AFTER all init rewrites are registered
// (priority 99), so the /pay/<token> rule is persisted on a fresh activation,
// an in-place bundle upgrade, or when this module is added to an already-active
// bundle — without ever wiping another component's rules by flushing too early.
add_action(
	'init',
	function () {
		if ( get_option( 'zic_rewrite_flushed' ) !== ZIC_VERSION ) {
			flush_rewrite_rules();
			update_option( 'zic_rewrite_flushed', ZIC_VERSION, false );
		}
	},
	99
);

add_action(
	'template_redirect',
	zic_safe(
		function () {
			if ( class_exists( 'ZIC_REST' ) ) {
				ZIC_REST::maybe_render_payment_page();
			}
		}
	)
);

/* ── REST (namespaced under the theme's single ZDZ_REST_NS) ─────────── */
add_action(
	'rest_api_init',
	zic_safe(
		function () {
			if ( class_exists( 'ZIC_REST' ) ) {
				ZIC_REST::register_routes();
			}
		}
	)
);

/* ── Admin menus, settings, and admin-post handlers ─────────────────── */
add_action(
	'admin_menu',
	zic_safe(
		function () {
			if ( class_exists( 'ZIC_Admin' ) ) {
				ZIC_Admin::register_menus();
			}
		}
	)
);
add_action(
	'admin_init',
	zic_safe(
		function () {
			if ( class_exists( 'ZIC_Admin' ) ) {
				ZIC_Admin::register_settings();
			}
		}
	)
);

add_action( 'admin_post_zic_create_invoice', zic_safe( function () {
	if ( class_exists( 'ZIC_Admin' ) ) {
		ZIC_Admin::handle_create_invoice();
	}
} ) );
add_action( 'admin_post_zic_export_csv', zic_safe( function () {
	if ( class_exists( 'ZIC_Exports' ) ) {
		ZIC_Exports::handle_csv();
	}
} ) );
add_action( 'admin_post_zic_refund', zic_safe( function () {
	if ( class_exists( 'ZIC_Refunds' ) ) {
		ZIC_Refunds::handle_refund_post();
	}
} ) );
add_action( 'admin_post_zic_fb_oauth_start', zic_safe( function () {
	if ( class_exists( 'ZIC_FreshBooks_OAuth' ) ) {
		ZIC_FreshBooks_OAuth::handle_start();
	}
} ) );
add_action( 'admin_post_zic_fb_oauth_callback', zic_safe( function () {
	if ( class_exists( 'ZIC_FreshBooks_OAuth' ) ) {
		ZIC_FreshBooks_OAuth::handle_callback();
	}
} ) );

/* ── FreshBooks token refresh cron ──────────────────────────────────── */
add_action( 'zic_fb_token_refresh_cron', zic_safe( function () {
	if ( class_exists( 'ZIC_FreshBooks_OAuth' ) ) {
		ZIC_FreshBooks_OAuth::cron_refresh();
	}
} ) );

/* ── Payment-succeeded side effects (receipt + merchant notice) ─────── */
add_action(
	'zic_payment_succeeded',
	zic_safe(
		function ( $invoice_row, $payment_intent ) {
			if ( class_exists( 'ZIC_Mailer' ) ) {
				ZIC_Mailer::send_client_receipt( $invoice_row, $payment_intent );
				ZIC_Mailer::send_merchant_notification( $invoice_row, $payment_intent );
			}
		}
	),
	10,
	2
);

/* ═══════════════════════════════════════════════════════════════════════════
 * THEME INTEGRATION — DASHBOARD APP TILE
 * ═══════════════════════════════════════════════════════════════════════════
 * The theme's interfaces are not defined until after_setup_theme (WordPress
 * loads plugins before themes), so registration is deferred here. An admin-only
 * tool: the tile deep-links to the WP-Admin Invoices screen. Deps missing → the
 * app declines to register rather than failing.
 */
add_action(
	'after_setup_theme',
	function () {

		if ( ! interface_exists( '\\Zorderz\\Widget_App_Interface' ) ) {
			return;
		}

		$app = new class implements \Zorderz\Widget_App_Interface {

			public function get_config(): array {
				return array(
					'id'          => 'invoice',
					'nm'          => 'Invoices',
					'name'        => 'Invoices',
					'icon'        => 'file-text',
					'cat'         => 'Finance',
					'cc'          => '#059669',
					'desc'        => 'Create invoices and accept card payments.',
					'description' => 'Create invoices and accept card payments.',
					// Declarative intent only — live access is granted per user via
					// `zdz_allowed_apps` meta and resolved in ZDZ_Plugin_API. An
					// admin/office tool, so it is offered to the admin-tier roles
					// (verified against ZDZ_User_Roles), not the field/kiosk roles.
					'roles'       => array( 'administrator', 'zdz_owner', 'zdz_admin' ),
					'bridge_type' => 'inline_widget',
					'admin_url'   => admin_url( 'admin.php?page=zic_dashboard' ),
				);
			}

			public function render_mobile_view( int $user_id ): void {
				echo wp_kses_post( $this->tile_html() );
			}

			public function render_dashboard_widget( int $user_id ): ?string {
				return $this->tile_html();
			}

			/** A small panel that opens the admin Invoices screen. */
			private function tile_html(): string {
				wp_enqueue_style( 'zic-widget-css', ZIC_PLUGIN_URL . 'assets/css/widget.css', array(), ZIC_VERSION );
				$url = esc_url( admin_url( 'admin.php?page=zic_dashboard' ) );
				return '<div class="zic-widget">'
					. '<h3>' . esc_html__( 'Invoices', 'zorderz' ) . '</h3>'
					. '<p>' . esc_html__( 'Create invoices and accept card payments.', 'zorderz' ) . '</p>'
					. '<a class="zic-widget-btn" href="' . $url . '">' . esc_html__( 'Open Invoices', 'zorderz' ) . '</a>'
					. '</div>';
			}
		};

		add_filter(
			'zdz_register_apps',
			function ( $apps ) use ( $app ) {
				if ( is_array( $apps ) ) {
					$apps['invoice'] = $app;
				}
				return $apps;
			}
		);
	}
);
