<?php
/**
 * User Media — Shared Media Management Layer
 *
 * Provides a centralized database table and API for user-uploaded
 * media across all Zorderz plugins (Sketch Pad, Estimate Creator
 * photos, future camera/document apps). Each media record ties a
 * WordPress attachment to a user, source app, and privacy level.
 *
 * Plugins call the static methods directly:
 *   ZDZ_User_Media::save( [ 'user_id' => ..., 'file_url' => ..., ... ] );
 *   ZDZ_User_Media::get_user_media( $user_id, [ 'media_type' => 'sketch' ] );
 *   ZDZ_User_Media::delete( $media_id, $user_id );
 *
 * Table: {prefix}zdz_user_media
 *
 * Privacy levels:
 *   'private'  — only the uploading user can see it (default)
 *   'team'     — all logged-in Zorderz users can see it
 *   'public'   — visible to anyone (admin approval may be required)
 *
 * @since   2.17.1
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_User_Media {

	/** @var string Table name (without prefix). */
	const TABLE = 'zdz_user_media';

	/**
	 * Save a new media record.
	 *
	 * @param array $args {
	 *     @type int    $user_id          WordPress user ID (required).
	 *     @type string $file_url         Full-size file URL (required).
	 *     @type string $thumbnail_url    Thumbnail URL (optional, defaults to file_url).
	 *     @type string $filename         Original filename (optional).
	 *     @type string $media_type       Type: 'sketch', 'photo', 'document' (default 'photo').
	 *     @type string $source_app       Plugin ID that created it (e.g. 'zdz-sketch-pad').
	 *     @type string $source_ref       App-specific reference (e.g. 'sketch:123').
	 *     @type string $title            Display title (default 'Untitled').
	 *     @type string $description      Optional description.
	 *     @type string $privacy          'private', 'team', or 'public' (default 'private').
	 *     @type int    $wp_attachment_id  WordPress attachment post ID (optional).
	 *     @type mixed  $canvas_data      App-specific data (stored as JSON in meta_json).
	 *     @type array  $meta             Additional metadata (stored as JSON in meta_json).
	 * }
	 * @return array|null The saved record as associative array, or null on failure.
	 */
	public static function save( array $args ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		if ( empty( $args['user_id'] ) || empty( $args['file_url'] ) ) {
			return null;
		}

		// Auto-create table if it doesn't exist (first-use bootstrap)
		self::ensure_table();

		// Build meta_json from canvas_data and any extra meta
		$meta = $args['meta'] ?? [];
		if ( ! empty( $args['canvas_data'] ) ) {
			$meta['canvas_data'] = $args['canvas_data'];
		}

		// ── EXIF provenance (v2.20.4) ──────────────────────────────────────
		// Capture time + GPS are recorded ONCE here at ingest and are never
		// editable afterward (see update() whitelist). Resolution order:
		//   1. Explicit args — captured_at / gps_lat / gps_lng. This is the
		//      client-supplied fallback for HEIC photos, whose EXIF PHP often
		//      cannot read; the capturing app can send time/location alongside
		//      the file.
		//   2. Otherwise, read EXIF from the ORIGINAL attachment file (never a
		//      thumbnail/re-encode — those strip EXIF). We resolve the path
		//      from wp_attachment_id via get_attached_file(), which returns the
		//      full-size original.
		// The complete raw EXIF block is always preserved in meta_json ("logs
		// are everything") even when only the normalized columns are queried.
		$captured_at = isset( $args['captured_at'] ) && $args['captured_at'] !== '' ? $args['captured_at'] : null;
		$gps_lat     = isset( $args['gps_lat'] ) && $args['gps_lat'] !== '' ? $args['gps_lat'] : null;
		$gps_lng     = isset( $args['gps_lng'] ) && $args['gps_lng'] !== '' ? $args['gps_lng'] : null;

		$exif_path = $args['exif_source_path'] ?? '';
		if ( '' === $exif_path && ! empty( $args['wp_attachment_id'] ) && function_exists( 'get_attached_file' ) ) {
			$exif_path = (string) get_attached_file( absint( $args['wp_attachment_id'] ) );
		}
		if ( $exif_path ) {
			$exif = self::extract_exif( $exif_path );
			if ( ! empty( $exif['raw'] ) ) {
				$meta['exif'] = $exif['raw']; // keep EVERYTHING for the log
			}
			// Only fill from EXIF what the caller didn't already provide.
			if ( null === $captured_at && ! empty( $exif['captured_at'] ) ) {
				$captured_at = $exif['captured_at'];
			}
			if ( null === $gps_lat && isset( $exif['gps_lat'] ) ) {
				$gps_lat = $exif['gps_lat'];
			}
			if ( null === $gps_lng && isset( $exif['gps_lng'] ) ) {
				$gps_lng = $exif['gps_lng'];
			}
		}

		// Normalize for storage: captured_at as Y-m-d H:i:s, GPS as float|null.
		$captured_at = $captured_at ? substr( (string) $captured_at, 0, 19 ) : null;
		$gps_lat     = is_numeric( $gps_lat ) ? round( (float) $gps_lat, 7 ) : null;
		$gps_lng     = is_numeric( $gps_lng ) ? round( (float) $gps_lng, 7 ) : null;

		$now = current_time( 'mysql' );
		$att_id = absint( $args['wp_attachment_id'] ?? 0 );

		$data = [
			'user_id'          => absint( $args['user_id'] ),
			'file_url'         => esc_url_raw( $args['file_url'] ),
			'thumbnail_url'    => esc_url_raw( $args['thumbnail_url'] ?? $args['file_url'] ),
			'filename'         => sanitize_file_name( $args['filename'] ?? '' ),
			'media_type'       => sanitize_key( $args['media_type'] ?? 'photo' ),
			'source_app'       => sanitize_key( $args['source_app'] ?? '' ),
			'source_ref'       => sanitize_text_field( $args['source_ref'] ?? '' ),
			'title'            => sanitize_text_field( $args['title'] ?? 'Untitled' ),
			'description'      => sanitize_textarea_field( $args['description'] ?? '' ),
			'privacy'          => in_array( $args['privacy'] ?? '', [ 'private', 'team', 'public' ], true )
			                      ? $args['privacy'] : 'private',
			'wp_attachment_id' => $att_id > 0 ? $att_id : 0,
			'meta_json'        => ! empty( $meta ) ? wp_json_encode( $meta ) : '',
			'created_at'       => $now,
			'updated_at'       => $now,
			'captured_at'      => $captured_at,
			'gps_lat'          => $gps_lat,
			'gps_lng'          => $gps_lng,
		];

		// Formats track $data key order. captured_at is %s (datetime string or
		// NULL); gps_lat/gps_lng are %f when set. wpdb writes NULL for null
		// values regardless of the format specifier.
		$formats = [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%f', '%f' ];

		$result = $wpdb->insert( $table, $data, $formats );
		if ( ! $result ) {
			error_log( 'ZDZ_User_Media::save() FAILED.' );
			error_log( '  DB error: ' . $wpdb->last_error );
			error_log( '  Table: ' . $table );
			error_log( '  Data keys: ' . implode( ', ', array_keys( $data ) ) );
			error_log( '  Data vals: user_id=' . $data['user_id'] . ' media_type=' . $data['media_type'] . ' file_url=' . substr( $data['file_url'], 0, 80 ) );
			error_log( '  Last query: ' . $wpdb->last_query );
			return null;
		}

		$data['id'] = (int) $wpdb->insert_id;
		return $data;
	}

	/**
	 * Ensure the user_media table exists. Called on first save
	 * in case the migration hasn't run via admin_init yet
	 * (e.g. user saves a sketch before visiting wp-admin).
	 */
	private static function ensure_table(): void {
		// Fast path: schema already verified this process
		static $verified = false;
		if ( $verified ) return;
		if ( get_option( 'zdz_media_schema', 0 ) >= 5 ) { $verified = true; return; }

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
		if ( $exists ) {
			$cols = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
			if ( ! in_array( 'meta_json', $cols, true ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `meta_json` longtext AFTER `wp_attachment_id`" );
			}
			if ( ! in_array( 'description', $cols, true ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `description` text AFTER `title`" );
			}
			// ── v2.20.4 (schema 3): EXIF provenance columns ──
			// captured_at + GPS are extracted once at ingest and never altered
			// afterward (see update() whitelist). Indexed so the analytics layer
			// can match photos to an invoice by date window + bounding-box on
			// lat/lng. Added idempotently — each guarded by a column check.
			if ( ! in_array( 'captured_at', $cols, true ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `captured_at` datetime NULL AFTER `updated_at`" );
			}
			if ( ! in_array( 'gps_lat', $cols, true ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `gps_lat` decimal(10,7) NULL AFTER `captured_at`" );
			}
			if ( ! in_array( 'gps_lng', $cols, true ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `gps_lng` decimal(10,7) NULL AFTER `gps_lat`" );
			}
			// ── v2.33.0 (schema 4): per-record share_token for the gated media proxy ──
			// Private media is served ONLY via /?zdz_media=<id>&t=<token> (see serve()),
			// never at its raw wp-uploads URL. Opaque 128-bit token, distinct from the
			// receipt word-token and the chat-attachment token (per-domain separation).
			if ( ! in_array( 'share_token', $cols, true ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `share_token` varchar(64) NOT NULL DEFAULT '' AFTER `wp_attachment_id`" );
			}
			// Indexes — guard against duplicates (re-adding a KEY errors).
			$idx = $wpdb->get_col( "SHOW INDEX FROM `{$table}`", 2 ); // col 2 = Key_name
			if ( ! in_array( 'idx_captured', (array) $idx, true ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD KEY `idx_captured` (`captured_at`)" );
			}
			if ( ! in_array( 'idx_geo', (array) $idx, true ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD KEY `idx_geo` (`gps_lat`, `gps_lng`)" );
			}
			// ── v2.36.0 (schema 5): index source_ref for O(1) dedupe ──
			// zdz-camera dedupes a retried upload by source_ref ('photo:uid:<uid>');
			// without this key get_by_source_ref would full-scan. Composite
			// (user_id, source_ref) since source_ref embeds a client-supplied uid.
			if ( ! in_array( 'idx_source_ref', (array) $idx, true ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD KEY `idx_source_ref` (`user_id`, `source_ref`)" );
			}
			update_option( 'zdz_media_schema', 5, true );
			$verified = true;
			return;
		}
		// Table doesn't exist — create it
		$charset = $wpdb->get_charset_collate();
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS `{$table}` (
				`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				`user_id` bigint(20) unsigned NOT NULL,
				`file_url` varchar(512) NOT NULL DEFAULT '',
				`thumbnail_url` varchar(512) NOT NULL DEFAULT '',
				`filename` varchar(255) NOT NULL DEFAULT '',
				`media_type` varchar(32) NOT NULL DEFAULT 'photo',
				`source_app` varchar(64) NOT NULL DEFAULT '',
				`source_ref` varchar(128) NOT NULL DEFAULT '',
				`title` varchar(255) NOT NULL DEFAULT 'Untitled',
				`description` text,
				`privacy` varchar(16) NOT NULL DEFAULT 'private',
				`wp_attachment_id` bigint(20) unsigned DEFAULT 0,
				`share_token` varchar(64) NOT NULL DEFAULT '',
				`meta_json` longtext,
				`created_at` datetime NOT NULL,
				`updated_at` datetime NOT NULL,
				`captured_at` datetime NULL,
				`gps_lat` decimal(10,7) NULL,
				`gps_lng` decimal(10,7) NULL,
				PRIMARY KEY (`id`),
				KEY `user_id` (`user_id`),
				KEY `media_type` (`media_type`),
				KEY `idx_captured` (`captured_at`),
				KEY `idx_geo` (`gps_lat`, `gps_lng`),
				KEY `idx_source_ref` (`user_id`, `source_ref`)
			) {$charset};"
		);
		if ( ! $wpdb->last_error ) {
			update_option( 'zdz_media_schema', 5, true );
			$verified = true;
		}
	}

	/**
	 * Get media records for a user.
	 *
	 * @param int   $user_id The user whose media to retrieve.
	 * @param array $filters {
	 *     @type string $media_type  Filter by type (e.g. 'sketch').
	 *     @type string $source_app  Filter by source plugin.
	 *     @type string $privacy     Filter by privacy level.
	 *     @type int    $limit       Max records (default 20).
	 *     @type int    $offset      Pagination offset (default 0).
	 *     @type string $order       'DESC' or 'ASC' (default 'DESC').
	 * }
	 * @return array List of media records.
	 */
	public static function get_user_media( int $user_id, array $filters = [] ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$where  = [ 'user_id = %d' ];
		$params = [ $user_id ];

		if ( ! empty( $filters['media_type'] ) ) {
			$where[]  = 'media_type = %s';
			$params[] = sanitize_key( $filters['media_type'] );
		}
		if ( ! empty( $filters['source_app'] ) ) {
			$where[]  = 'source_app = %s';
			$params[] = sanitize_key( $filters['source_app'] );
		}
		if ( ! empty( $filters['privacy'] ) ) {
			$where[]  = 'privacy = %s';
			$params[] = sanitize_key( $filters['privacy'] );
		}

		$limit  = absint( $filters['limit'] ?? 20 ) ?: 20;
		$offset = absint( $filters['offset'] ?? 0 );
		$order  = strtoupper( $filters['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
			. " ORDER BY created_at {$order} LIMIT %d OFFSET %d",
			array_merge( $params, [ $limit, $offset ] )
		);

		return array_map( array( __CLASS__, 'shape_out' ), $wpdb->get_results( $sql, ARRAY_A ) ?: [] );
	}

	/**
	 * Get team-visible media (privacy = 'team' or 'public').
	 *
	 * @param array $filters Same as get_user_media but without user_id restriction.
	 * @return array
	 */
	public static function get_team_media( array $filters = [] ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$where  = [ "privacy IN ('team','public')" ];
		$params = [];

		if ( ! empty( $filters['media_type'] ) ) {
			$where[]  = 'media_type = %s';
			$params[] = sanitize_key( $filters['media_type'] );
		}
		if ( ! empty( $filters['source_app'] ) ) {
			$where[]  = 'source_app = %s';
			$params[] = sanitize_key( $filters['source_app'] );
		}

		$limit  = absint( $filters['limit'] ?? 20 ) ?: 20;
		$offset = absint( $filters['offset'] ?? 0 );

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
			. " ORDER BY created_at DESC LIMIT %d OFFSET %d",
			array_merge( $params, [ $limit, $offset ] )
		);

		return array_map( array( __CLASS__, 'shape_out' ), $wpdb->get_results( $sql, ARRAY_A ) ?: [] );
	}

	/**
	 * Get ALL media org-wide, regardless of owner or privacy (admin browse).
	 *
	 * This is the data source for the Media app's admin-only "All" scope: it
	 * returns every row so an owner/admin can DISCOVER photos that are stored
	 * 'private' (uploader-only in the list surfaces) and would otherwise never
	 * appear in a browsable gallery. It is NOT permission-gated here — the
	 * CALLER must authorize the viewer (the Media endpoint gates it on
	 * ZDZ_User_Media-consumer admin: WP administrator / zdz_owner / zdz_admin) —
	 * mirroring how get_team_media() is likewise un-gated and relies on its
	 * caller. Rows still pass through shape_out(), so every URL returned is a
	 * token-gated proxy link, never a raw uploads path (item-#1 contract).
	 *
	 * Access note: private media served via /?zdz_media=<id>&t=<token> is ALREADY
	 * viewable by admins at the serve() layer (v2.33.0: "private → owner +
	 * admins"), so surfacing the row to an admin here does not widen file access
	 * beyond what serve() already permits — it only makes the row discoverable.
	 *
	 * @param array $filters {
	 *     @type string $media_type  Filter by type (e.g. 'photo', 'sketch').
	 *     @type string $source_app  Filter by source plugin.
	 *     @type string $privacy     Optional exact-privacy filter.
	 *     @type int    $limit       Max records (default 20).
	 *     @type int    $offset      Pagination offset (default 0).
	 *     @type string $order       'DESC' or 'ASC' (default 'DESC').
	 * }
	 * @return array List of media records (shape_out()'d).
	 */
	public static function get_all_media( array $filters = [] ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $filters['media_type'] ) ) {
			$where[]  = 'media_type = %s';
			$params[] = sanitize_key( $filters['media_type'] );
		}
		if ( ! empty( $filters['source_app'] ) ) {
			$where[]  = 'source_app = %s';
			$params[] = sanitize_key( $filters['source_app'] );
		}
		if ( ! empty( $filters['privacy'] ) ) {
			$where[]  = 'privacy = %s';
			$params[] = sanitize_key( $filters['privacy'] );
		}

		$limit  = absint( $filters['limit'] ?? 20 ) ?: 20;
		$offset = absint( $filters['offset'] ?? 0 );
		$order  = strtoupper( $filters['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
			. " ORDER BY created_at {$order} LIMIT %d OFFSET %d",
			array_merge( $params, [ $limit, $offset ] )
		);

		return array_map( array( __CLASS__, 'shape_out' ), $wpdb->get_results( $sql, ARRAY_A ) ?: [] );
	}

	/**
	 * Get a single media record by ID.
	 *
	 * @param int $media_id
	 * @return array|null
	 */
	public static function get_by_id( int $media_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $media_id ),
			ARRAY_A
		);

		return $row ? self::shape_out( $row ) : null;
	}

	/**
	 * Fetch a single record by its source_ref (indexed; v2.36.0 idx_source_ref).
	 * Scoped by user_id — source_ref embeds a client-supplied capture uid, so
	 * scoping prevents a cross-user collision from returning another user's row.
	 * Returns the newest match if somehow duplicated. Mirrors get_by_id (returns
	 * shape_out() so callers get gated proxy URLs, never raw uploads paths).
	 *
	 * @param int    $user_id
	 * @param string $source_ref  e.g. 'photo:uid:<capture-uid>'.
	 * @return array|null
	 */
	public static function get_by_source_ref( int $user_id, string $source_ref ): ?array {
		if ( $user_id <= 0 || '' === $source_ref ) {
			return null;
		}
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND source_ref = %s ORDER BY id DESC LIMIT 1",
				$user_id, $source_ref
			),
			ARRAY_A
		);
		return $row ? self::shape_out( $row ) : null;
	}

	/**
	 * Update a media record.
	 *
	 * @param int   $media_id Record ID.
	 * @param array $updates  Fields to update (title, description, privacy, meta_json).
	 * @return bool
	 */
	public static function update( int $media_id, array $updates ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// EXIF provenance is READ-ONLY by design (v2.20.4): captured_at,
		// gps_lat and gps_lng are intentionally NOT in this whitelist, so no
		// later edit can overwrite the capture time/location recorded at
		// ingest. Do not add them here — provenance must remain forensic.
		$allowed = [ 'title', 'description', 'privacy', 'meta_json' ];
		$data    = [];
		$formats = [];

		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $updates ) ) {
				$data[ $key ] = $key === 'privacy'
					? ( in_array( $updates[ $key ], [ 'private', 'team', 'public' ], true ) ? $updates[ $key ] : 'private' )
					: sanitize_text_field( $updates[ $key ] );
				$formats[] = '%s';
			}
		}

		if ( empty( $data ) ) {
			return false;
		}

		$data['updated_at'] = current_time( 'mysql' );
		$formats[]          = '%s';

		return (bool) $wpdb->update( $table, $data, [ 'id' => $media_id ], $formats, [ '%d' ] );
	}

	/**
	 * Delete a media record and optionally its WP attachment.
	 *
	 * @param int  $media_id       Record ID.
	 * @param int  $user_id        Must match the record's user_id (security check).
	 * @param bool $delete_file    Also delete the WP attachment (default true).
	 * @return bool
	 */
	public static function delete( int $media_id, int $user_id, bool $delete_file = true ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$row = self::get_by_id( $media_id );
		if ( ! $row ) {
			return false;
		}

		// Security: only the owner or an admin can delete
		if ( (int) $row['user_id'] !== $user_id && ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// Delete WP attachment if requested
		if ( $delete_file && ! empty( $row['wp_attachment_id'] ) ) {
			wp_delete_attachment( (int) $row['wp_attachment_id'], true );
		}

		return (bool) $wpdb->delete( $table, [ 'id' => $media_id ], [ '%d' ] );
	}

	/**
	 * Count media for a user (for UI badges, quotas, etc.).
	 *
	 * @param int    $user_id
	 * @param string $media_type Optional filter.
	 * @return int
	 */
	public static function count( int $user_id, string $media_type = '' ): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		if ( $media_type ) {
			return (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND media_type = %s",
				$user_id, $media_type
			) );
		}

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
			$user_id
		) );
	}

	/* ------------------------------------------------------------------ */
	/*  EXIF provenance helpers (v2.20.4)                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Extract capture time + GPS from a photo's EXIF.
	 *
	 * Reads from the ORIGINAL file path (caller must pass the full-size
	 * original, not a thumbnail — resize/re-encode strips EXIF). PHP's
	 * exif_read_data() handles JPEG/TIFF reliably; for HEIC (common on
	 * iPhone) EXIF may be unreadable here, in which case this returns only
	 * whatever it could parse and the caller falls back to client-supplied
	 * values.
	 *
	 * EXIF timestamps are local with no timezone; we store the local time as
	 * given and keep the raw block in meta_json so nothing is lost.
	 *
	 * @param string $path Absolute path to the original image file.
	 * @return array{ raw?: array, captured_at?: string, gps_lat?: float, gps_lng?: float }
	 */
	private static function extract_exif( string $path ): array {
		if ( ! $path || ! is_readable( $path ) ) return [];
		if ( ! function_exists( 'exif_read_data' ) ) return [];

		// Only attempt formats exif_read_data understands; silence its notices
		// on unsupported/truncated files.
		$e = @exif_read_data( $path, 0, true );
		if ( ! $e || ! is_array( $e ) ) return [];

		$out = [ 'raw' => $e ]; // keep EVERYTHING in meta_json for the log

		// ── Capture time: EXIF.DateTimeOriginal is "YYYY:MM:DD HH:MM:SS" ──
		$dto = $e['EXIF']['DateTimeOriginal'] ?? ( $e['IFD0']['DateTime'] ?? '' );
		if ( $dto && preg_match( '/^(\d{4}):(\d{2}):(\d{2})\s+(\d{2}:\d{2}:\d{2})/', $dto, $m ) ) {
			// Convert the date portion's colons to dashes; keep the time as-is.
			$out['captured_at'] = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}";
		}

		// ── GPS → decimal degrees ──
		if ( ! empty( $e['GPS']['GPSLatitude'] ) && ! empty( $e['GPS']['GPSLongitude'] ) ) {
			$lat = self::gps_to_decimal( $e['GPS']['GPSLatitude'], $e['GPS']['GPSLatitudeRef'] ?? 'N' );
			$lng = self::gps_to_decimal( $e['GPS']['GPSLongitude'], $e['GPS']['GPSLongitudeRef'] ?? 'E' );
			if ( null !== $lat && null !== $lng ) {
				$out['gps_lat'] = $lat;
				$out['gps_lng'] = $lng;
			}
		}

		return $out;
	}

	/**
	 * Convert an EXIF GPS coordinate to signed decimal degrees.
	 *
	 * EXIF stores each of degrees/minutes/seconds as a "num/den" rational
	 * string. Decimal = deg + min/60 + sec/3600, negated for S / W refs.
	 *
	 * @param array  $coord [deg, min, sec] as rational strings (e.g. "44/1").
	 * @param string $ref   'N' | 'S' | 'E' | 'W'.
	 * @return float|null
	 */
	private static function gps_to_decimal( $coord, string $ref ): ?float {
		if ( ! is_array( $coord ) || count( $coord ) < 3 ) return null;

		$parts = [];
		foreach ( array_slice( $coord, 0, 3 ) as $rational ) {
			if ( is_string( $rational ) && false !== strpos( $rational, '/' ) ) {
				list( $num, $den ) = array_pad( explode( '/', $rational, 2 ), 2, '1' );
				$den = (float) $den;
				$parts[] = $den != 0.0 ? ( (float) $num / $den ) : 0.0;
			} else {
				$parts[] = (float) $rational;
			}
		}

		$decimal = $parts[0] + ( $parts[1] / 60 ) + ( $parts[2] / 3600 );

		$ref = strtoupper( $ref );
		if ( 'S' === $ref || 'W' === $ref ) {
			$decimal = -$decimal;
		}

		return round( $decimal, 7 );
	}

	/* ═══ v2.33.0: gated media serving (theme-infra fix) ═══
	 * Private media was previously handed to the browser as a raw, public wp-uploads
	 * URL — the `privacy` field only filtered LIST queries, not file access, so a
	 * private photo/sketch was retrievable by anyone who had/guessed the URL. Now the
	 * getters return a gated URL and the file streams ONLY to an authorized viewer.
	 * Access mapping (CT-approved): private → owner + admins (view_others_data);
	 * team/public → any logged-in user. Serves camera + media-library + sketch-pad. */

	/** Get-or-lazily-mint the opaque per-record token (persisted). */
	private static function token_for( array $row ): string {
		$tok = (string) ( $row['share_token'] ?? '' );
		if ( '' !== $tok && strlen( $tok ) >= 24 ) { return $tok; }
		$id = (int) ( $row['id'] ?? 0 );
		if ( $id <= 0 ) { return ''; }
		global $wpdb;
		$tok = bin2hex( random_bytes( 16 ) );
		$wpdb->update( $wpdb->prefix . self::TABLE, array( 'share_token' => $tok ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		return $tok;
	}

	/** Membership/privacy-gated URL for a media record (never the raw file path). */
	public static function secure_url( array $row, string $size = 'full' ): string {
		$id = (int) ( $row['id'] ?? 0 );
		$tok = self::token_for( $row );
		if ( $id <= 0 || '' === $tok ) { return (string) ( $row['file_url'] ?? '' ); }
		$args = array( 'zdz_media' => $id, 't' => $tok );
		if ( 'thumb' === $size ) { $args['s'] = 'thumb'; }
		return add_query_arg( $args, home_url( '/' ) );
	}

	/** Rewrite a row's URLs to the gated proxy before it leaves the data layer. */
	private static function shape_out( array $row ): array {
		$tok = self::token_for( $row );      // mint once, then reuse for both URLs
		$row['share_token']   = $tok;
		$row['file_url']      = self::secure_url( $row, 'full' );
		$row['thumbnail_url'] = self::secure_url( $row, 'thumb' );
		return $row;
	}

	/** Admin-tier viewer test (owner-override for private media). */
	private static function viewer_can_view_others( int $uid ): bool {
		if ( $uid <= 0 ) { return false; }
		if ( user_can( $uid, 'manage_options' ) ) { return true; }
		if ( class_exists( 'ZDZ_Data_Permissions' ) && method_exists( 'ZDZ_Data_Permissions', 'can' ) ) {
			return (bool) ZDZ_Data_Permissions::can( $uid, 'view_others_data' );
		}
		$u = get_userdata( $uid );
		return $u && is_array( $u->roles ) && (bool) array_intersect( array( 'zdz_owner', 'zdz_admin' ), $u->roles );
	}

	/** Map a stored wp-uploads URL back to a confined local path (attachment-less rows). */
	private static function url_to_local_path( string $url ): string {
		if ( '' === $url ) { return ''; }
		$up = wp_get_upload_dir();
		if ( 0 !== strpos( $url, (string) $up['baseurl'] ) ) { return ''; }
		$rel  = ltrim( substr( $url, strlen( (string) $up['baseurl'] ) ), '/' );
		$path = trailingslashit( $up['basedir'] ) . $rel;
		$real = realpath( $path ); $base = realpath( $up['basedir'] );
		return ( $real && $base && 0 === strpos( $real, $base ) ) ? $real : '';
	}

	/** template_redirect entry — /?zdz_media=<id>&t=<token>[&s=thumb]. */
	public static function maybe_serve(): void {
		if ( ! isset( $_GET['zdz_media'] ) ) { return; }
		self::serve();
	}

	private static function serve(): void {
		$fail = static function () { status_header( 404 ); header( 'X-Robots-Tag: noindex, nofollow', true ); exit; };
		$id  = isset( $_GET['zdz_media'] ) ? absint( $_GET['zdz_media'] ) : 0;
		$tok = isset( $_GET['t'] ) ? (string) wp_unslash( $_GET['t'] ) : '';
		$want_thumb = ( ( $_GET['s'] ?? '' ) === 'thumb' );
		if ( $id <= 0 || '' === $tok ) { $fail(); }

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, user_id, privacy, wp_attachment_id, file_url, thumbnail_url, filename, share_token FROM " . $wpdb->prefix . self::TABLE . " WHERE id = %d",
			$id
		), ARRAY_A );
		if ( ! $row ) { $fail(); }

		// Constant-time token check.
		$expected = (string) ( $row['share_token'] ?? '' );
		if ( '' === $expected || ! hash_equals( $expected, $tok ) ) { $fail(); }

		// Access by privacy (CT-approved): private -> owner + admins; team/public -> any logged-in.
		$uid = (int) get_current_user_id();
		if ( 'private' === (string) $row['privacy'] ) {
			$is_owner = ( $uid > 0 && (int) $row['user_id'] === $uid );
			if ( ! $is_owner && ! self::viewer_can_view_others( $uid ) ) { $fail(); }
		} elseif ( ! is_user_logged_in() ) {
			$fail();
		}

		// Resolve the file: prefer the WP attachment (thumb = 'medium'); fall back to the stored URL's path.
		$path = '';
		$att  = (int) ( $row['wp_attachment_id'] ?? 0 );
		if ( $want_thumb && $att > 0 && function_exists( 'image_get_intermediate_size' ) ) {
			$thumb = image_get_intermediate_size( $att, 'medium' );
			if ( ! empty( $thumb['path'] ) ) { $path = trailingslashit( wp_get_upload_dir()['basedir'] ) . $thumb['path']; }
		}
		if ( '' === $path && $att > 0 ) { $path = (string) get_attached_file( $att ); }
		if ( '' === $path ) {
			$src  = $want_thumb ? ( $row['thumbnail_url'] ?: $row['file_url'] ) : $row['file_url'];
			$path = self::url_to_local_path( (string) $src );
		}
		if ( '' === $path || ! is_readable( $path ) ) { $fail(); }

		$mime = wp_check_filetype( $path )['type'] ?: 'application/octet-stream';
		$name = sanitize_file_name( (string) ( $row['filename'] ?: ( 'media-' . (int) $row['id'] ) ) );
		while ( ob_get_level() > 0 ) { ob_end_clean(); }
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff', true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: no-referrer', true );
		header( 'Cache-Control: private, max-age=600, must-revalidate', true );
		readfile( $path );
		exit;
	}
}

add_action( 'template_redirect', array( 'ZDZ_User_Media', 'maybe_serve' ) );
