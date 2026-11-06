<?php
/**
 * ZCAM_Video_Store — where a video clip's streamed parts wait until the clip is finished, and the limits on them.
 *
 * While a clip records, its parts (one every ~2 s) are uploaded one by one and kept as
 * <root>/<user id>/<capture id>/<n>.part until the finalize request joins them into one video. This class decides:
 *
 *   - Who may stream parts at all (may_record()): a signed-in person who has the Camera app, never the shared device
 *     (by the platform's trait, ZDZ_User_Roles::is_shared_device) and never a device in Demo Mode. Photos are not
 *     affected.
 *   - How much one person may have waiting (admit()): at most MAX_OPEN unfinished clips and QUOTA_BYTES of parts. A
 *     business may lower both (filters zcam_video_max_open_captures, zcam_video_quota_bytes), never raise them. Over a
 *     limit the part is refused with "try later" (HTTP 429): the phone keeps it and sends it again once earlier clips
 *     are finished.
 *   - Where the parts live (root()): ZCAM_VIDEO_TMP_DIR (wp-config) or the filter zcam_video_tmp_dir when set;
 *     otherwise a folder beside the WordPress install when that is outside the web root and the host lets the site
 *     write there; otherwise a folder under uploads with an unguessable name. The choice is remembered (option
 *     zcam_video_tmp_root) so every request and the clean-up find the same place. The root gets deny-all rules
 *     (.htaccess, which also covers every folder below it) and an empty index.php. A clip that began under the earlier fixed uploads/zcam-video-tmp folder
 *     finishes there.
 *   - Clean-up (purge(), hourly on the zcam_video_tmp_purge event): a clip none of whose parts has been written for
 *     TTL seconds (24 hours) is abandoned; its folder is removed. Finalize removes a finished clip's parts itself.
 *
 * @package Zorderz\Camera
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ZCAM_Video_Store {

	const TTL          = 86400;        // a clip with no new part for a day is abandoned
	const MAX_OPEN     = 8;            // unfinished clips per person
	const QUOTA_BYTES  = 1073741824;   // 1 GiB of waiting parts per person
	const MIN_QUOTA    = 26214400;     // never below one maximum-size part (25 MiB)
	const PURGE_BUDGET = 500;          // clip folders looked at per clean-up run
	const CRON_HOOK    = 'zcam_video_tmp_purge';
	const ROOT_OPTION  = 'zcam_video_tmp_root';
	const LEGACY_DIR   = 'zcam-video-tmp';

	public static function boot(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'purge' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/** The hourly clean-up, scheduled once (self-healing on every request's init). */
	public static function schedule(): void {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 600, 'hourly', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/* ── who ──────────────────────────────────────────────────────────────── */

	/** The shared device (the platform's trait, else its role), or this device in Demo Mode. */
	public static function is_shared_device( int $uid ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		if ( $uid === (int) get_current_user_id()
			&& class_exists( 'ZDZ_Kiosk_Demo' ) && method_exists( 'ZDZ_Kiosk_Demo', 'is_active' ) && ZDZ_Kiosk_Demo::is_active() ) {
			return true;
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) ) {
			return ZDZ_User_Roles::is_shared_device( $uid );
		}
		$u = get_userdata( $uid );
		return $u && in_array( 'zdz_general', (array) $u->roles, true );
	}

	/** May this person stream a clip? '' yes; else 'signed_out' | 'shared' | 'grant'. */
	public static function may_record( int $uid ): string {
		if ( $uid <= 0 ) {
			return 'signed_out';
		}
		if ( self::is_shared_device( $uid ) ) {
			return 'shared';
		}
		if ( is_callable( array( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) ) {
			return ZDZ_Plugin_API::user_can_access_app( $uid, 'zdz-camera' ) ? '' : 'grant';
		}
		return ( user_can( $uid, 'manage_options' ) || user_can( $uid, 'zdz_access_app' ) ) ? '' : 'grant';
	}

	/** The refusal for may_record()'s answer, in words. */
	public static function refusal( string $why ): string {
		if ( 'shared' === $why ) {
			return __( 'Video is not available on the shared device. Photos still work.', 'zorderz' );
		}
		if ( 'grant' === $why ) {
			return __( 'The Camera is not one of your apps.', 'zorderz' );
		}
		return __( 'Not logged in.', 'zorderz' );
	}

	/* ── limits ───────────────────────────────────────────────────────────── */

	public static function max_open(): int {
		$n = (int) apply_filters( 'zcam_video_max_open_captures', self::MAX_OPEN );
		return max( 1, min( self::MAX_OPEN, $n ) );
	}

	public static function quota_bytes(): int {
		$n = (int) apply_filters( 'zcam_video_quota_bytes', self::QUOTA_BYTES );
		return max( self::MIN_QUOTA, min( self::QUOTA_BYTES, $n ) );
	}

	/**
	 * What a person has waiting, read from their folders: { bytes, open, captures[] }.
	 *
	 * @param string[] $user_dirs this person's folder under each root
	 */
	public static function usage( array $user_dirs ): array {
		$bytes    = 0;
		$captures = array();
		foreach ( $user_dirs as $ud ) {
			if ( '' === (string) $ud || ! is_dir( $ud ) ) {
				continue;
			}
			foreach ( (array) glob( rtrim( $ud, '/' ) . '/*', GLOB_ONLYDIR ) as $cap ) {
				$captures[ basename( $cap ) ] = true;
				foreach ( (array) glob( $cap . '/*' ) as $f ) {
					if ( is_file( $f ) ) {
						$bytes += (int) filesize( $f );
					}
				}
			}
		}
		return array( 'bytes' => $bytes, 'open' => count( $captures ), 'captures' => array_keys( $captures ) );
	}

	/**
	 * May one more part be kept? '' yes; 'open' (too many unfinished clips) or 'bytes' (over the quota). A part that
	 * replaces one already kept (a retry) counts only its difference.
	 */
	public static function admit( array $usage, string $capture, int $incoming, int $replacing, int $max_open, int $quota ): string {
		$known = in_array( $capture, (array) ( $usage['captures'] ?? array() ), true );
		if ( ! $known && (int) ( $usage['open'] ?? 0 ) >= $max_open ) {
			return 'open';
		}
		$after = (int) ( $usage['bytes'] ?? 0 ) - max( 0, $replacing ) + max( 0, $incoming );
		return $after > $quota ? 'bytes' : '';
	}

	/* ── where ────────────────────────────────────────────────────────────── */

	/** The folder parts are kept under (no trailing slash). */
	public static function root(): string {
		$forced = defined( 'ZCAM_VIDEO_TMP_DIR' ) ? (string) ZCAM_VIDEO_TMP_DIR : '';
		$forced = (string) apply_filters( 'zcam_video_tmp_dir', $forced );
		if ( '' !== trim( $forced ) ) {
			return rtrim( trim( $forced ), '/\\' );
		}
		$saved = (string) get_option( self::ROOT_OPTION, '' );
		if ( '' !== $saved && is_dir( $saved ) && is_writable( $saved ) ) {
			return $saved;
		}
		$up   = wp_get_upload_dir();
		$root = self::pick_root(
			defined( 'ABSPATH' ) ? (string) ABSPATH : '',
			isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a server path, only resolved with realpath() to compare locations; never output or stored
			(string) ( $up['basedir'] ?? '' ),
			self::tag()
		);
		if ( '' !== $root && is_dir( $root ) ) {
			update_option( self::ROOT_OPTION, $root, false );
		}
		return $root;
	}

	/**
	 * Beside the WordPress install when that is outside the web root and writable, else uploads/zcam-video-<tag>.
	 * Creates the folder it picks, with its guard files.
	 */
	public static function pick_root( string $abspath, string $docroot, string $uploads, string $tag ): string {
		$doc    = '' !== $docroot ? realpath( $docroot ) : false;
		$parent = '' !== $abspath ? realpath( dirname( rtrim( $abspath, '/\\' ) ) ) : false;
		if ( $doc && $parent && ! self::inside( $parent, $doc ) && is_writable( $parent ) ) {
			$dir = $parent . '/zorderz-private-' . $tag . '/video-tmp';
			if ( self::make( $dir ) ) {
				self::guard( dirname( $dir ) );
				self::guard( $dir );
				return $dir;
			}
		}
		if ( '' === $uploads ) {
			return '';
		}
		$dir = rtrim( $uploads, '/\\' ) . '/zcam-video-' . $tag;
		if ( self::make( $dir ) ) {
			self::guard( $dir );
		}
		return $dir;
	}

	/** The fixed folder earlier versions used; read for clips that began there, and cleaned up. */
	public static function legacy_root(): string {
		$up = wp_get_upload_dir();
		return '' !== (string) ( $up['basedir'] ?? '' ) ? rtrim( (string) $up['basedir'], '/\\' ) . '/' . self::LEGACY_DIR : '';
	}

	/** A short name derived from the site's own salt, so the folder under uploads cannot be guessed. */
	public static function tag(): string {
		$seed = ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' ) . ( defined( 'ABSPATH' ) ? ABSPATH : '' ) . 'zcam-video-tmp';
		return substr( hash( 'sha256', $seed ), 0, 16 );
	}

	/** This person's folder under each root that may hold their parts. */
	public static function user_dirs( int $user_id ): array {
		$out = array();
		foreach ( array( self::root(), self::legacy_root() ) as $r ) {
			if ( '' !== $r ) {
				$out[] = $r . '/' . $user_id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** A clip's folder ('' for a bad id). A clip that began in the earlier folder stays there until it is finished. */
	public static function parts_dir( int $user_id, string $capture_uid, bool $create = false ): string {
		$safe = (string) preg_replace( '/[^A-Za-z0-9\-]/', '', $capture_uid );
		if ( '' === $safe || $user_id <= 0 ) {
			return '';
		}
		$legacy = self::legacy_root();
		if ( '' !== $legacy && is_dir( $legacy . '/' . $user_id . '/' . $safe ) ) {
			self::guard( $legacy );
			return $legacy . '/' . $user_id . '/' . $safe;
		}
		$root = self::root();
		if ( '' === $root ) {
			return '';
		}
		$dir = $root . '/' . $user_id . '/' . $safe;
		if ( $create && ! is_dir( $dir ) ) {
			self::make( $dir );
			self::guard( $root );
		}
		return $dir;
	}

	/** Deny-all rules for the web server, and an empty index, in a folder. */
	public static function guard( string $dir ): void {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
		}
	}

	/* ── clean-up ─────────────────────────────────────────────────────────── */

	/** The hourly run: every root, abandoned clips removed. Returns how many clip folders went. */
	public static function purge( $now = null ): int {
		$now  = is_int( $now ) ? $now : time();
		$gone = 0;
		foreach ( array_unique( array_filter( array( self::root(), self::legacy_root() ) ) ) as $r ) {
			$gone += self::purge_root( $r, $now, self::TTL, self::PURGE_BUDGET );
			self::guard( $r );
		}
		return $gone;
	}

	/** Remove every clip folder under $root whose newest part is older than $ttl; then empty person folders. */
	public static function purge_root( string $root, int $now, int $ttl, int $budget ): int {
		if ( '' === $root || ! is_dir( $root ) ) {
			return 0;
		}
		$gone = 0;
		$seen = 0;
		foreach ( (array) glob( rtrim( $root, '/' ) . '/*', GLOB_ONLYDIR ) as $ud ) {
			if ( ! ctype_digit( basename( $ud ) ) ) {
				continue;
			}
			foreach ( (array) glob( $ud . '/*', GLOB_ONLYDIR ) as $cap ) {
				if ( ++$seen > $budget ) {
					return $gone;
				}
				$newest = (int) @filemtime( $cap );
				foreach ( (array) glob( $cap . '/*' ) as $f ) {
					$newest = max( $newest, (int) @filemtime( $f ) );
				}
				if ( $newest < $now - $ttl ) {
					self::remove_dir( $cap );
					$gone++;
				}
			}
			$left = glob( $ud . '/*' );
			if ( is_array( $left ) && ! $left ) {
				@rmdir( $ud ); // phpcs:ignore
			}
		}
		return $gone;
	}

	/** Remove a clip's folder and its files (one level: parts and the joined clip). */
	public static function remove_dir( string $dir ): void {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		foreach ( array_merge( (array) glob( $dir . '/*' ), (array) glob( $dir . '/.[!.]*' ) ) as $f ) {
			if ( is_file( $f ) ) {
				@unlink( $f ); // phpcs:ignore
			}
		}
		@rmdir( $dir ); // phpcs:ignore
	}

	/* ── helpers ──────────────────────────────────────────────────────────── */

	private static function inside( string $path, string $base ): bool {
		$path = rtrim( $path, '/\\' ) . '/';
		$base = rtrim( $base, '/\\' ) . '/';
		return 0 === strpos( $path, $base );
	}

	private static function make( string $dir ): bool {
		if ( ! is_dir( $dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $dir );
			} else {
				@mkdir( $dir, 0750, true ); // phpcs:ignore
			}
		}
		return is_dir( $dir ) && is_writable( $dir );
	}
}
