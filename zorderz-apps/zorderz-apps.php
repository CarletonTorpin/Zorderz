<?php
/**
 * Plugin Name: Zorderz - TS - Apps
 * Plugin URI:  https://zorderz.org
 * Description: The Zorderz app bundle - 18 apps (Camera, Media, Sketch Pad, Messaging, Quick-ID, Game, Invoices, Knowledge Base, Scheduler, Jobs, Surveys, Stock, Leads, Prep, Receipts, Estimates, Commission, and the Chat assistant). Requires the Zorderz theme, which provides the dashboard, roles, permissions, shared media store, Item Engine and Core services these apps register into.
 * Version:     1.1.0
 * Author:      Zorderz
 * Author URI:  https://zorderz.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * ── What this is ──────────────────────────────────────────────────────
 * Four apps ship as one plugin rather than four, so an install is two
 * artifacts: the theme (the platform) and this bundle (the apps). Each app
 * still lives in its own directory under apps/ and keeps its own version,
 * constants and assets — bundling changes packaging, not architecture.
 *
 * Each app registers itself with the theme through the `zdz_register_apps`
 * filter on `after_setup_theme`. An app whose dependencies are missing
 * declines to register rather than failing, so a partial install degrades
 * to fewer tiles instead of a broken dashboard.
 *
 * ── Load order ────────────────────────────────────────────────────────
 * The theme must load first: it defines ZDZ_User_Media, ZDZ_User_Roles,
 * ZDZ_Plugin_API and the `zorderz/v1` REST namespace. WordPress loads
 * plugins before themes, which is why every app defers its real work to
 * `after_setup_theme` or later. Do not move that work to plugins_loaded.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZDZ_APPS_VERSION', '1.1.0' );
define( 'ZDZ_APPS_FILE', __FILE__ );
define( 'ZDZ_APPS_DIR', plugin_dir_path( __FILE__ ) );

/**
 * The bundled apps, in load order.
 *
 * `activate` names a function to call on plugin activation, if the app has
 * first-run work (table creation, seeding, cron scheduling). Apps that
 * self-heal on load leave it null.
 */
function zdz_apps_manifest() {
	return [
		'camera' => [
			'label'      => 'Camera',
			'file'       => 'apps/camera/app.php',
			'activate'   => null,
			'deactivate' => null,
		],
		'media' => [
			'label'      => 'Media',
			'file'       => 'apps/media/app.php',
			'activate'   => null,
			'deactivate' => null,
		],
		'sketch' => [
			'label'      => 'Sketch Pad',
			'file'       => 'apps/sketch/app.php',
			'activate'   => null,
			'deactivate' => null,
		],
		'messaging' => [
			'label'      => 'Messaging',
			'file'       => 'apps/messaging/app.php',
			'activate'   => 'zim_activate',
			'deactivate' => 'zim_deactivate',
		],
		'quickid' => [
			'label'      => 'Quick-ID',
			'file'       => 'apps/quickid/app.php',
			'activate'   => null,
			'deactivate' => null,
		],
		'game' => [
			'label'      => 'Game',
			'file'       => 'apps/game/app.php',
			'activate'   => 'zg_activate',
			'deactivate' => null,
		],
		'invoice' => [
			'label'      => 'Invoices',
			'file'       => 'apps/invoice/app.php',
			'activate'   => 'zic_activate',
			'deactivate' => 'zic_deactivate',
		],
		'knowledge' => [
			'label'      => 'Knowledge',
			'file'       => 'apps/knowledge/app.php',
			'activate'   => 'zkv_activate',
			'deactivate' => 'zkv_deactivate',
		],
		'scheduler' => [
			'label'      => 'Scheduler',
			'file'       => 'apps/scheduler/app.php',
			'activate'   => 'zsch_activate',
			'deactivate' => 'zsch_deactivate',
		],
		'jobs' => [
			'label'      => 'Jobs',
			'file'       => 'apps/jobs/app.php',
			'activate'   => 'zjob_activate',
			'deactivate' => 'zjob_deactivate',
		],
		'surveys' => [
			'label'      => 'Surveys',
			'file'       => 'apps/surveys/app.php',
			'activate'   => 'zsv_activate',
			'deactivate' => 'zsv_deactivate',
		],
		'stock' => [
			'label'      => 'Stock',
			'file'       => 'apps/stock/app.php',
			'activate'   => 'zstock_activate',
			'deactivate' => 'zstock_deactivate',
		],
		'leads' => [
			'label'      => 'Leads',
			'file'       => 'apps/leads/app.php',
			'activate'   => 'zl_activate',
			'deactivate' => 'zl_deactivate',
		],
		'prep' => [
			'label'      => 'Prep',
			'file'       => 'apps/prep/app.php',
			'activate'   => 'zprep_activate',
			'deactivate' => 'zprep_deactivate',
		],
		'receipts' => [
			'label'      => 'Receipts',
			'file'       => 'apps/receipts/app.php',
			'activate'   => 'zrcpt_activate',
			'deactivate' => 'zrcpt_deactivate',
		],
		'estimate' => [
			'label'      => 'Estimates',
			'file'       => 'apps/estimate/app.php',
			'activate'   => 'zest_activate',
			'deactivate' => 'zest_deactivate',
		],
		'commission' => [
			'label'      => 'Commission',
			'file'       => 'apps/commission/app.php',
			'activate'   => 'zcc_activate',
			'deactivate' => 'zcc_deactivate',
		],
		'analytics' => [
			'label'      => 'Chat',
			'file'       => 'apps/analytics/app.php',
			'activate'   => 'zana_activate',
			'deactivate' => 'zana_deactivate',
		],
	];
}

