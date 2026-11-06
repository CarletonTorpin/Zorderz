<?php
/**
 * ZKV_File_Names — every Vault file gets a name nobody can guess.
 *
 * Vault files live in wp-content/uploads/zkv-vault/. A deny-all .htaccess there protects
 * nothing on a server that serves static files without reading it (nginx), so a file
 * stored under its ORIGINAL name could be downloaded by anyone who guessed that name.
 * The files stay in the uploads folder (a host's uploads backup includes them), but each
 * one is stored as 32 random hex characters plus its extension. The name a person sees
 * (and downloads as) is the document's original_name. A file can only be fetched by
 * someone who already holds its 128-bit random name; a web-server rule denying the folder
 * is still recommended where one can be set.
 *
 * - New files: every writer asks random_name() (uploads through wp_handle_upload's
 *   unique_filename_callback).
 * - Existing files: renamed in place, in small batches (a lock, BATCH per run, WP-Cron
 *   plus a throttled admin page load). Before each rename the old -> new path is recorded
 *   (relative to uploads, so it survives a staging copy), so a reader holding an old path
 *   still finds the file (renamed()). A media attachment's file, its generated sizes and
 *   its original image are renamed with it. Every row naming the old path is updated.
 *   A file that cannot be renamed is retried at most MAX_ATTEMPTS times, then left and
 *   counted. Unreferenced old-named files in the Vault folder (older than an hour, so an
 *   upload in progress is never touched) are renamed too.
 * - Vault documents filed elsewhere in uploads are renamed only when the attachment is
 *   the Vault's own (post_status private) or there is no attachment; others are counted.
 * - ZKV_PRIVATE_DIR (wp-config.php) still lets another host keep the files anywhere.
 * - Logs carry counts only, never names.
 *
 * Layer: [CORE].
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZKV_File_Names {

	const BATCH        = 25;
	const MAX_ATTEMPTS = 5;
	const ORPHAN_AGE   = 3600;
	const LOCK         = 'zkv_rename_lock';      // an option, taken with add_option() (atomic)
	const STATUS_OPT   = 'zkv_rename_status';
	const THROTTLE     = 'zkv_rename_admin_throttle';
	const DONE_OPT     = 'zkv_names_done';
	const MAP_OPT      = 'zkv_renamed_paths';
	const TRIES_OPT    = 'zkv_rename_tries';
	const CRON_HOOK    = 'zkv_rename_files';

	// ── where and what ─────────────────────────────────────────────

	/** The Vault folder: ZKV_PRIVATE_DIR when wp-config defines it, else uploads/zkv-vault. */
	public static function vault_dir() {
		if ( defined( 'ZKV_PRIVATE_DIR' ) && '' !== (string) ZKV_PRIVATE_DIR ) {
			return rtrim( (string) ZKV_PRIVATE_DIR, '/\\' );
		}
		return self::uploads_dir() . '/zkv-vault';
	}

	public static function uploads_dir() {
		$up = wp_upload_dir();
		return rtrim( (string) $up['basedir'], '/\\' );
	}

	/** 32 random hex characters plus the original extension (lower case, letters and digits only). */
	public static function random_name( $original = '' ) {
		$ext = strtolower( (string) pathinfo( (string) $original, PATHINFO_EXTENSION ) );
		$ext = preg_match( '/^[a-z0-9]{1,8}$/', $ext ) ? $ext : '';
		return bin2hex( random_bytes( 16 ) ) . ( '' !== $ext ? '.' . $ext : '' );
	}

	/** wp_handle_upload's unique_filename_callback( $dir, $name, $ext ). */
	public static function upload_name_cb( $dir, $name, $ext = '' ) {
		return self::random_name( $name );
	}

	/** Already random? (a generated size keeps the base and adds "-150x150") */
	public static function is_random_name( $basename ) {
		return 1 === preg_match( '/^[0-9a-f]{32}(?:-[a-z0-9_-]+)?(?:\.[a-z0-9]{1,8})?$/', (string) $basename );
	}

	/**
	 * May a resolved file path be served or read? Inside the Vault folder or uploads, never
	 * elsewhere. The folder separator is required after the root ("uploads/zkv-vault-evil"
	 * is not inside "uploads/zkv-vault").
	 */
	public static function is_allowed_path( $real ) {
		$real = (string) $real;
		if ( '' === $real ) { return false; }
		foreach ( array( self::vault_dir(), self::uploads_dir() ) as $root ) {
			$r = realpath( $root );
			if ( $r && ( $real === $r || 0 === strpos( $real, rtrim( $r, '/\\' ) . DIRECTORY_SEPARATOR ) ) ) {
				return true;
			}
		}
		return false;
	}

	// ── the rename record ──────────────────────────────────────────

	/** A path as a key: relative to uploads (survives a staging copy), else absolute. */
	private static function key( $path ) {
		$p = str_replace( '\\', '/', (string) $path );
		if ( preg_match( '#/wp-content/uploads/(.+)$#', $p, $m ) ) {
			return 'u:' . $m[1];
		}
		$up = str_replace( '\\', '/', self::uploads_dir() );
		if ( 0 === strpos( $p, $up . '/' ) ) {
			return 'u:' . substr( $p, strlen( $up ) + 1 );
		}
		return 'a:' . $p;
	}

	private static function unkey( $key ) {
		if ( 0 === strpos( $key, 'u:' ) ) {
			return self::uploads_dir() . '/' . substr( $key, 2 );
		}
		return substr( $key, 2 );
	}

	/** Record old -> new. Refuses when the old path already has a record (another run is on it). */
	private static function remember( $from, $to ) {
		$map = (array) get_option( self::MAP_OPT, array() );
		$k   = self::key( $from );
		if ( isset( $map[ $k ] ) && file_exists( self::unkey( $map[ $k ] ) ) ) { return false; }
		$map[ $k ] = self::key( $to );
		update_option( self::MAP_OPT, $map, false );
		return true;
	}

	private static function forget( $from ) {
		$map = (array) get_option( self::MAP_OPT, array() );
		unset( $map[ self::key( $from ) ] );
		update_option( self::MAP_OPT, $map, false );
	}

	/**
	 * Where a file with this OLD path lives now, or ''. Follows the record (one hop or more),
	 * never a guess by name, and only to a file that exists inside the allowed folders.
	 */
	public static function renamed( $path ) {
		$path = (string) $path;
		if ( '' === $path || false !== strpos( $path, '..' ) ) { return ''; }
		$map = (array) get_option( self::MAP_OPT, array() );
		$k   = self::key( $path );
		for ( $i = 0; $i < 5 && isset( $map[ $k ] ); $i++ ) {
			$k = $map[ $k ];
		}
		if ( self::key( $path ) === $k ) { return ''; }
		$to = self::unkey( $k );
		$rp = realpath( $to );
		return ( $rp && self::is_allowed_path( $rp ) ) ? $to : '';
	}

	// ── the renamer ────────────────────────────────────────────────

	public static function boot() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_run_admin' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	public static function pending() {
		return ZKV_VERSION !== get_option( self::DONE_OPT, '' );
	}

	public static function maybe_schedule() {
		if ( self::pending() && function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::CRON_HOOK );
		}
	}

	/** An admin page load runs a batch at most once every 5 minutes. */
	public static function maybe_run_admin() {
		if ( ! self::pending() || ! current_user_can( 'manage_options' ) || wp_doing_ajax() ) { return; }
		if ( get_transient( self::THROTTLE ) ) { return; }
		set_transient( self::THROTTLE, 1, 300 );
		self::run();
	}

	/**
	 * Rename one batch. Returns counts: renamed, failed, remaining, kept (filed elsewhere and
	 * not the Vault's own), gave_up, orphans, done. Never throws.
	 */
	public static function run() {
		$out = array( 'renamed' => 0, 'failed' => 0, 'remaining' => 0, 'kept' => 0, 'gave_up' => 0, 'orphans' => 0, 'done' => false, 'kept_ids' => array(), 'gave_up_ids' => array() );
		if ( ! self::lock() ) { return $out; }
		try {
			global $wpdb;
			$t     = $wpdb->prefix . 'zkv_documents';
			$rows  = (array) $wpdb->get_results( "SELECT id, file_url, attachment_id FROM {$t} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed table name; no values in the query.
			$tries = (array) get_option( self::TRIES_OPT, array() );
			$vault = self::vault_dir();
			$seen  = array();
			$skip  = array(); // files given up on: they never hold the run open
			$todo  = array();

			foreach ( $rows as $doc ) {
				$cur = self::current_path( $doc );
				$rk  = '' !== $cur ? ( realpath( $cur ) ?: $cur ) : '';
				if ( '' === $cur || isset( $seen[ $rk ] ) ) { continue; }
				$seen[ $rk ] = true;
				if ( self::is_random_name( basename( $cur ) ) ) { continue; }
				$rp = realpath( $cur );
				if ( ! $rp || ! self::is_allowed_path( $rp ) ) { continue; }
				$in_vault = self::inside( $cur, $vault );
				if ( ! $in_vault ) {
					$att = (int) ( $doc['attachment_id'] ?? 0 );
					if ( 0 === $att ) {
						// No attachment of its own: only when no media item uses the file either
						$url = rtrim( (string) ( wp_upload_dir()['baseurl'] ?? '' ), '/' ) . '/' . ltrim( substr( str_replace( '\\', '/', $rp ), strlen( str_replace( '\\', '/', (string) realpath( self::uploads_dir() ) ) ) ), '/' );
						$own = ! function_exists( 'attachment_url_to_postid' ) || 0 === (int) attachment_url_to_postid( $url );
					} else {
						$own = function_exists( 'get_post_status' ) && 'private' === get_post_status( $att );
					}
					if ( ! $own ) { $out['kept']++; $out['kept_ids'][] = (int) $doc['id']; continue; }
				}
				if ( (int) ( $tries[ (int) $doc['id'] ] ?? 0 ) >= self::MAX_ATTEMPTS ) { $out['gave_up']++; $out['gave_up_ids'][] = (int) $doc['id']; $skip[ $rk ] = true; continue; }
				$todo[] = array( $doc, $cur );
			}
			$out['remaining'] = count( $todo );

			foreach ( array_slice( $todo, 0, self::BATCH ) as $pair ) {
				list( $doc, $cur ) = $pair;
				if ( self::rename_document( $doc, $cur ) ) {
					$out['renamed']++;
					unset( $tries[ (int) $doc['id'] ] );
				} else {
					$out['failed']++;
					$tries[ (int) $doc['id'] ] = (int) ( $tries[ (int) $doc['id'] ] ?? 0 ) + 1;
				}
			}
			$out['remaining'] -= ( $out['renamed'] + $out['failed'] );

			if ( 0 === $out['remaining'] && 0 === $out['failed'] ) {
				$out['orphans'] = self::rename_orphans( $seen, $tries, $skip, $out );
				if ( ! self::vault_has_old_names( $skip ) ) {
					update_option( self::DONE_OPT, ZKV_VERSION, false );
					$out['done'] = true;
				}
			}
			update_option( self::TRIES_OPT, $tries, false );
			if ( $out['renamed'] || $out['failed'] || $out['orphans'] || $out['done'] ) {
				error_log( sprintf( 'ZKV file names: renamed %d, failed %d, left %d, filed elsewhere %d, gave up %d, unlinked files renamed %d%s',
					$out['renamed'], $out['failed'], max( 0, $out['remaining'] ), $out['kept'], $out['gave_up'], $out['orphans'], $out['done'] ? ', done' : '' ) );
			}
			if ( ! $out['done'] && function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_single_event( time() + ( $out['renamed'] ? 60 : 900 ), self::CRON_HOOK );
			}
		} catch ( \Throwable $e ) {
			error_log( 'ZKV file names: stopped (' . get_class( $e ) . ')' );
		} finally {
			delete_option( self::LOCK );
		}
		update_option( self::STATUS_OPT, $out + array( 'at' => time() ), false );
		return $out;
	}

	/** add_option() fails when the row exists, so only one run gets in; a lock older than 2 minutes is stale. */
	private static function lock() {
		if ( add_option( self::LOCK, time(), '', false ) ) { return true; }
		$t = (int) get_option( self::LOCK, 0 );
		if ( $t && time() - $t > 120 ) {
			delete_option( self::LOCK );
			return (bool) add_option( self::LOCK, time(), '', false );
		}
		return false;
	}

	/** Shown to administrators when a document kept a guessable name (the log has only counts). */
	public static function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$st = (array) get_option( self::STATUS_OPT, array() );
		$n  = (int) ( $st['kept'] ?? 0 ) + (int) ( $st['gave_up'] ?? 0 );
		if ( $n < 1 ) { return; }
		$ids = array_merge( (array) ( $st['kept_ids'] ?? array() ), (array) ( $st['gave_up_ids'] ?? array() ) );
		echo '<div class="notice notice-warning"><p><strong>Knowledge Vault:</strong> ' . (int) $n
			. ' file(s) still have their original, guessable names: filed outside the Vault folder and also used by the site, or could not be renamed'
			. ( $ids ? ' (document IDs ' . esc_html( implode( ', ', array_map( 'intval', $ids ) ) ) . ')' : '' )
			. '. Upload those documents to the Vault again, or delete them.</p></div>';
	}

	private static function inside( $path, $root ) {
		$p = str_replace( '\\', '/', (string) $path );
		$r = rtrim( str_replace( '\\', '/', (string) $root ), '/' );
		return '' !== $r && 0 === strpos( $p, $r . '/' );
	}

	/** Where a document's file is right now, or ''. */
	private static function current_path( array $doc ) {
		$stored = (string) ( $doc['file_url'] ?? '' );
		if ( '' !== $stored && 0 !== strpos( $stored, 'http' ) && file_exists( $stored ) ) {
			return $stored;
		}
		if ( ! empty( $doc['attachment_id'] ) && function_exists( 'get_attached_file' ) ) {
			$a = get_attached_file( (int) $doc['attachment_id'] );
			if ( $a && file_exists( $a ) ) { return $a; }
		}
		if ( class_exists( 'ZKV_Viewer' ) ) {
			$p = (string) ZKV_Viewer::locate( $doc );
			if ( '' !== $p ) { return $p; }
		}
		return '';
	}

	/** Rename one document's file in place (and its attachment's files), then update every row naming it. */
	private static function rename_document( array $doc, $cur ) {
		global $wpdb;
		$dir  = dirname( $cur );
		$new  = self::random_name( basename( $cur ) );
		$dest = $dir . '/' . $new;
		if ( file_exists( $dest ) ) { return false; } // 2^-128; never overwrite
		if ( ! self::remember( $cur, $dest ) ) { return false; } // recorded BEFORE the rename
		if ( ! @rename( $cur, $dest ) ) {
			self::forget( $cur );
			return false;
		}

		$att = (int) ( $doc['attachment_id'] ?? 0 );
		if ( $att && function_exists( 'get_attached_file' ) ) {
			$att_file = get_attached_file( $att );
			$old_base = pathinfo( $cur, PATHINFO_FILENAME );
			$new_base = pathinfo( $dest, PATHINFO_FILENAME );
			$is_main  = $att_file && self::same( $att_file, $cur );
			// A big photo: the row holds the original, the attachment the "-scaled" copy beside it
			$is_sib   = $att_file && ! $is_main && self::same( dirname( $att_file ), $dir )
				&& 0 === strpos( basename( $att_file ), $old_base . '-' );
			if ( $is_main || $is_sib ) {
				$meta = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $att ) : false;
				$att_dest = $dest;
				if ( $is_sib ) {
					$n = self::rename_sibling( $dir, basename( $att_file ), $old_base, $new_base );
					$att_dest = '' !== $n ? $dir . '/' . $n : $att_file;
				}
				if ( is_array( $meta ) ) {
					if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
						foreach ( $meta['sizes'] as $k => $size ) {
							$n = self::rename_sibling( $dir, (string) ( $size['file'] ?? '' ), $old_base, $new_base );
							if ( '' !== $n ) { $meta['sizes'][ $k ]['file'] = $n; }
						}
					}
					if ( ! empty( $meta['original_image'] ) ) {
						if ( basename( (string) $meta['original_image'] ) === basename( $cur ) ) {
							$meta['original_image'] = basename( $dest ); // the original IS the row's file, already renamed
						} else {
							$n = self::rename_sibling( $dir, (string) $meta['original_image'], $old_base, $new_base, true );
							if ( '' !== $n ) { $meta['original_image'] = $n; }
						}
					}
				}
				update_attached_file( $att, $att_dest );
				if ( is_array( $meta ) ) {
					$meta['file'] = function_exists( '_wp_relative_upload_path' ) ? _wp_relative_upload_path( $att_dest ) : $att_dest;
					wp_update_attachment_metadata( $att, $meta );
				}
			}
		}

		$t   = $wpdb->prefix . 'zkv_documents';
		$old = (string) ( $doc['file_url'] ?? '' );
		$ok  = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET file_url = %s WHERE id = %d OR file_url = %s OR file_url = %s", $dest, (int) $doc['id'], $cur, '' !== $old ? $old : $cur ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed table name; values are placeholders.
		if ( false === $ok ) {
			error_log( 'ZKV file names: a row was not updated (the rename record still finds the file)' );
		}
		return true;
	}

	/** Rename a generated size (or the original image) that sits beside the main file. */
	private static function rename_sibling( $dir, $file, $old_base, $new_base, $is_original = false ) {
		$file = basename( $file );
		if ( '' === $file ) { return ''; }
		$from = $dir . '/' . $file;
		if ( ! file_exists( $from ) ) { return ''; }
		$fb   = pathinfo( $file, PATHINFO_FILENAME );
		$ext  = pathinfo( $file, PATHINFO_EXTENSION );
		if ( 0 === strpos( $fb, $old_base . '-' ) ) {
			$suffix = substr( $fb, strlen( $old_base ) ); // e.g. "-150x150"
		} elseif ( $is_original || 0 === strpos( $old_base, $fb ) ) {
			$suffix = '-original';
		} else {
			$suffix = '-' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
		}
		$suffix = strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $suffix ) );
		$dot    = '' !== $ext ? '.' . strtolower( $ext ) : '';
		$to     = $new_base . $suffix . $dot;
		if ( file_exists( $dir . '/' . $to ) ) { $to = $new_base . $suffix . '-' . bin2hex( random_bytes( 3 ) ) . $dot; }
		if ( file_exists( $dir . '/' . $to ) || ! self::remember( $from, $dir . '/' . $to ) ) { return ''; }
		if ( @rename( $from, $dir . '/' . $to ) ) { return $to; }
		self::forget( $from );
		return '';
	}

	private static function same( $a, $b ) {
		return str_replace( '\\', '/', (string) $a ) === str_replace( '\\', '/', (string) $b );
	}

	/** Old-named files in the Vault folder no document claims, older than an hour: renamed in place. */
	private static function rename_orphans( array $claimed, array &$tries, array &$skip, array &$out ) {
		$n   = 0;
		$dir = self::vault_dir();
		if ( ! is_dir( $dir ) ) { return 0; }
		foreach ( self::files( $dir ) as $f ) {
			$rk = realpath( $f ) ?: $f;
			if ( isset( $claimed[ $rk ] ) || self::is_guard_file( $f ) || self::is_random_name( basename( $f ) ) ) { continue; }
			if ( time() - (int) @filemtime( $f ) < self::ORPHAN_AGE ) { continue; }
			$tk = 'f:' . md5( self::key( $f ) );
			if ( (int) ( $tries[ $tk ] ?? 0 ) >= self::MAX_ATTEMPTS ) { $skip[ $rk ] = true; $out['gave_up']++; continue; }
			$to = dirname( $f ) . '/' . self::random_name( basename( $f ) );
			if ( file_exists( $to ) || ! self::remember( $f, $to ) ) { continue; }
			if ( @rename( $f, $to ) ) {
				$n++;
				unset( $tries[ $tk ] );
			} else {
				self::forget( $f );
				$tries[ $tk ] = (int) ( $tries[ $tk ] ?? 0 ) + 1;
			}
		}
		return $n;
	}

	private static function vault_has_old_names( array $skip = array() ) {
		$dir = self::vault_dir();
		if ( ! is_dir( $dir ) ) { return false; }
		foreach ( self::files( $dir ) as $f ) {
			if ( isset( $skip[ realpath( $f ) ?: $f ] ) ) { continue; }
			if ( ! self::is_guard_file( $f ) && ! self::is_random_name( basename( $f ) ) ) { return true; }
		}
		return false;
	}

	/** Every file under a folder; an unreadable subfolder is skipped, not fatal. */
	private static function files( $dir ) {
		$out = array();
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			foreach ( $it as $f ) {
				if ( $f->isFile() ) { $out[] = $f->getPathname(); }
			}
		} catch ( \Throwable $e ) {
			// An unreadable folder: return what was listed
		}
		return $out;
	}

	private static function is_guard_file( $path ) {
		return in_array( basename( (string) $path ), array( '.htaccess', 'index.php', 'index.html' ), true );
	}

	// ── deleting ───────────────────────────────────────────────────

	/**
	 * After a document row is deleted: remove its file when it sits in the Vault folder and
	 * no other document names it. (Otherwise a document with no media attachment would keep
	 * its file on disk after "delete".)
	 */
	public static function delete_file( $file_url ) {
		global $wpdb;
		$path = (string) $file_url;
		if ( '' === $path || 0 === strpos( $path, 'http' ) ) { return false; }
		if ( ! file_exists( $path ) ) {
			$path = self::renamed( $path );
			if ( '' === $path ) { return false; }
		}
		$rp = realpath( $path );
		if ( ! $rp || ! self::inside( $rp, (string) realpath( self::vault_dir() ) ) ) { return false; }
		$t     = $wpdb->prefix . 'zkv_documents';
		$still = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE file_url = %s", $path ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed table name; values are placeholders.
		if ( $still > 0 ) { return false; }
		return @unlink( $rp );
	}
}
