<?php
/**
 * ZDZ_Privacy_View_Gate_File -- the optional must-use file that runs the Privacy View gate before any plugin loads.
 *
 * The gate that judges every request from an account in Privacy View (ZDZ_Privacy_View::gate()) runs when the theme
 * loads. A plugin's own file, and its plugins_loaded and setup_theme handlers, run before that. WordPress loads
 * must-use plugins before every other plugin, so the theme ships one file, mu-plugin/0-zdz-privacy-view-gate.php, that
 * finds a sign-in cookie naming such an account and hands the request to ZDZ_Privacy_View::early_gate() there.
 *
 * A theme never installs a must-use plugin by itself. This class:
 *   - says where things stand (status()): not installed, installed and the one this theme ships, installed and older
 *     or different, and whether the folder can be written from here;
 *   - installs, updates or removes the file ONLY when an administrator presses the button on Zorderz -> Privacy View
 *     (handle(): admin-post, a nonce, the capabilities to manage the site and to install plugins; never an account in
 *     Privacy View, the shared device or a View As preview), through WordPress's own file system layer, checking what
 *     was written, and recording who did what in the audit log and the site's log;
 *   - where the folder cannot be written from here (or the site forbids file changes), says so and names the one file
 *     to copy by hand;
 *   - adds a Site Health test.
 * Without the file everything works as before: the theme's own gate, the same lists. The file only makes the same
 * gate run earlier.
 *
 * @package Zorderz
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Privacy_View_Gate_File {

	const FILE   = '0-zdz-privacy-view-gate.php';
	const ACTION = 'zdz_privacy_view_gate_file';
	const LAST   = 'zdz_privacy_view_gate_file';   // option: the last press -- [ at, by, act, ok, version, why ]
	/** What a press can end in (the page's notice is chosen by one of these words, never by request text). */
	const RESULTS = array( 'installed', 'updated', 'removed', 'not-allowed', 'no-copy', 'not-writable', 'write-failed', 'differs', 'remove-failed', 'not-there' );

	public static function boot() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'site_health' ) );
	}

	/* ================================================================== where things are */

	/** The file as this theme ships it. */
	public static function shipped_path() {
		return rtrim( function_exists( 'get_template_directory' ) ? (string) get_template_directory() : dirname( __DIR__ ), '/\\' ) . '/mu-plugin/' . self::FILE;
	}

	/** The folder WordPress loads must-use plugins from ('' if it cannot be told). */
	public static function target_dir() {
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			return rtrim( (string) WPMU_PLUGIN_DIR, '/\\' );
		}
		return defined( 'WP_CONTENT_DIR' ) ? rtrim( (string) WP_CONTENT_DIR, '/\\' ) . '/mu-plugins' : '';
	}

	public static function target_path() {
		$dir = self::target_dir();
		return '' === $dir ? '' : $dir . '/' . self::FILE;
	}

	/** The "Version:" a copy of the file states in its header ('' if it has none). */
	public static function version_of( $text ) {
		return preg_match( '/^[ \t\/*#@]*Version:[ \t]*([0-9][0-9A-Za-z.\-]{0,20})[ \t]*$/mi', substr( (string) $text, 0, 8192 ), $m ) ? (string) $m[1] : '';
	}

	private static function read( $path ) {
		if ( '' === (string) $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		$text = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file of this site, read to compare two copies
		return false === $text ? null : (string) $text;
	}

	/**
	 * Where things stand:
	 *   state      'absent'     no file in the must-use folder
	 *              'current'    the installed file is, byte for byte, the one this theme ships
	 *              'older'      it states an older version than this theme's copy
	 *              'different'  anything else (another version, or changed by hand)
	 *   installed  the installed file's version ('' when absent or unreadable)
	 *   shipped    the version this theme ships ('' when the theme has no copy)
	 *   running    the version WordPress loaded on this request ('' when it loaded none)
	 *   writable   may it be written from here? (the site allows file changes and the folder, or the one above it,
	 *              can be written)
	 */
	public static function status() {
		$ours   = self::read( self::shipped_path() );
		$theirs = self::read( self::target_path() );
		$out    = array(
			'state'     => 'absent',
			'installed' => null === $theirs ? '' : self::version_of( $theirs ),
			'shipped'   => null === $ours ? '' : self::version_of( $ours ),
			'running'   => defined( 'ZDZ_PV_GATE_VERSION' ) ? (string) ZDZ_PV_GATE_VERSION : '',
			'writable'  => self::writable(),
		);
		if ( null !== $theirs ) {
			if ( null !== $ours && hash_equals( hash( 'sha256', $ours ), hash( 'sha256', $theirs ) ) ) {
				$out['state'] = 'current';
			} elseif ( '' !== $out['installed'] && '' !== $out['shipped'] && version_compare( $out['installed'], $out['shipped'], '<' ) ) {
				$out['state'] = 'older';
			} else {
				$out['state'] = 'different';
			}
		} elseif ( '' !== self::target_path() && file_exists( self::target_path() ) ) {
			$out['state'] = 'different';   // there, and not readable from here
		}
		return $out;
	}

	/** May the file be written from here at all? A site that forbids file changes, or a folder this process cannot write, cannot. */
	public static function writable() {
		if ( function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'zdz_privacy_view_gate_file' ) ) {
			return false;
		}
		$dir = self::target_dir();
		if ( '' === $dir ) {
			return false;
		}
		$at = is_dir( $dir ) ? $dir : dirname( $dir );
		return is_dir( $at ) && ( function_exists( 'wp_is_writable' ) ? wp_is_writable( $at ) : is_writable( $at ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- only when WordPress's own check is not loaded
	}

	/* ================================================================== who may press */

	/**
	 * May this person install, update or remove the file? Someone who manages the site AND could install a plugin by
	 * hand (WordPress refuses that capability where file changes are forbidden, and to all but network administrators
	 * on a network). Never an account in Privacy View, never the shared device, never during a View As preview.
	 */
	public static function may_manage( $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 || ! function_exists( 'user_can' ) || ! user_can( $uid, 'manage_options' ) || ! user_can( $uid, 'install_plugins' ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_View_As' ) && method_exists( 'ZDZ_View_As', 'is_emulating' ) && ZDZ_View_As::is_emulating() ) {
			return false;
		}
		return true;
	}

	/* ================================================================== the press */

	/** admin-post: install (also the update) or remove, then back to the page with one word for what happened. */
	public static function handle() {
		$uid = (int) get_current_user_id();
		if ( ! self::may_manage( $uid ) ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$result = 'not-allowed';
		if ( 'install' === $do || 'remove' === $do ) {
			$result = 'install' === $do ? self::install( self::fs(), $uid ) : self::remove( self::fs(), $uid );
		}
		$back = add_query_arg( array( 'page' => class_exists( 'ZDZ_Privacy_View' ) ? ZDZ_Privacy_View::PAGE : 'zdz-privacy-view', 'gate' => $result ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $back );
		exit;
	}

	/** WordPress's file system layer, ready to write without asking for credentials, or null. */
	private static function fs() {
		if ( ! self::writable() ) {
			return null;
		}
		if ( ! function_exists( 'WP_Filesystem' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'WP_Filesystem' ) || ! WP_Filesystem() ) {
			return null;
		}
		global $wp_filesystem;
		return is_object( $wp_filesystem ) ? $wp_filesystem : null;
	}

	/** The must-use folder as the file system layer names it ('' if it cannot be found or made). */
	private static function fs_dir( $fs ) {
		$dir = self::target_dir();
		if ( '' === $dir || ! is_object( $fs ) ) {
			return '';
		}
		$there = method_exists( $fs, 'find_folder' ) ? $fs->find_folder( $dir ) : $dir;
		if ( is_string( $there ) && '' !== $there && $fs->is_dir( $there ) ) {
			return rtrim( $there, '/\\' ) . '/';
		}
		$above = method_exists( $fs, 'find_folder' ) ? $fs->find_folder( dirname( $dir ) ) : dirname( $dir );
		if ( ! is_string( $above ) || '' === $above || ! $fs->is_dir( $above ) ) {
			return '';
		}
		$made = rtrim( $above, '/\\' ) . '/' . basename( $dir );
		return $fs->mkdir( $made ) || $fs->is_dir( $made ) ? $made . '/' : '';
	}

	/**
	 * Puts this theme's copy in the must-use folder (the first time, or over an older one). $fs: the file system layer
	 * (fs(); null when it cannot write). Returns one of RESULTS. What was written is read back and compared; the note
	 * the early gate reads of the apps' declarations is written first, so the file never runs without it.
	 */
	public static function install( $fs, $uid ) {
		$uid = (int) $uid;
		if ( ! self::may_manage( $uid ) ) {
			return 'not-allowed';
		}
		$text = self::read( self::shipped_path() );
		if ( null === $text || '' === self::version_of( $text ) ) {
			return self::done( $uid, 'install', false, '', 'no-copy' );
		}
		$version = self::version_of( $text );
		$dir     = is_object( $fs ) ? self::fs_dir( $fs ) : '';
		if ( '' === $dir ) {
			return self::done( $uid, 'install', false, $version, 'not-writable' );
		}
		$was = $fs->exists( $dir . self::FILE );
		if ( class_exists( 'ZDZ_Privacy_View' ) && method_exists( 'ZDZ_Privacy_View', 'remember_declared' ) ) {
			ZDZ_Privacy_View::remember_declared( true );
		}
		if ( ! $fs->put_contents( $dir . self::FILE, $text, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ) ) {
			return self::done( $uid, $was ? 'update' : 'install', false, $version, 'write-failed' );
		}
		$back = $fs->get_contents( $dir . self::FILE );
		if ( ! is_string( $back ) || ! hash_equals( hash( 'sha256', $text ), hash( 'sha256', $back ) ) ) {
			return self::done( $uid, $was ? 'update' : 'install', false, $version, 'differs' );
		}
		if ( function_exists( 'wp_opcache_invalidate' ) && '' !== self::target_path() ) {
			wp_opcache_invalidate( self::target_path(), true );
		}
		return self::done( $uid, $was ? 'update' : 'install', true, $version, $was ? 'updated' : 'installed' );
	}

	/** Takes the file out of the must-use folder, and the note it read with it. Returns one of RESULTS. */
	public static function remove( $fs, $uid ) {
		$uid = (int) $uid;
		if ( ! self::may_manage( $uid ) ) {
			return 'not-allowed';
		}
		$dir = is_object( $fs ) ? self::fs_dir( $fs ) : '';
		if ( '' === $dir ) {
			return self::done( $uid, 'remove', false, '', 'not-writable' );
		}
		if ( ! $fs->exists( $dir . self::FILE ) ) {
			return self::done( $uid, 'remove', false, '', 'not-there' );
		}
		if ( ! $fs->delete( $dir . self::FILE ) || $fs->exists( $dir . self::FILE ) ) {
			return self::done( $uid, 'remove', false, '', 'remove-failed' );
		}
		if ( function_exists( 'wp_opcache_invalidate' ) && '' !== self::target_path() ) {
			wp_opcache_invalidate( self::target_path(), true );
		}
		if ( class_exists( 'ZDZ_Privacy_View' ) && method_exists( 'ZDZ_Privacy_View', 'forget_declared' ) ) {
			ZDZ_Privacy_View::forget_declared();
		}
		return self::done( $uid, 'remove', true, '', 'removed' );
	}

	/** Records a press (the audit log, the site's log, and the last one for the page) and returns its result word. */
	private static function done( $uid, $act, $ok, $version, $result ) {
		$line = sprintf( 'the early gate file: %s %s%s', $act, $ok ? 'done' : 'failed (' . $result . ')', '' !== $version ? ', version ' . $version : '' );
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
			try {
				ZDZ_Admin_Dashboard::log_action( (int) $uid, 'privacy_view_gate_file', $line, 'privacy-view' );
			} catch ( \Throwable $e ) {
				unset( $e );   // the record never blocks the press
			}
		}
		error_log( 'Zorderz Privacy View: ' . $line . ' by user ' . (int) $uid ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- an administrator's change to the site's files belongs in the site's log
		if ( function_exists( 'update_option' ) ) {
			update_option( self::LAST, array( 'at' => gmdate( 'Y-m-d H:i:s' ), 'by' => (int) $uid, 'act' => (string) $act, 'ok' => (bool) $ok, 'version' => (string) $version, 'why' => (string) $result ), false );
		}
		return (string) $result;
	}

	/* ================================================================== words */

	/** One sentence for where things stand. */
	public static function status_line( array $s ) {
		switch ( $s['state'] ) {
			case 'current':
				/* translators: %s: a version number */
				return sprintf( __( 'Installed, version %s.', 'zorderz' ), $s['installed'] );
			case 'older':
				/* translators: 1: the installed version, 2: the version this theme ships */
				return sprintf( __( 'Installed, version %1$s. This theme ships version %2$s: update it.', 'zorderz' ), $s['installed'], $s['shipped'] );
			case 'different':
				/* translators: 1: the installed version (or "unknown"), 2: the version this theme ships */
				return sprintf( __( 'Installed (version %1$s), but it is not the file this theme ships (version %2$s). Replace it to be sure of what runs.', 'zorderz' ),
					'' !== $s['installed'] ? $s['installed'] : __( 'unknown', 'zorderz' ), '' !== $s['shipped'] ? $s['shipped'] : __( 'unknown', 'zorderz' ) );
		}
		return __( 'Not installed.', 'zorderz' );
	}

	/** The sentence for a press's result word. */
	public static function result_line( $result ) {
		$lines = array(
			'installed'     => __( 'The early gate is installed. It runs from the next request on.', 'zorderz' ),
			'updated'       => __( 'The early gate was updated.', 'zorderz' ),
			'removed'       => __( 'The early gate was removed. The gate still runs when the theme loads.', 'zorderz' ),
			'not-allowed'   => __( 'That is not available on your account.', 'zorderz' ),
			'no-copy'       => __( 'This theme has no copy of the file to install.', 'zorderz' ),
			'not-writable'  => __( 'The folder cannot be written from here. Copy the file by hand (below).', 'zorderz' ),
			'write-failed'  => __( 'The file could not be written. Copy it by hand (below).', 'zorderz' ),
			'differs'       => __( 'The file was written, but what is on the server is not what this theme ships. Copy it by hand (below).', 'zorderz' ),
			'remove-failed' => __( 'The file could not be removed. Delete it by hand (below).', 'zorderz' ),
			'not-there'     => __( 'There was no file to remove.', 'zorderz' ),
		);
		return $lines[ (string) $result ] ?? '';
	}

	/* ================================================================== the page section (Zorderz -> Privacy View) */

	private static function button( $do, $label, $primary, $confirm = '' ) {
		$out  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 0 0">';
		$out .= wp_nonce_field( self::ACTION, '_wpnonce', true, false );
		$out .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		$out .= '<button class="button' . ( $primary ? ' button-primary' : '' ) . '"' . ( '' !== $confirm ? ' onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '' ) . '>' . esc_html( $label ) . '</button></form>';
		return $out;
	}

	public static function render() {
		$uid = (int) get_current_user_id();
		if ( ! current_user_can( 'manage_options' ) || ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on() ) ) {
			return;
		}
		$s      = self::status();
		$result = isset( $_GET['gate'] ) ? sanitize_key( wp_unslash( $_GET['gate'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- one word from a fixed list chooses a fixed sentence; nothing is acted on
		echo '<h2>' . esc_html__( 'Early gate', 'zorderz' ) . '</h2>';
		if ( in_array( $result, self::RESULTS, true ) ) {
			$good = in_array( $result, array( 'installed', 'updated', 'removed' ), true );
			echo '<div class="notice ' . ( $good ? 'notice-success' : 'notice-warning' ) . ' inline"><p>' . esc_html( self::result_line( $result ) ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Every request from an account in Privacy View is checked when the theme loads. A plugin\'s own file, and what it does while the plugins load, run before that. This optional file is loaded by WordPress before any plugin and runs the same check there. It does nothing for anyone else, and nothing is installed unless you press the button.', 'zorderz' ) . '</p>';
		echo '<p><strong>' . esc_html( self::status_line( $s ) ) . '</strong>';
		if ( 'absent' !== $s['state'] && '' === $s['running'] ) {
			echo ' ' . esc_html__( 'WordPress did not load it on this request.', 'zorderz' );
		}
		echo '</p>';
		$last = get_option( self::LAST, null );
		if ( is_array( $last ) && ! empty( $last['at'] ) ) {
			$who = function_exists( 'get_userdata' ) ? get_userdata( (int) ( $last['by'] ?? 0 ) ) : null;
			/* translators: 1: what was done (install, update, remove), 2: "done" or "failed", 3: a date and time (UTC), 4: a person's name */
			echo '<p class="description">' . esc_html( sprintf( __( 'Last change: %1$s, %2$s, %3$s UTC, by %4$s.', 'zorderz' ), (string) ( $last['act'] ?? '' ), ! empty( $last['ok'] ) ? __( 'done', 'zorderz' ) : __( 'failed', 'zorderz' ), (string) $last['at'],
				$who && isset( $who->display_name ) ? (string) $who->display_name : '#' . (int) ( $last['by'] ?? 0 ) ) ) . '</p>';
		}
		if ( '' === $s['shipped'] ) {
			echo '<p>' . esc_html__( 'This theme has no copy of the file.', 'zorderz' ) . '</p>';
			return;
		}
		$may = self::may_manage( $uid );
		if ( $may && $s['writable'] ) {
			echo '<p>';
			if ( 'absent' === $s['state'] ) {
				echo self::button( 'install', __( 'Install the early gate', 'zorderz' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- button() is built from esc_url(), esc_attr(), esc_html(), wp_nonce_field() and fixed text
			} elseif ( 'current' !== $s['state'] ) {
				echo self::button( 'install', 'older' === $s['state'] ? __( 'Update it', 'zorderz' ) : __( 'Replace it with this theme\'s copy', 'zorderz' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above
			}
			if ( 'absent' !== $s['state'] ) {
				echo self::button( 'remove', __( 'Remove it', 'zorderz' ), false, __( 'Remove the early gate? Requests from an account in Privacy View are then checked when the theme loads, as on a site without it.', 'zorderz' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above
			}
			echo '</p>';
			return;
		}
		echo '<p>' . esc_html( $may
			? __( 'The folder cannot be written from here (or this site does not allow file changes from wp-admin). Copy this one file by hand:', 'zorderz' )
			: __( 'Installing it takes an administrator who may install plugins. It can also be copied by hand, one file:', 'zorderz' ) ) . '</p>';
		echo '<p><code>' . esc_html( self::shipped_path() ) . '</code><br>&rarr; <code>' . esc_html( self::target_dir() . '/' ) . '</code></p>';
		echo '<p class="description">' . esc_html__( 'To turn it off, delete the file there. Keep it in step with the theme: after a theme update, copy the file again if this page says it is older.', 'zorderz' ) . '</p>';
	}

	/* ================================================================== Site Health */

	public static function site_health( $tests ) {
		$tests['direct']['zdz_privacy_view_gate_file'] = array(
			'label' => __( 'Zorderz: the Privacy View early gate', 'zorderz' ),
			'test'  => array( __CLASS__, 'site_health_result' ),
		);
		return $tests;
	}

	/** Is any account in Privacy View? */
	private static function any_account() {
		if ( ! function_exists( 'get_users' ) || ! class_exists( 'ZDZ_Privacy_View' ) ) {
			return false;
		}
		return (bool) get_users( array( 'meta_key' => ZDZ_Privacy_View::META, 'meta_value' => '1', 'fields' => 'ID', 'number' => 1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one row, on the Site Health screen only
	}

	public static function site_health_result() {
		$s    = self::status();
		$used = self::any_account();
		$page = admin_url( 'admin.php?page=' . ( class_exists( 'ZDZ_Privacy_View' ) ? ZDZ_Privacy_View::PAGE : 'zdz-privacy-view' ) );
		$out  = array(
			'badge'   => array( 'label' => __( 'Security', 'zorderz' ), 'color' => 'blue' ),
			'test'    => 'zdz_privacy_view_gate_file',
			'status'  => 'good',
			'actions' => '<p><a href="' . esc_url( $page ) . '">' . esc_html__( 'Zorderz → Privacy View', 'zorderz' ) . '</a></p>',
		);
		$what = '<p>' . esc_html__( 'Requests from an account in Privacy View are checked when the theme loads. The optional early gate is a must-use file that runs the same check before any plugin loads.', 'zorderz' ) . '</p>';
		if ( 'current' === $s['state'] ) {
			return $out + array(
				'label'       => __( 'The Privacy View early gate is installed and up to date', 'zorderz' ),
				'description' => $what . '<p>' . esc_html( self::status_line( $s ) ) . '</p>',
			);
		}
		if ( 'absent' === $s['state'] ) {
			if ( ! $used ) {
				return $out + array(
					'label'       => __( 'No account is in Privacy View, so the early gate has nothing to do', 'zorderz' ),
					'description' => $what . '<p>' . esc_html__( 'It is not installed. Nothing needs doing.', 'zorderz' ) . '</p>',
				);
			}
			$out['status'] = 'recommended';
			return $out + array(
				'label'       => __( 'An account is in Privacy View and the early gate is not installed', 'zorderz' ),
				'description' => $what . '<p>' . esc_html__( 'Its requests are held to the same lists either way. With the early gate, nothing a plugin does while the plugins load runs for a request that is outside those lists.', 'zorderz' ) . '</p>',
			);
		}
		$out['status'] = 'recommended';
		return $out + array(
			'label'       => __( 'The Privacy View early gate is not the file this theme ships', 'zorderz' ),
			'description' => $what . '<p>' . esc_html( self::status_line( $s ) ) . '</p>',
		);
	}
}