/**
 * Load the apps.
 *
 * Wrapped per app: a fatal in one module must not take down the others or
 * the dashboard. A failure is logged and surfaced as an admin notice rather
 * than a white screen, because a stranger's first install is exactly where
 * that matters.
 */
foreach ( zdz_apps_manifest() as $slug => $app ) {
	$path = ZDZ_APPS_DIR . $app['file'];
	if ( ! file_exists( $path ) ) {
		continue;
	}
	try {
		require_once $path;
	} catch ( \Throwable $e ) {
		error_log( sprintf( '[Zorderz Apps] %s failed to load: %s', $slug, $e->getMessage() ) );
		add_action(
			'admin_notices',
			function () use ( $app, $e ) {
				printf(
					'<div class="notice notice-error"><p><strong>Zorderz:</strong> the %s app could not load. %s</p></div>',
					esc_html( $app['label'] ),
					esc_html( $e->getMessage() )
				);
			}
		);
	}
}

/**
 * Activation — run every bundled app's first-run work.
 *
 * The sub-modules call register_activation_hook( __FILE__, … ) themselves,
 * but WordPress only fires that for a plugin's own main file, so those never
 * run once bundled. Delegating here is what keeps them working.
 */
register_activation_hook(
	__FILE__,
	function () {
		foreach ( zdz_apps_manifest() as $slug => $app ) {
			if ( $app['activate'] && function_exists( $app['activate'] ) ) {
				try {
					call_user_func( $app['activate'] );
				} catch ( \Throwable $e ) {
					error_log( sprintf( '[Zorderz Apps] %s activation failed: %s', $slug, $e->getMessage() ) );
				}
			}
		}
		update_option( 'zdz_apps_version', ZDZ_APPS_VERSION, false );
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		foreach ( zdz_apps_manifest() as $slug => $app ) {
			if ( $app['deactivate'] && function_exists( $app['deactivate'] ) ) {
				try {
					call_user_func( $app['deactivate'] );
				} catch ( \Throwable $e ) {
					error_log( sprintf( '[Zorderz Apps] %s deactivation failed: %s', $slug, $e->getMessage() ) );
				}
			}
		}
	}
);

/**
 * Tell the user plainly when the theme is missing.
 *
 * Failing loudly is a deliberate choice: the previous architecture resolved
 * missing dependencies with class_exists() checks that silently produced a
 * blank dashboard, which is far harder to diagnose than an error.
 */
add_action(
	'admin_notices',
	function () {
		if ( class_exists( 'ZDZ_Plugin_API' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>Zorderz Apps</strong> needs the Zorderz theme to be active. '
			. 'The theme provides the dashboard, roles and shared media store these apps plug into — '
			. 'without it the apps load but have nowhere to appear.</p></div>';
	}
);

/**
 * Version bump — re-run activation work when the bundle is updated in place
 * (uploading a new zip over an existing install does not fire activation).
 */
add_action(
	'plugins_loaded',
	function () {
		if ( get_option( 'zdz_apps_version' ) === ZDZ_APPS_VERSION ) {
			return;
		}
		foreach ( zdz_apps_manifest() as $slug => $app ) {
			if ( $app['activate'] && function_exists( $app['activate'] ) ) {
				try {
					call_user_func( $app['activate'] );
				} catch ( \Throwable $e ) {
					error_log( sprintf( '[Zorderz Apps] %s upgrade failed: %s', $slug, $e->getMessage() ) );
				}
			}
		}
		update_option( 'zdz_apps_version', ZDZ_APPS_VERSION, false );
	},
	4
);
