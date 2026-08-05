<?php
/**
 * ZDZ_Apps_AutoInstall — one-upload onboarding.
 *
 * The Zorderz Apps bundle is a separate WordPress plugin by design: the apps
 * must be independently deactivatable and updatable, and WordPress keeps themes
 * and plugins apart. But making a new operator upload TWO artifacts to get a
 * working platform is friction we don't need. So a release theme SHIPS a copy of
 * the apps bundle under /bundled/zorderz-apps/, and once the theme is active this
 * class installs and activates that plugin ONCE, automatically. Net result:
 * upload one theme zip and the whole platform comes up.
 *
 * The standalone apps zip still ships and is still the update path; this only
 * removes the SECOND upload on a first install. It changes nothing about the
 * "theme is the platform, plugin is the apps" architecture — the theme is active
 * first (that is what runs this code), exactly as the manual order requires.
 *
 * Guarantees:
 *  - One-time. Once we have acted, a flag is set and we never touch the apps
 *    again, so an operator who later DEACTIVATES them is left alone (we do not
 *    re-activate on every admin load).
 *  - Never clobber. If the apps plugin is already present (installed by hand, or
 *    by a previous run), we do not overwrite it.
 *  - Fail soft. If the plugins folder cannot be written (locked host / perms) we
 *    surface a one-line notice with the manual step and allow a later retry; we
 *    never fatal. The original two-artifact manual install still works untouched.
 *  - Degrade cleanly. A theme built without the bundle (a raw source checkout)
 *    simply falls back to the manual path with a notice.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Apps_AutoInstall {

	/** The bundled plugin's entry file, relative to WP_PLUGIN_DIR. */
	const PLUGIN_FILE = 'zorderz-apps/zorderz-apps.php';

	/** Set once we have installed/activated (or decided we cannot); stops re-runs. */
	const DONE_OPTION = 'zdz_apps_autoinstall_done';

	/** Carries a single admin notice across one redirect. */
	const NOTICE_TRANSIENT = 'zdz_apps_autoinstall_notice';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	/**
	 * Runs on admin_init. Cheap and self-guarding: after the one-time job is done
	 * this returns on the first line, so the steady-state cost is a single
	 * get_option().
	 */
	public static function maybe_install() {
		// Only someone who could do this by hand may trigger it.
		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		// We act at most once; after that the apps are the operator's to manage.
		if ( get_option( self::DONE_OPTION ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		// Case 1: already present. Do not overwrite. Activate once if it has never
		// been turned on (so a first boot is complete), then mark done.
		if ( file_exists( WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE ) ) {
			if ( ! is_plugin_active( self::PLUGIN_FILE ) ) {
				$res = activate_plugin( self::PLUGIN_FILE );
				if ( is_wp_error( $res ) ) {
					self::set_notice( 'error', 'Zorderz Apps is installed but could not be activated automatically (' . $res->get_error_message() . '). Activate "Zorderz Apps" under Plugins.' );
				}
			}
			update_option( self::DONE_OPTION, '1', false );
			return;
		}

		// Case 2: a theme built without the bundle (raw source checkout). Nothing
		// to copy; fall back to the manual path and stop trying.
		$src = get_template_directory() . '/bundled/zorderz-apps';
		if ( ! is_dir( $src ) || ! file_exists( $src . '/zorderz-apps.php' ) ) {
			update_option( self::DONE_OPTION, '1', false );
			self::set_notice( 'warning', 'Zorderz has no bundled apps package to install. Add the Zorderz Apps plugin under Plugins -> Add New to finish setup.' );
			return;
		}

		// Case 3: install it. Copy the bundled folder into the plugins directory
		// through the WordPress filesystem abstraction, then activate.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			// Cannot get write access right now; leave the flag UNSET so a later
			// load (or after the operator fixes permissions) can retry.
			self::set_notice( 'warning', 'Zorderz could not write to the plugins folder automatically. Upload the Zorderz Apps plugin zip under Plugins -> Add New to finish setup.' );
			return;
		}

		global $wp_filesystem;
		$dest = WP_PLUGIN_DIR . '/zorderz-apps';
		if ( ! $wp_filesystem->is_dir( $dest ) ) {
			$wp_filesystem->mkdir( $dest );
		}

		$copied = copy_dir( $src, $dest );
		if ( is_wp_error( $copied ) || ! file_exists( WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE ) ) {
			$why = is_wp_error( $copied ) ? $copied->get_error_message() : 'the plugin file did not appear';
			self::set_notice( 'warning', 'Zorderz could not auto-install the apps (' . $why . '). Upload the Zorderz Apps plugin zip under Plugins -> Add New to finish setup.' );
			return; // no done flag: allow a retry on a later load.
		}

		$activated = activate_plugin( self::PLUGIN_FILE );
		update_option( self::DONE_OPTION, '1', false );
		if ( is_wp_error( $activated ) ) {
			self::set_notice( 'error', 'Zorderz installed the apps but activation failed (' . $activated->get_error_message() . '). Activate "Zorderz Apps" under Plugins.' );
			return;
		}
		self::set_notice( 'success', 'Zorderz Apps were installed and activated automatically. Reload to see the app menus. Your platform is ready.' );
	}

	private static function set_notice( $type, $msg ) {
		set_transient( self::NOTICE_TRANSIENT, array( 'type' => $type, 'msg' => $msg ), 5 * MINUTE_IN_SECONDS );
	}

	public static function render_notice() {
		$n = get_transient( self::NOTICE_TRANSIENT );
		if ( ! is_array( $n ) || empty( $n['msg'] ) ) {
			return;
		}
		delete_transient( self::NOTICE_TRANSIENT );
		$map   = array( 'success' => 'notice-success', 'warning' => 'notice-warning', 'error' => 'notice-error' );
		$class = isset( $map[ $n['type'] ] ) ? $map[ $n['type'] ] : 'notice-info';
		printf(
			'<div class="notice %s is-dismissible"><p><strong>Zorderz:</strong> %s</p></div>',
			esc_attr( $class ),
			esc_html( $n['msg'] )
		);
	}
}
