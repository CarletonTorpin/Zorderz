<?php
/**
 * Plugin Name: Zorderz Apps
 * Plugin URI:  https://zorderz.org
 * Description: The Zorderz app bundle - 18 apps (Camera, Media, Sketch Pad, Messaging, Quick-ID, Game, Invoices, Knowledge Base, Scheduler, Jobs, Surveys, Stock, Leads, Prep, Receipts, Estimates, Commission, and the Chat assistant). Requires the Zorderz theme, which provides the dashboard, roles, permissions, shared media store, Item Engine and Core services these apps register into.
 * Version:     1.6.0
 * Author:      Zorderz
 * Author URI:  https://zorderz.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * ── What this is ──────────────────────────────────────────────────────
 * The apps ship as one plugin rather than many, so an install is two
 * artifacts: the theme (the platform) and this bundle (the apps). Each app
 * still lives in its own directory under apps/ and keeps its own version,
 * constants and assets — bundling changes packaging, not architecture.
 *
 * Versioning: from 1.2.0 the theme and this bundle move in lockstep on one
 * number (the distribution version). The per-app constants below are internal
 * and drive each app's own dbDelta migrations; they are not the release number.
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

define( 'ZDZ_APPS_VERSION', '1.6.0' );
define( 'ZDZ_APPS_FILE', __FILE__ );
define( 'ZDZ_APPS_DIR', plugin_dir_path( __FILE__ ) );

/**
 * v1.1.7: optional runtime capture of PHP error_log() output to a readable file.
 *
 * On managed hosts (e.g. WP Engine) PHP's error_log is often routed to syslog and
 * there is no wp-content/debug.log, so the platform's own error_log() diagnostics
 * (chat/model failures, KV bridge, indexing) are not readable as a file. When an
 * admin turns this on (the zdz_debug_capture option, toggled via the diagnostics
 * endpoint), redirect subsequent error_log() output to a readable file that the
 * debug-log reader already picks up. Off by default — no behaviour change on a
 * normal install. Applied as early as possible so it catches the whole request.
 */
if ( function_exists( 'get_option' ) && get_option( 'zdz_debug_capture' ) ) {
	@ini_set( 'log_errors', '1' );
	@ini_set( 'error_log', ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' ) . '/zorderz-debug.log' );
}

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

/**
 * Admin diagnostics — read the site's debug / PHP error log.
 *
 * v1.1.6: a read-only, manage_options-gated REST endpoint that returns the tail of
 * the site's log so functional issues (502s, failed model calls, indexing) can be
 * diagnosed from the actual runtime, not guessed. It probes the usual locations
 * (wp-content/debug.log, PHP's ini error_log, and any *.log beside wp-content) and
 * returns the newest readable one plus a list of everything it checked.
 *
 *   GET {ZDZ_REST_NS}/diagnostics/debug-log?bytes=200000   (X-WP-Nonce: wp_rest)
 */
add_action(
	'rest_api_init',
	function () {
		$ns = defined( 'ZDZ_REST_NS' ) ? ZDZ_REST_NS : 'zorderz/v1';
		register_rest_route(
			$ns,
			'/diagnostics/debug-log',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => 'zdz_apps_read_debug_log',
			)
		);
	}
);

function zdz_apps_read_debug_log( $request ) {
	// Toggle runtime capture (see the note at the top of this file).
	$cap = $request->get_param( 'capture' );
	if ( 'on' === $cap ) {
		update_option( 'zdz_debug_capture', '1', false );
		@ini_set( 'log_errors', '1' );
		@ini_set( 'error_log', ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' ) . '/zorderz-debug.log' );
		error_log( '[zorderz] debug capture enabled at ' . gmdate( 'c' ) );
	} elseif ( 'off' === $cap ) {
		update_option( 'zdz_debug_capture', '', false );
	}

	$bytes = (int) $request->get_param( 'bytes' );
	if ( $bytes <= 0 ) {
		$bytes = 200000;
	}
	$bytes = max( 1024, min( 2000000, $bytes ) );

	$content_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ( ABSPATH . 'wp-content' );
	$cands = array(
		$content_dir . '/debug.log',
		(string) ini_get( 'error_log' ),
		ABSPATH . 'error_log',
		dirname( ABSPATH ) . '/logs/php_errorlog',
		dirname( $content_dir ) . '/logs/php_errorlog',
	);
	foreach ( array( $content_dir, dirname( $content_dir ), untrailingslashit( ABSPATH ) ) as $d ) {
		foreach ( (array) @glob( rtrim( (string) $d, '/' ) . '/*.log' ) as $g ) {
			$cands[] = $g;
		}
	}
	$cands = array_values( array_unique( array_filter( $cands ) ) );

	$checked    = array();
	$best       = '';
	$best_score = -1;
	foreach ( $cands as $p ) {
		$readable = @is_file( $p ) && @is_readable( $p );
		$sz       = $readable ? (int) @filesize( $p ) : 0;
		$mt       = $readable ? (int) @filemtime( $p ) : 0;
		$checked[] = array(
			'path'     => $p,
			'readable' => (bool) $readable,
			'size'     => $sz,
			'mtime'    => $mt ? gmdate( 'Y-m-d H:i:s', $mt ) : '',
		);
		if ( $readable ) {
			$score = ( basename( $p ) === 'debug.log' ? 10000000000 : 0 ) + $mt;
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $p;
			}
		}
	}

	$tail = '';
	$size = 0;
	if ( '' !== $best ) {
		$size = (int) @filesize( $best );
		$fh   = @fopen( $best, 'rb' );
		if ( $fh ) {
			if ( $size > $bytes ) {
				@fseek( $fh, -$bytes, SEEK_END );
			}
			$tail = (string) stream_get_contents( $fh );
			@fclose( $fh );
		}
	}

	return array(
		'path'           => $best,
		'size'           => $size,
		'returned_bytes' => strlen( $tail ),
		'candidates'     => $checked,
		'wp_debug'       => defined( 'WP_DEBUG' ) ? (bool) WP_DEBUG : false,
		'wp_debug_log'   => defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false,
		'ini_error_log'  => (string) ini_get( 'error_log' ),
		'capture'        => (bool) get_option( 'zdz_debug_capture' ),
		'tail'           => $tail,
	);
}
