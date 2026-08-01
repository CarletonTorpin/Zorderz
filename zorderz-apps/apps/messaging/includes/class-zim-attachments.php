<?php
/**
 * ZIM_Attachments
 *
 * File attachment handling. Images + PDFs only. 5 MB cap.
 *
 * SECURITY CONTRACT (Trap 9 / acceptance #8):
 *   - ALL uploads route through wp_handle_upload() so we inherit WordPress's
 *     MIME sniffing, filename sanitization, and directory-traversal
 *     protection. We NEVER call move_uploaded_file() directly.
 *   - We pass an explicit 'mimes' whitelist (ZIM_ALLOWED_MIMES). PHP MIME
 *     sniffing is done by WordPress via wp_check_filetype_and_ext() — catches
 *     renamed .exe → .png.
 *   - Size check BEFORE wp_handle_upload so we fail fast on oversize.
 *
 * HEIC HANDLING (acceptance #9):
 *   iPhones upload HEIC. Browsers generally can't render it. We try:
 *     1. Call ZRCPT_HEIC::to_jpeg() if present (the Receipts app
 *        provides this — avoids duplicating the ImageMagick/ffmpeg plumbing).
 *     2. If absent, try Imagick HEIC → JPEG (many hosts have heic delegate).
 *     3. If neither works, reject with a clear error — better than storing an
 *        unviewable file.
 *
 * 30-DAY PURGE (Trap 4 / acceptance #7):
 *   When a message is soft-deleted, its attachments stay on disk for 30 days
 *   (audit / admin undelete). Daily cron purges files whose parent message's
 *   deleted_at is older than 30 days, stamping purged_at on the row.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIM_Attachments {

	/**
	 * Handle an upload from $_FILES['attachment']. Returns attachment row
	 * data (including our DB id) or WP_Error.
	 *
	 * Note: the row is orphaned (message_id=0) until bind_to_message() runs
	 * after the user hits Send. Stale orphans are cleaned daily.
	 */
	public static function handle_upload( $file_field_name, $uploader_user_id ) {
		if ( empty( $_FILES[ $file_field_name ] ) ) {
			return new WP_Error( 'zim_no_file', 'No file uploaded.' );
		}
		$file = $_FILES[ $file_field_name ];

		// Fail fast on size.
		if ( ! empty( $file['size'] ) && (int) $file['size'] > ZIM_MAX_UPLOAD_BYTES ) {
			return new WP_Error( 'zim_too_large', 'File exceeds 5 MB limit.' );
		}

		// Require the WP upload machinery.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// HEIC preprocessing — convert BEFORE wp_handle_upload because its
		// MIME allowlist won't like most hosts' image/heic detection.
		$needs_heic_convert = false;
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		// Reject animated GIFs. We allow static GIFs but not animated ones.
		// Detection: animated GIFs contain multiple image frames separated
		// by the 0x00 0x21 0xF9 (Graphic Control Extension) marker. Counting
		// those markers is the most reliable filesystem-level test.
		if ( $ext === 'gif' && ! empty( $file['tmp_name'] ) && file_exists( $file['tmp_name'] ) ) {
			$gif_data = @file_get_contents( $file['tmp_name'] );
			if ( $gif_data !== false ) {
				$frame_count = preg_match_all( '/\x00\x21\xF9/', $gif_data );
				if ( $frame_count > 1 ) {
					return new WP_Error( 'zim_animated_gif',
						'Animated GIFs are not supported. Please upload a static image.' );
				}
			}
		}

		if ( in_array( $ext, array( 'heic', 'heif' ), true ) ) {
			$converted = self::convert_heic_to_jpeg( $file['tmp_name'] );
			if ( is_wp_error( $converted ) ) {
				return $converted;
			}
			$file['tmp_name'] = $converted['path'];
			$file['name']     = preg_replace( '/\.hei[cf]$/i', '.jpg', $file['name'] );
			$file['type']     = 'image/jpeg';
			$file['size']     = filesize( $converted['path'] );
			$needs_heic_convert = true;
		}

		// Route through wp_handle_upload — Trap 9.
		// v1.0.27 (security): store NEW uploads under an unguessable random basename so
		// the underlying file can't be enumerated even as a backstop -- the browser only
		// ever receives the membership-gated proxy URL below, never this path. The user's
		// real filename is preserved (original_name) for display. Old files are left in
		// place per the deploy decision; they are simply served through the same gate.
		$zim_display_name = (string) $file['name'];
		$zim_ext          = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		$file['name']      = bin2hex( random_bytes( 16 ) ) . ( '' !== $zim_ext ? '.' . $zim_ext : '' );

		$overrides = array(
			'test_form' => false,
			'mimes'     => zim_allowed_mimes(),
		);
		$result = wp_handle_upload( $file, $overrides );
		if ( ! empty( $result['error'] ) ) {
			return new WP_Error( 'zim_upload_error', $result['error'] );
		}

		// Create an attachment post for WordPress file management. We use
		// post_status = 'private' so these don't appear in the shared Media
		// Library grid view. WP still tracks them for backup/delete/metadata.
		// We also stamp a custom meta key so we can filter them out of media
		// queries site-wide without affecting other private attachments.
		$attachment_id = wp_insert_attachment( array(
			'guid'           => $result['url'],
			'post_mime_type' => $result['type'],
			'post_title'     => sanitize_file_name( pathinfo( $result['file'], PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'private',
			'post_author'    => (int) $uploader_user_id,
		), $result['file'] );
		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			@unlink( $result['file'] );
			return new WP_Error( 'zim_attachment_post_failed', 'Failed to register attachment.' );
		}
		$metadata = wp_generate_attachment_metadata( $attachment_id, $result['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );
		// Tag as TSIM-owned so the media-library filter can exclude them.
		update_post_meta( $attachment_id, '_tsim_chat_attachment', '1' );

		// Record in our own table for cheap message-join queries and for
		// tracking soft-delete / purge lifecycle.
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'zim_attachments',
			array(
				'message_id'         => 0, // orphan until bound
				'attachment_post_id' => (int) $attachment_id,
				'file_url'           => esc_url_raw( $result['url'] ),
				'mime'               => sanitize_text_field( $result['type'] ),
				'size_bytes'         => (int) filesize( $result['file'] ),
				'original_name'      => sanitize_file_name( $zim_display_name ),
				'created_at'         => current_time( 'mysql', true ),
			),
			array( '%d','%d','%s','%s','%d','%s','%s' )
		);

		// v1.0.27: opaque 128-bit token (postmeta) -- a DIFFERENT scheme from the receipt
		// word-token (distinct charset/entropy/secret) so a break in one domain never
		// transfers to the other. The client is handed only the gated proxy URL.
		$zim_att_id = (int) $wpdb->insert_id;
		$zim_token  = self::get_or_make_token( (int) $attachment_id );

		return array(
			'id'             => $zim_att_id,
			'attachment_id'  => (int) $attachment_id,
			'url'            => self::proxy_url( $zim_att_id, $zim_token ),
			'mime'           => (string) $result['type'],
			'size'           => (int) filesize( $result['file'] ),
			'name'           => sanitize_file_name( $zim_display_name ),
			'heic_converted' => $needs_heic_convert,
		);
	}

	/**
	 * HEIC → JPEG conversion. Prefers ecosystem helper, falls back to Imagick.
	 * Returns array( 'path' => string ) on success or WP_Error.
	 */
	private static function convert_heic_to_jpeg( $src_path ) {
		// Prefer the Receipts app's helper if it's around.
		if ( class_exists( 'ZRCPT_HEIC' ) && method_exists( 'ZRCPT_HEIC', 'to_jpeg' ) ) {
			try {
				$dst = call_user_func( array( 'ZRCPT_HEIC', 'to_jpeg' ), $src_path );
				if ( is_string( $dst ) && $dst && file_exists( $dst ) ) {
					return array( 'path' => $dst );
				}
			} catch ( \Throwable $e ) {
				// Fall through to Imagick.
			}
		}

		// Imagick fallback.
		if ( class_exists( 'Imagick' ) ) {
			try {
				$img = new Imagick( $src_path );
				$img->setImageFormat( 'jpeg' );
				$img->setImageCompressionQuality( 85 );
				$dst = wp_tempnam( 'zim-heic-' . basename( $src_path ) . '.jpg' );
				$img->writeImage( $dst );
				$img->clear();
				if ( file_exists( $dst ) && filesize( $dst ) > 0 ) {
					return array( 'path' => $dst );
				}
			} catch ( \Throwable $e ) {
				// Fall through to error.
			}
		}

		return new WP_Error( 'zim_heic_unsupported',
			'HEIC conversion failed. Please upload a JPEG or PNG.' );
	}

	/**
	 * Bind orphan attachments to a freshly-created message. Defensive: only
	 * the uploader's own recent orphans are bound.
	 */
	public static function bind_to_message( array $attachment_ids, $message_id, $uploader_user_id ) {
		global $wpdb;
		$message_id       = (int) $message_id;
		$uploader_user_id = (int) $uploader_user_id;
		$attachment_ids   = array_map( 'intval', $attachment_ids );
		if ( empty( $attachment_ids ) || $message_id <= 0 ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );
		$args = array_merge( array( $message_id ), $attachment_ids, array( $uploader_user_id ) );

		// Only update orphans owned by the uploader — prevents rebinding someone
		// else's attachment to your own message.
		// phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}zim_attachments a
			   JOIN {$wpdb->posts} p ON p.ID = a.attachment_post_id
			    SET a.message_id = %d
			  WHERE a.id IN ({$placeholders})
			    AND a.message_id = 0
			    AND p.post_author = %d",
			...$args
		) );
	}

	/**
	 * Load attachments for a batch of messages.
	 * Hides purged rows.
	 */
	public static function for_messages( array $message_ids ) {
		global $wpdb;
		$message_ids = array_values( array_unique( array_map( 'intval', $message_ids ) ) );
		if ( empty( $message_ids ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $message_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, message_id, attachment_post_id, file_url, mime, size_bytes, original_name
			   FROM {$wpdb->prefix}zim_attachments
			  WHERE message_id IN ({$placeholders})
			    AND purged_at IS NULL
			  ORDER BY id ASC",
			...$message_ids
		), ARRAY_A );

		$out = array();
		foreach ( $rows as $r ) {
			$mid = (int) $r['message_id'];
			if ( ! isset( $out[ $mid ] ) ) {
				$out[ $mid ] = array();
			}
			$out[ $mid ][] = array(
				'id'    => (int) $r['id'],
				'url'   => self::proxy_url( (int) $r['id'], self::get_or_make_token( (int) $r['attachment_post_id'] ) ),
				'mime'  => (string) $r['mime'],
				'size'  => (int) $r['size_bytes'],
				'name'  => (string) $r['original_name'],
				'kind'  => self::kind_from_mime( (string) $r['mime'] ),
			);
		}
		return $out;
	}

	private static function kind_from_mime( $mime ) {
		if ( 0 === strpos( $mime, 'image/' ) ) {
			return 'image';
		}
		if ( 'application/pdf' === $mime ) {
			return 'pdf';
		}
		return 'file';
	}

	/**
	 * Daily cron: purge files whose parent message has been deleted > 30 days.
	 * Also garbage-collects orphans older than 1 day (failed sends).
	 */
	public static function cron_purge() {
		global $wpdb;

		// (a) Orphan cleanup — attachments that were never bound to a message.
		$orphan_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, attachment_post_id FROM {$wpdb->prefix}zim_attachments
			  WHERE message_id = 0
			    AND purged_at IS NULL
			    AND created_at < %s
			  LIMIT 500",
			gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
		) );
		foreach ( $orphan_rows as $row ) {
			wp_delete_attachment( (int) $row->attachment_post_id, true );
			$wpdb->update(
				$wpdb->prefix . 'zim_attachments',
				array( 'purged_at' => current_time( 'mysql', true ), 'file_url' => '' ),
				array( 'id' => (int) $row->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		// (b) Deleted-message cleanup — attachments on messages deleted > 30 days.
		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( ZIM_ATTACHMENT_RETENTION_DAYS * DAY_IN_SECONDS ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.id, a.attachment_post_id
			   FROM {$wpdb->prefix}zim_attachments a
			   JOIN {$wpdb->prefix}zim_messages m ON m.id = a.message_id
			  WHERE a.purged_at IS NULL
			    AND m.deleted_at IS NOT NULL
			    AND m.deleted_at < %s
			  LIMIT 500",
			$threshold
		) );
		foreach ( $rows as $row ) {
			wp_delete_attachment( (int) $row->attachment_post_id, true );
			$wpdb->update(
				$wpdb->prefix . 'zim_attachments',
				array( 'purged_at' => current_time( 'mysql', true ), 'file_url' => '' ),
				array( 'id' => (int) $row->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * v1.0.27 -- Membership-gated proxy URL for an attachment. The browser only
	 * ever receives THIS url (never the file's real, world-readable uploads path).
	 */
	private static function proxy_url( $att_row_id, $token ) {
		return add_query_arg(
			array( 'zim_att' => (int) $att_row_id, 't' => rawurlencode( (string) $token ) ),
			home_url( '/' )
		);
	}

	/**
	 * v1.0.27 -- Get (or lazily mint) the opaque per-attachment access token, stored
	 * in postmeta on the attachment post. 128 bits from random_bytes -- deliberately a
	 * different scheme from the receipt word-token so a break in one domain never
	 * transfers to the other. Old attachments get one on first render, so the gate
	 * covers them too without a bulk file migration.
	 */
	private static function get_or_make_token( $attachment_post_id ) {
		$attachment_post_id = (int) $attachment_post_id;
		if ( $attachment_post_id <= 0 ) {
			return '';
		}
		$tok = get_post_meta( $attachment_post_id, '_tsim_att_token', true );
		if ( ! is_string( $tok ) || strlen( $tok ) < 24 ) {
			$tok = bin2hex( random_bytes( 16 ) );
			update_post_meta( $attachment_post_id, '_tsim_att_token', $tok );
		}
		return $tok;
	}

	/**
	 * v1.0.27 -- template_redirect entry. Streams an attachment ONLY to a logged-in
	 * member of its conversation, after a constant-time token check. Any failure is a
	 * bare 404 (never confirm existence). Closes the pre-1.0.27 IDOR where files sat at
	 * public, enumerable uploads URLs with no membership check.
	 */
	public static function maybe_serve() {
		if ( ! isset( $_GET['zim_att'] ) ) {
			return;
		}
		self::serve();
	}

	private static function serve() {
		$fail = static function () {
			status_header( 404 );
			header( 'X-Robots-Tag: noindex, nofollow', true );
			exit;
		};

		if ( ! is_user_logged_in() || ! function_exists( 'zim_user_has_access' ) || ! zim_user_has_access() ) {
			$fail();
		}
		$att_id = isset( $_GET['zim_att'] ) ? absint( $_GET['zim_att'] ) : 0;
		$token  = isset( $_GET['t'] ) ? (string) wp_unslash( $_GET['t'] ) : '';
		if ( $att_id <= 0 || '' === $token ) {
			$fail();
		}

		global $wpdb;
		$a = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, message_id, attachment_post_id, mime, original_name, purged_at
			   FROM {$wpdb->prefix}zim_attachments WHERE id = %d",
			$att_id
		) );
		if ( ! $a || ! empty( $a->purged_at ) ) {
			$fail();
		}

		// Constant-time token check.
		$expected = get_post_meta( (int) $a->attachment_post_id, '_tsim_att_token', true );
		if ( ! is_string( $expected ) || '' === $expected || ! hash_equals( $expected, $token ) ) {
			$fail();
		}

		// Authorization: a bound message -> caller must be a conversation member; an
		// orphan (uploaded, not yet sent) -> only the uploader may preview it.
		$uid = get_current_user_id();
		if ( (int) $a->message_id > 0 ) {
			$conv = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT conversation_id FROM {$wpdb->prefix}zim_messages WHERE id = %d",
				(int) $a->message_id
			) );
			if ( $conv <= 0 || ! ZIM_Membership::is_member( $uid, $conv ) ) {
				$fail();
			}
		} else {
			$post = get_post( (int) $a->attachment_post_id );
			if ( ! $post || (int) $post->post_author !== $uid ) {
				$fail();
			}
		}

		$path = get_attached_file( (int) $a->attachment_post_id );
		if ( ! $path || ! is_readable( $path ) ) {
			$fail();
		}

		$mime = (string) $a->mime;
		if ( '' === $mime ) {
			$mime = 'application/octet-stream';
		}
		$safe_name = sanitize_file_name( (string) $a->original_name );
		if ( '' === $safe_name ) {
			$safe_name = 'attachment-' . (int) $a->id;
		}
		$disposition = ( 0 === strpos( $mime, 'image/' ) || 'application/pdf' === $mime ) ? 'inline' : 'attachment';

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . $safe_name . '"' );
		header( 'X-Content-Type-Options: nosniff', true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: no-referrer', true );
		header( 'Cache-Control: private, max-age=600, must-revalidate', true );
		readfile( $path );
		exit;
	}
}
