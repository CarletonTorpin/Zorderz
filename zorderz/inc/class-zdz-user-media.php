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

		// ── EXIF provenance ──────────────────────────────────────
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
	/**
	 * The table's schema version, in ONE place: the fast-path gate and both stamps read it, so a
	 * bump can never update one and miss another (the column would then never appear on an
	 * existing site while new code writes to it). 6: marks_json, the drawings on a photo.
	 */
	const SCHEMA_VERSION = 6;

	private static function ensure_table(): void {
		// Fast path: schema already verified this process
		static $verified = false;
		if ( $verified ) return;
		if ( get_option( 'zdz_media_schema', 0 ) >= self::SCHEMA_VERSION ) { $verified = true; return; }

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
			// ── (schema 3): EXIF provenance columns ──
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
			// ── (schema 4): per-record share_token for the gated media proxy ──
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
			// ── (schema 5): index source_ref for O(1) dedupe ──
			// zdz-camera dedupes a retried upload by source_ref ('photo:uid:<uid>');
			// without this key get_by_source_ref would full-scan. Composite
			// (user_id, source_ref) since source_ref embeds a client-supplied uid.
			if ( ! in_array( 'idx_source_ref', (array) $idx, true ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD KEY `idx_source_ref` (`user_id`, `source_ref`)" );
			}
			// ── schema 6: the drawings on a photo, in their own column so a tag edit, a rotate or an
			// owner move (all meta_json) never races a drawing, and the photo file is never touched.
			if ( ! in_array( 'marks_json', $cols, true ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `marks_json` longtext NULL AFTER `meta_json`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus the class constant TABLE
			}
			// Stamp success only when every ALTER above succeeded: a permissions error, a row-size
			// limit or a lock timeout leaves the stamp alone, so the upgrade is tried again.
			if ( $wpdb->last_error ) {
				error_log( 'ZDZ_User_Media::ensure_table upgrade failed, NOT stamping schema: ' . $wpdb->last_error );
				return;
			}
			update_option( 'zdz_media_schema', self::SCHEMA_VERSION, true );
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
				`marks_json` longtext NULL,
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
			update_option( 'zdz_media_schema', self::SCHEMA_VERSION, true );
			$verified = true;
		}
	}

	/**
	 * How many media records this person added, by type, between two site-local times. For the media app's
	 * media.my_counts (a "Photos this week" home tile): the app asks here, so no plugin reads this table.
	 * `created_at` is written in the site's time (current_time( 'mysql' )), so the bounds are site-local
	 * 'Y-m-d H:i:s', from inclusive, to exclusive. Counts only.
	 *
	 * @return array|null [ type => count ] (photo, video, sketch, document, ... as stored), or null when the table could
	 *                    not be read (never an empty answer standing in for a failure).
	 */
	public static function count_for( int $user_id, string $from_local, string $to_local ): ?array {
		global $wpdb;
		if ( $user_id <= 0 || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $from_local ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $to_local ) ) {
			return null;
		}
		$table = $wpdb->prefix . self::TABLE;
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT media_type, COUNT(*) AS n FROM {$table} WHERE user_id = %d AND created_at >= %s AND created_at < %s GROUP BY media_type", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus the class constant TABLE
			$user_id, $from_local, $to_local
		), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return null;
		}
		$out = array();
		foreach ( $rows as $r ) {
			$t         = sanitize_key( (string) ( $r['media_type'] ?? '' ) );
			$t         = '' === $t ? 'other' : $t;
			$out[ $t ] = ( $out[ $t ] ?? 0 ) + (int) ( $r['n'] ?? 0 );
		}
		return $out;
	}

	/**
	 * Get media records for a user.
	 *
	 * @param int   $user_id The user whose media to retrieve.
	 * @param array $filters {
	 *     @type string $media_type  Filter by type (e.g. 'sketch').
	 *     @type string $source_app  Filter by source app.
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
	 * viewable by admins at the serve() layer ("private → owner +
	 * admins"), so surfacing the row to an admin here does not widen file access
	 * beyond what serve() already permits — it only makes the row discoverable.
	 *
	 * @param array $filters {
	 *     @type string $media_type  Filter by type (e.g. 'photo', 'sketch').
	 *     @type string $source_app  Filter by source app.
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
	 * Fetch a single record by its source_ref (indexed; idx_source_ref).
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
	 * Media shared TO a user — the "Shared With Me" scope. INNER JOINs
	 * the grants table so a deleted media row simply drops out; deduped to the
	 * most-recent share per item; rows pass through shape_out() so the URLs are
	 * the same token-gated proxy links every other scope returns. Adds
	 * shared_by / shared_by_name / shared_at / share_group.
	 */
	public static function get_shared_with_me( int $uid, array $filters = [] ): array {
		if ( $uid <= 0 || ! class_exists( 'ZDZ_Media_Share' ) ) { return []; }
		ZDZ_Media_Share::ensure_table();
		global $wpdb;
		$m   = $wpdb->prefix . self::TABLE;
		$s   = $wpdb->prefix . ZDZ_Media_Share::TABLE;
		$now = current_time( 'mysql' );

		$where  = [ 's.recipient_id = %d', 's.revoked_at IS NULL', '( s.expires_at IS NULL OR s.expires_at > %s )' ];
		$params = [ $uid, $now ];
		if ( ! empty( $filters['media_type'] ) ) { $where[] = 'm.media_type = %s'; $params[] = sanitize_key( $filters['media_type'] ); }

		$limit  = absint( $filters['limit'] ?? 20 ) ?: 20;
		$offset = absint( $filters['offset'] ?? 0 );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- {$s} and {$m} are $wpdb->prefix plus class constants; $where holds only the fixed fragments above, each with its own placeholder
		$sql = $wpdb->prepare(
			"SELECT m.*, MAX(s.created_at) AS shared_at,
			        SUBSTRING_INDEX(GROUP_CONCAT(s.shared_by ORDER BY s.created_at DESC), ',', 1) AS shared_by,
			        SUBSTRING_INDEX(GROUP_CONCAT(s.share_group ORDER BY s.created_at DESC), ',', 1) AS share_group
			   FROM {$s} s INNER JOIN {$m} m ON m.id = s.media_id
			  WHERE " . implode( ' AND ', $where ) . "
			  GROUP BY m.id
			  ORDER BY shared_at DESC
			  LIMIT %d OFFSET %d",
			array_merge( $params, [ $limit, $offset ] )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$rows = $wpdb->get_results( $sql, ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the $wpdb->prepare() result above
		$out  = [];
		foreach ( $rows as $row ) {
			$by     = (int) $row['shared_by'];
			$u      = get_userdata( $by );
			$shaped = self::shape_out( $row );
			$shaped['shared_by']      = $by;
			$shaped['shared_by_name'] = $u ? $u->display_name : __( 'A teammate', 'zorderz' );
			$shaped['shared_at']      = (string) $row['shared_at'];
			$shaped['share_group']    = (string) $row['share_group'];
			$out[] = $shaped;
		}
		return $out;
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

		// EXIF provenance is READ-ONLY by design: captured_at,
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

	// ------------------------------------------------------------------------------------------------ tags and owner

	/**
	 * Add and remove keyword TAGS on an existing media row, with a record of who changed them. Tags live inside
	 * meta_json.tags (there is no tags column). This is the ONLY sanctioned tag writer, because it decodes the current
	 * meta, merges and re-encodes it with wp_json_encode(): update() passes meta_json through sanitize_text_field(),
	 * which would damage the stored EXIF block. Who changed what, when and why is written into meta_json
	 * (last_tagged_by / last_tagged_at and a capped tag_history) and to the error log for the administrator's audit.
	 * Not permission-gated here: the CALLER authorizes the actor (the row's owner, or someone who may see others'
	 * photos), exactly as for get_all_media().
	 *
	 * Idempotent: adding a tag already there (in any case) or removing one that is not there changes nothing and
	 * writes nothing.
	 *
	 * @param int    $media_id
	 * @param array  $add      Tags to add (strings).
	 * @param array  $remove   Tags to remove (strings; case-insensitive).
	 * @param int    $actor_id The person making the edit (0 → the signed-in person).
	 * @param string $reason   Optional note ("why").
	 * @return array|null { tags, added, removed, changed } or null on a missing row or a failed write.
	 */
	public static function apply_tags( int $media_id, array $add = [], array $remove = [], int $actor_id = 0, string $reason = '' ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		if ( $media_id <= 0 ) {
			return null;
		}

		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT meta_json FROM {$table} WHERE id = %d", $media_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus the class constant TABLE
		if ( null === $raw ) {
			return null; // no such row
		}
		$meta = json_decode( (string) $raw, true );
		if ( ! is_array( $meta ) ) {
			$meta = [];
		}

		$clean = static function ( $t ) {
			$t = trim( sanitize_text_field( (string) $t ) );
			return ( '' !== $t && mb_strlen( $t ) <= 60 ) ? $t : '';
		};
		$cur    = ( isset( $meta['tags'] ) && is_array( $meta['tags'] ) )
			? array_values( array_filter( array_map( $clean, $meta['tags'] ) ) ) : [];
		$add    = array_filter( array_map( $clean, (array) $add ) );
		$remove = array_map( 'strtolower', array_filter( array_map( $clean, (array) $remove ) ) );

		$before = $cur;
		$lc     = array_map( 'strtolower', $cur );
		foreach ( $add as $t ) {
			if ( ! in_array( strtolower( $t ), $lc, true ) ) {
				$cur[] = $t;
				$lc[]  = strtolower( $t );
			}
		}
		if ( $remove ) {
			$cur = array_values( array_filter( $cur, static function ( $t ) use ( $remove ) {
				return ! in_array( strtolower( $t ), $remove, true );
			} ) );
		}
		if ( count( $cur ) > 40 ) {
			$cur = array_slice( $cur, 0, 40 ); // a sane cap per item
		}

		$added   = array_values( array_diff( $cur, $before ) );
		$removed = array_values( array_diff( $before, $cur ) );
		if ( empty( $added ) && empty( $removed ) ) {
			return [ 'tags' => array_values( $cur ), 'added' => [], 'removed' => [], 'changed' => false ];
		}

		$actor  = $actor_id > 0 ? $actor_id : get_current_user_id();
		$reason = trim( sanitize_text_field( $reason ) );
		$when   = current_time( 'mysql' );

		$meta['tags'] = array_values( $cur );
		if ( ! isset( $meta['tag_history'] ) || ! is_array( $meta['tag_history'] ) ) {
			$meta['tag_history'] = [];
		}
		$entry = [ 'by' => $actor, 'at' => $when, 'add' => $added, 'rm' => $removed ];
		if ( '' !== $reason ) {
			$entry['why'] = $reason;
		}
		$meta['tag_history'][] = $entry;
		if ( count( $meta['tag_history'] ) > 50 ) {
			$meta['tag_history'] = array_slice( $meta['tag_history'], -50 );
		}
		$meta['last_tagged_by'] = $actor;
		$meta['last_tagged_at'] = $when;

		$ok = $wpdb->update(
			$table,
			[ 'meta_json' => wp_json_encode( $meta ), 'updated_at' => $when ],
			[ 'id' => $media_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		if ( false === $ok ) {
			return null;
		}

		// The administrator's audit trail (the server log; never shown to people in the app).
		error_log( sprintf(
			'ZDZ_User_Media tags: media #%d by user #%d - added[%s] removed[%s]%s',
			$media_id, $actor,
			implode( ',', $added ), implode( ',', $removed ),
			'' !== $reason ? ' reason="' . $reason . '"' : ''
		) );

		return [ 'tags' => array_values( $cur ), 'added' => $added, 'removed' => $removed, 'changed' => true ];
	}

	/**
	 * An administrator moves a photo to another person (the one who should own it).
	 *
	 * `user_id` is the one owner column (privacy, the badge, delete and share all follow it), so the move changes it,
	 * and the record keeps who UPLOADED it (meta_json.uploaded_by, written once, never changed again) plus every move
	 * (meta_json.owner_history: from, to, by, at; the last 20). No schema change: this record lives beside the tags in
	 * meta_json, read through provenance().
	 *
	 * Refused: an actor who is not an administrator (actor_may_reassign()), a missing photo or person, the shared
	 * device as the new owner (it is never a person, so it never owns a photo), and anyone who does not work in the
	 * app (below the viewer rung of the capability ladder: a customer or subscriber account a crafted request names).
	 *
	 * The UPDATE carries the owner the caller saw (`WHERE user_id = old`) and the details it read, so two
	 * administrators moving the same photo at once cannot both win, a move never lands on a row someone else moved a
	 * moment before, and a tag edit or a rotate saved in between is never overwritten.
	 *
	 * @return array|WP_Error [ 'id', 'user_id', 'uploaded_by', 'moved' ] or an error the caller can show.
	 */
	public static function reassign_owner( int $media_id, int $new_uid, int $actor_id, string $reason = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		if ( $actor_id <= 0 || ! self::actor_may_reassign( $actor_id ) ) {
			return new WP_Error( 'zdz_media_not_admin', __( 'Only an administrator can move a photo to someone else.', 'zorderz' ) );
		}
		// The raw row (get_by_id() shapes it for the browser and may mint a share token: not wanted for a move).
		$row = $media_id > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id, meta_json FROM {$table} WHERE id = %d", $media_id ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus the class constant TABLE
		if ( ! $row ) {
			return new WP_Error( 'zdz_media_missing', __( 'That photo was not found.', 'zorderz' ) );
		}
		$u = $new_uid > 0 ? get_userdata( $new_uid ) : false;
		if ( ! $u ) {
			return new WP_Error( 'zdz_media_no_user', __( 'That person was not found.', 'zorderz' ) );
		}
		// The shared device is never a person, so it never owns a photo.
		if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $new_uid ) ) {
			return new WP_Error( 'zdz_media_kiosk', __( 'A photo cannot belong to the shared device.', 'zorderz' ) );
		}
		// Only someone who works in the app (viewer and up on the capability ladder). Without the ladder: refused.
		if ( ! class_exists( 'ZDZ_Capabilities' ) || ! method_exists( 'ZDZ_Capabilities', 'tier_of' )
			|| ! in_array( (string) ZDZ_Capabilities::tier_of( $new_uid ), array( 'viewer', 'staff', 'admin' ), true ) ) {
			return new WP_Error( 'zdz_media_not_staff', __( 'A photo can only belong to someone who uses the app.', 'zorderz' ) );
		}
		$old = (int) $row['user_id'];
		if ( $old === $new_uid ) {
			$p = self::provenance( $row );
			return [ 'id' => $media_id, 'user_id' => $old, 'uploaded_by' => $p['uploaded_by'], 'moved' => false ];
		}
		$meta = json_decode( (string) ( $row['meta_json'] ?? '' ), true );
		if ( ! is_array( $meta ) ) {
			$meta = [];
		}
		if ( empty( $meta['uploaded_by'] ) ) {
			$meta['uploaded_by'] = $old; // the person who took it; never rewritten after the first move
		}
		$when = current_time( 'mysql' );
		if ( ! isset( $meta['owner_history'] ) || ! is_array( $meta['owner_history'] ) ) {
			$meta['owner_history'] = [];
		}
		$entry  = [ 'from' => $old, 'to' => $new_uid, 'by' => $actor_id, 'at' => $when ];
		$reason = trim( sanitize_text_field( $reason ) );
		if ( '' !== $reason ) {
			$entry['why'] = mb_substr( $reason, 0, 200 );
		}
		$meta['owner_history'][] = $entry;
		if ( count( $meta['owner_history'] ) > 20 ) {
			$meta['owner_history'] = array_slice( $meta['owner_history'], -20 );
		}
		// The UPDATE carries the owner AND the details the move read: a changed owner is refused; changed details are
		// read again once.
		$raw = $row['meta_json'];
		for ( $try = 0; $try < 2; $try++ ) {
			$args = [ $new_uid, wp_json_encode( $meta ), $when, $media_id, $old ];
			if ( null === $raw ) {
				$sql = "UPDATE {$table} SET user_id = %d, meta_json = %s, updated_at = %s WHERE id = %d AND user_id = %d AND meta_json IS NULL";
			} else {
				$sql    = "UPDATE {$table} SET user_id = %d, meta_json = %s, updated_at = %s WHERE id = %d AND user_id = %d AND BINARY meta_json = %s";
				$args[] = (string) $raw;
			}
			$n = $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is one of the two fixed UPDATE statements above, where {$table} is $wpdb->prefix plus the class constant TABLE
			if ( $n ) {
				break;
			}
			$now = $wpdb->get_row( $wpdb->prepare( "SELECT user_id, meta_json FROM {$table} WHERE id = %d", $media_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus the class constant TABLE
			if ( 0 === $try && is_array( $now ) && (int) $now['user_id'] === $old ) {
				$raw  = $now['meta_json'];
				$meta = json_decode( (string) ( $raw ?? '' ), true );
				$meta = is_array( $meta ) ? $meta : [];
				if ( empty( $meta['uploaded_by'] ) ) {
					$meta['uploaded_by'] = $old;
				}
				$hist   = ( isset( $meta['owner_history'] ) && is_array( $meta['owner_history'] ) ) ? $meta['owner_history'] : [];
				$hist[] = $entry;
				$meta['owner_history'] = array_slice( $hist, -20 );
				continue;
			}
			return new WP_Error( 'zdz_media_moved_meanwhile', __( 'Someone changed this photo a moment ago. Open it again and retry.', 'zorderz' ) );
		}
		error_log( sprintf( 'ZDZ_User_Media owner: media #%d moved from user #%d to user #%d by user #%d', $media_id, $old, $new_uid, $actor_id ) );
		return [ 'id' => $media_id, 'user_id' => $new_uid, 'uploaded_by' => (int) $meta['uploaded_by'], 'moved' => true ];
	}

	/**
	 * Who may move a photo to another person: the administrator tier only (a site administrator, or a person
	 * ZDZ_Hierarchy counts as an administrator); never the shared device, whatever else the account holds.
	 */
	public static function actor_may_reassign( int $uid ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			return false;
		}
		if ( user_can( $uid, 'manage_options' ) ) {
			return true;
		}
		return class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_admin' ) && ZDZ_Hierarchy::is_admin( $uid );
	}

	/**
	 * Who uploaded a row and who last moved it, as user ids only (never a name: a caller that shows one resolves it
	 * under its own visibility rules). A row never moved: uploaded_by is its owner and moved_by 0.
	 *
	 * @return array [ 'uploaded_by' => int, 'moved_by' => int, 'moved_at' => string ]
	 */
	public static function provenance( array $row ): array {
		$meta = $row['meta_json'] ?? '';
		$meta = is_array( $meta ) ? $meta : json_decode( (string) $meta, true );
		$meta = is_array( $meta ) ? $meta : [];
		$up   = (int) ( $meta['uploaded_by'] ?? 0 );
		$hist = ( isset( $meta['owner_history'] ) && is_array( $meta['owner_history'] ) ) ? $meta['owner_history'] : [];
		$last = $hist ? end( $hist ) : null;
		return [
			'uploaded_by' => $up > 0 ? $up : (int) ( $row['user_id'] ?? 0 ),
			'moved_by'    => is_array( $last ) ? (int) ( $last['by'] ?? 0 ) : 0,
			'moved_at'    => is_array( $last ) ? (string) ( $last['at'] ?? '' ) : '',
		];
	}

	// ------------------------------------------------------------------------------------------------ drawings

	/**
	 * Is the marks column there? The schema stamp is written only after every ALTER succeeded, so reading it never
	 * runs an ALTER on a page view. A site that has not had an upload since the upgrade gets the column on its first
	 * save of a drawing (save_layer() runs the upgrade).
	 */
	public static function marks_ready(): bool {
		return (int) get_option( 'zdz_media_schema', 0 ) >= 6;
	}

	/** Run the table upgrade now (the first drawing saved on a site that has not uploaded since the column arrived). */
	public static function ensure_schema(): void {
		self::ensure_table();
	}

	/**
	 * Photos and sketches can be drawn on; a video or a document cannot (its file is not a picture). An estimate's
	 * measurement photo (`estimate_photo`) is a photo too - the one a person most wants to point an arrow at. Its
	 * transcript (`estimate_transcript`) is text, not a picture.
	 */
	const MARKABLE = array( 'photo', 'sketch', 'estimate_photo' );

	public static function markable( array $row ): bool {
		return in_array( (string) ( $row['media_type'] ?? 'photo' ), self::MARKABLE, true );
	}

	/**
	 * May this signed-in person see this photo at all? A team or public photo: anyone signed in. A private one:
	 * viewer_can_view() (its owner, the admin tier, a share grant, or an app's grant - the jobs app's signed grant for a
	 * work photo at a Project's address rides on the request as zprj_g / zprj_e, on the drawing routes too).
	 */
	public static function may_see_photo( int $uid, array $row ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		if ( in_array( (string) ( $row['privacy'] ?? 'private' ), array( 'team', 'public' ), true ) ) {
			return true;
		}
		return self::viewer_can_view( $uid, $row );
	}

	/**
	 * Who may draw on a photo: anyone who may see it and uses the app, never the shared device, never on a video or a
	 * document. Each person draws only their own layer; who else sees it is theirs to choose.
	 */
	public static function viewer_may_mark( int $uid, array $row ): bool {
		if ( $uid <= 0 || ! self::markable( $row ) || ZDZ_Media_Marks::is_kiosk( $uid ) || ! self::uses_app( $uid ) ) {
			return false;   // a person who uses the app, never a bare signed-in account
		}
		return self::may_see_photo( $uid, $row );
	}

	/** The photo as the drawing code needs it (privacy and owner decide who sees it), or null. */
	private static function mark_row( int $media_id, bool $with_marks ): ?array {
		global $wpdb;
		if ( $media_id <= 0 ) {
			return null;
		}
		$cols = 'id, user_id, privacy, media_type' . ( $with_marks && self::marks_ready() ? ', marks_json' : '' );
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT {$cols} FROM " . $wpdb->prefix . self::TABLE . ' WHERE id = %d', $media_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- {$cols} is a fixed column list; the table is $wpdb->prefix plus the class constant TABLE
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Everything the viewer shows for one photo's drawings: the layers this person sees (theirs first), whether they may
	 * draw, and for an administrator how many more "Show everyone's drawings" would show. One answer for missing, not a
	 * photo you may see, and private (a request learns nothing about photos it may not see).
	 */
	public static function marks_view( int $media_id, int $viewer ) {
		$row = self::mark_row( $media_id, true );
		if ( ! $row || ! ZDZ_Media_Marks::reader( $viewer ) || ! self::may_see_photo( $viewer, $row ) ) {
			return new WP_Error( 'zdz_media_marks_denied', __( 'That photo is not one you can see.', 'zorderz' ) );
		}
		return self::view_of( $row, $viewer );
	}

	private static function view_of( array $row, int $viewer ): array {
		$raw   = $row['marks_json'] ?? null;
		$admin = self::viewer_can_view_others( $viewer ) && ! ZDZ_Media_Marks::is_kiosk( $viewer );
		$all   = $admin && ZDZ_Media_Marks::shows_all( $viewer );
		list( $layers, $hidden ) = ( null === $raw || '' === $raw ) ? array( array(), 0 ) : ZDZ_Media_Marks::view( $raw, $viewer, $row, $all );
		$out = array(
			'id'       => (int) $row['id'],
			'layers'   => $layers,
			'can_mark' => self::viewer_may_mark( $viewer, $row ),
			// the revision this person's drawing is at, even when they cleared it - a new drawing saves from here
			'my_rev'   => ( null === $raw || '' === $raw ) ? 0 : ZDZ_Media_Marks::last_rev( ZDZ_Media_Marks::decode( $raw ), $viewer ),
		);
		if ( $admin ) {
			$out['is_admin'] = true;
			$out['show_all'] = $all;
			$out['hidden_n'] = (int) $hidden;
		}
		return $out;
	}

	/**
	 * Save one person's OWN drawing on a photo. $strokes is the whole layer as they see it after their change; $base_rev
	 * the revision of their layer they started from (0 for a first drawing). Another person saving their own drawing in
	 * the same instant never refuses this one: the UPDATE carries the column's bytes, and when someone else's layer moved
	 * in between it reads again and retries. Refused (409, with the drawings as they are now) only when this person's OWN
	 * layer moved - the same drawing saved from two devices. Nothing else on the row changes (not updated_at: the photo's
	 * address stays the same). $context ('project:79') is kept on a first drawing made from a Project, which then starts
	 * shared with that Project's people; $share/$people, when given, set who sees it in the same save.
	 *
	 * @return array|WP_Error the viewer's view after the save (marks_view())
	 */
	public static function save_layer( int $media_id, int $actor, $strokes, int $base_rev, string $context = '', ?string $share = null, array $people = array() ) {
		global $wpdb;
		if ( ! class_exists( 'ZDZ_Media_Marks' ) ) {
			return new WP_Error( 'zdz_media_unavailable', __( 'Drawing on photos is not available just now.', 'zorderz' ) );
		}
		if ( $actor <= 0 ) {
			return new WP_Error( 'zdz_media_marks_denied', __( 'Sign in to draw on a photo.', 'zorderz' ) );
		}
		$table = $wpdb->prefix . self::TABLE;
		// Who may draw is decided first, from columns every schema has; only then may the first save on
		// a site add the column.
		$who = self::mark_row( $media_id, false );
		if ( ! $who || ! self::viewer_may_mark( $actor, $who ) ) {
			return new WP_Error( 'zdz_media_marks_denied', __( 'You can draw only on a photo you can see.', 'zorderz' ) );
		}
		if ( ! self::marks_ready() ) {
			self::ensure_table();
			if ( ! self::marks_ready() ) {
				return new WP_Error( 'zdz_media_marks_unready', __( 'Drawing on photos is not ready on this site yet. Try again in a minute.', 'zorderz' ) );
			}
		}
		$clean = ZDZ_Media_Marks::clean( $strokes );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$stale = __( 'Your drawing on this photo was saved from somewhere else a moment ago. It is shown now, with what you just drew; tap Save again to keep it.', 'zorderz' );
		for ( $try = 0; $try < 4; $try++ ) {
			$row = self::mark_row( $media_id, true );
			if ( ! $row || ! self::viewer_may_mark( $actor, $row ) ) {
				return new WP_Error( 'zdz_media_marks_denied', __( 'You can draw only on a photo you can see.', 'zorderz' ) );
			}
			$raw  = $row['marks_json'] ?? null;
			$doc  = ZDZ_Media_Marks::decode( $raw );
			if ( ZDZ_Media_Marks::is_stale( $doc, $actor, $base_rev ) ) {   // a cleared drawing keeps counting
				return new WP_Error( 'zdz_media_marks_stale', $stale, array( 'view' => self::view_of( $row, $actor ) ) );
			}
			$res = ZDZ_Media_Marks::with_layer( $doc, $actor, $clean, current_time( 'mysql' ), $context );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			list( $next, $added, $removed ) = $res;
			if ( null !== $share && ZDZ_Media_Marks::layer_index( $next, $actor ) >= 0 ) {
				$next = ZDZ_Media_Marks::with_share( $next, $actor, $share, self::sharable_ids( $row, $people, $actor ) );
				if ( is_wp_error( $next ) ) {
					return $next;
				}
			}
			if ( $next === $doc && ( null === $raw || '' === $raw || 2 === (int) ( json_decode( (string) $raw, true )['v'] ?? 0 ) ) ) {
				return self::view_of( $row, $actor ); // nothing changed
			}
			$json = wp_json_encode( $next );
			$n    = ( null === $raw )
				? $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET marks_json = %s WHERE id = %d AND marks_json IS NULL", $json, $media_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus the class constant TABLE
				: $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET marks_json = %s WHERE id = %d AND BINARY marks_json = %s", $json, $media_id, (string) $raw ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus the class constant TABLE
			if ( $n ) {
				error_log( sprintf( 'ZDZ_User_Media marks: media #%d - user #%d saved their drawing (%d added, %d removed)', $media_id, $actor, $added, $removed ) );
				$row['marks_json'] = $json;
				return self::view_of( $row, $actor );
			}
			if ( false === $n ) {
				error_log( sprintf( 'ZDZ_User_Media marks: media #%d - the database refused a drawing (%s)', $media_id, $wpdb->last_error ) );
				return new WP_Error( 'zdz_media_marks_unready', __( 'The drawing could not be saved just now. Try again in a minute.', 'zorderz' ) );
			}
			// Someone else's drawing on this photo changed in between: read again and put ours on top of theirs.
		}
		return new WP_Error( 'zdz_media_marks_busy', __( 'Several people are saving drawings on this photo right now. Tap Save again.', 'zorderz' ), array( 'view' => self::view_of( (array) self::mark_row( $media_id, true ), $actor ) ) );
	}

	/** Of the people asked for, those who use the app and may see this photo (a drawing is never shared wider). */
	private static function sharable_ids( array $row, array $ids, int $actor ): array {
		$out = array();
		foreach ( array_values( array_unique( array_map( 'intval', $ids ) ) ) as $u ) {
			if ( $u > 0 && $u !== $actor && self::uses_app( $u ) && ! ZDZ_Media_Marks::is_kiosk( $u ) && self::may_see_photo( $u, $row ) ) {
				$out[] = $u;
			}
		}
		return array_slice( $out, 0, ZDZ_Media_Marks::MAX_PEOPLE );   // filtered first, then capped
	}

	/** A person who uses the app (the tier ladder: viewer and up), never a customer or a bare subscriber account. */
	public static function uses_app( int $uid ): bool {
		if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) ) {
			return in_array( ZDZ_Capabilities::tier_of( $uid ), array( 'viewer', 'staff', 'admin' ), true );
		}
		return function_exists( 'user_can' ) && ( user_can( $uid, 'zdz_access_app' ) || user_can( $uid, 'manage_options' ) );
	}

	/**
	 * Who sees MY drawing on a photo: 'private' (only me), 'team' (everyone who can see the photo), 'people' (the people
	 * named, each of whom must be able to see the photo) or 'project' (that Project's people; only for a drawing made in
	 * one). Retried like a save when someone else's drawing changed in between.
	 */
	public static function share_layer( int $media_id, int $actor, string $share, array $people ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		for ( $try = 0; $try < 4; $try++ ) {
			$row = self::mark_row( $media_id, true );
			if ( ! $row || ! self::viewer_may_mark( $actor, $row ) ) {
				return new WP_Error( 'zdz_media_marks_denied', __( 'You can share only your own drawing on a photo you can see.', 'zorderz' ) );
			}
			$raw = $row['marks_json'] ?? null;
			$doc = ZDZ_Media_Marks::decode( $raw );
			$ids = 'people' === $share ? self::sharable_ids( $row, $people, $actor ) : array();
			if ( 'people' === $share && $people && ! $ids ) {
				return new WP_Error( 'zdz_media_marks_bad', __( 'None of those people can see this photo.', 'zorderz' ) );
			}
			$next = ZDZ_Media_Marks::with_share( $doc, $actor, $share, $ids );
			if ( is_wp_error( $next ) ) {
				return $next;
			}
			if ( null === $raw || '' === $raw ) {
				return new WP_Error( 'zdz_media_marks_none', __( 'Draw on the photo first; then you can share your drawing.', 'zorderz' ) );
			}
			$json = wp_json_encode( $next );
			$n    = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET marks_json = %s WHERE id = %d AND BINARY marks_json = %s", $json, $media_id, (string) $raw ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus the class constant TABLE
			if ( $n || $json === (string) $raw ) {
				$row['marks_json'] = $json;
				return self::view_of( $row, $actor );
			}
			if ( false === $n ) {
				return new WP_Error( 'zdz_media_marks_unready', __( 'The change could not be saved just now. Try again in a minute.', 'zorderz' ) );
			}
		}
		return new WP_Error( 'zdz_media_marks_busy', __( 'Several people are saving drawings on this photo right now. Try again.', 'zorderz' ) );
	}

	/** The people MY drawing on a photo can be shared with: app users who may see the photo (name and initials). */
	public static function share_people( int $media_id, int $actor ) {
		$row = self::mark_row( $media_id, false );
		if ( ! $row || ! self::viewer_may_mark( $actor, $row ) ) {
			return new WP_Error( 'zdz_media_marks_denied', __( 'That photo is not one you can see.', 'zorderz' ) );
		}
		$ids = function_exists( 'get_users' ) ? array_map( 'intval', (array) get_users( array( 'fields' => 'ID', 'number' => 200 ) ) ) : array();
		$out = array();
		foreach ( self::sharable_ids( $row, $ids, $actor ) as $u ) {
			$out[] = ZDZ_Media_Marks::person( $u );
		}
		usort( $out, function ( $a, $b ) {
			return strcasecmp( $a['name'], $b['name'] );
		} );
		return array( 'people' => array_slice( $out, 0, 100 ) );
	}

	/**
	 * How many drawings and notes this viewer sees on each of these photos, and whether they may draw. One query.
	 * [ media_id => [ marks_n, notes_n, can_mark ] ]
	 *
	 * $vouched: the caller already decided, server side, that this viewer may see every one of these photos (the jobs app's
	 * resolver chose them for this viewer - a private work photo at the Project's address included, which the viewer
	 * then opens through a signed grant on the request). Without it each photo is checked here. Either way a layer's
	 * own sharing still decides whether it shows; vouching widens nothing beyond the photo the caller already shows.
	 */
	public static function marks_for_ids( array $ids, int $viewer_id, bool $vouched = false ): array {
		$rows = self::rows_for_marks( $ids );
		$out  = array();
		foreach ( $rows as $r ) {
			$seen = $viewer_id > 0 && ( $vouched || self::may_see_photo( $viewer_id, $r ) );
			list( $n, $notes ) = $seen ? ZDZ_Media_Marks::counts_for( $r['marks_json'] ?? null, $viewer_id, $r ) : array( 0, 0 );
			$out[ (int) $r['id'] ] = array(
				'marks_n'  => $n,
				'notes_n'  => $notes,
				'can_mark' => $seen && self::markable( $r ) && ! ZDZ_Media_Marks::is_kiosk( $viewer_id ) && self::uses_app( $viewer_id ),
			);
		}
		return $out;
	}

	/**
	 * Every note this viewer may read on these photos (Projects gathers the notes in its photos), by the one rule that
	 * decides who sees a drawing ($vouched as for marks_for_ids). Each: media_id, id, text, by (uid, for the server to
	 * place the writer on a Project), name, initials, at, mine, seen_as. Newest first.
	 */
	public static function notes_for_ids( array $ids, int $viewer_id, bool $vouched = false ): array {
		$out = array();
		foreach ( self::rows_for_marks( $ids ) as $r ) {
			if ( $viewer_id <= 0 || empty( $r['marks_json'] ) || ( ! $vouched && ! self::may_see_photo( $viewer_id, $r ) ) ) {
				continue;
			}
			foreach ( ZDZ_Media_Marks::notes_for( $r['marks_json'], $viewer_id, $r ) as $n ) {
				$out[] = $n;
			}
		}
		usort( $out, function ( $a, $b ) {
			return strcmp( (string) $b['at'], (string) $a['at'] );
		} );
		return $out;
	}

	private static function rows_for_marks( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), function ( $i ) { return $i > 0; } ) ) );
		if ( ! $ids ) {
			return array();
		}
		$ids  = array_slice( $ids, 0, 500 );
		$cols = 'id, user_id, privacy, media_type' . ( self::marks_ready() ? ', marks_json' : '' );
		return (array) $wpdb->get_results( "SELECT {$cols} FROM " . $wpdb->prefix . self::TABLE . ' WHERE id IN (' . implode( ',', $ids ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- {$cols} is a fixed column list; the table is $wpdb->prefix plus the class constant TABLE; $ids are positive integers (intval and filtered above)
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
	/* EXIF provenance helpers                                 */
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

	/* ═══ gated media serving (theme-infra fix) ═══
	 * Private media was previously handed to the browser as a raw, public wp-uploads
	 * URL — the `privacy` field only filtered LIST queries, not file access, so a
	 * private photo/sketch was retrievable by anyone who had/guessed the URL. Now the
	 * getters return a gated URL and the file streams ONLY to an authorized viewer.
	 * Access mapping: private → owner + admins (view_others_data);
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
		// A cache-buster, so an in-place edit (a rotate rewrites the file and its thumbnails) shows at once instead of
		// the browser's ten-minute private copy. serve() ignores `v`; it only changes the address when updated_at does.
		if ( ! empty( $row['updated_at'] ) ) { $args['v'] = (int) strtotime( (string) $row['updated_at'] ); }
		return add_query_arg( $args, home_url( '/' ) );
	}

	/** Rewrite a row's URLs to the gated proxy before it leaves the data layer. */
	private static function shape_out( array $row ): array {
		$tok = self::token_for( $row );      // mint once, then reuse for both URLs
		$row['share_token']   = $tok;
		$row['file_url']      = self::secure_url( $row, 'full' );
		$row['thumbnail_url'] = self::secure_url( $row, 'thumb' );
		// How many drawings, and notes, the person asking sees on it - never the lines themselves (a list of photos
		// stays small; the viewer fetches the drawings of the one photo it opens). The stored drawings stay here.
		if ( array_key_exists( 'marks_json', $row ) ) {
			$viewer = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
			list( $row['marks_n'], $row['notes_n'] ) = ( $viewer > 0 && class_exists( 'ZDZ_Media_Marks' ) ) ? ZDZ_Media_Marks::counts_for( $row['marks_json'], $viewer, $row ) : array( 0, 0 );
			unset( $row['marks_json'] );
		}
		return $row;
	}

	/**
	 * Cross-user visibility predicate — "may this viewer see OTHER users' data?"
	 *
	 * v1.6.2: promoted from private to PUBLIC as a sanctioned shared
	 * boundary so every app resolves cross-user visibility through this ONE ladder
	 * (guard the call with method_exists()) instead of forking a drifted copy
	 * that silently omits the view_others_data rung. This is a VISIBILITY change only;
	 * the ladder and its verdicts are byte-for-byte unchanged (no behaviour widened):
	 *   1) manage_options (owner/admin override)  →  true
	 *   2) ZDZ_Data_Permissions::can($uid,'view_others_data')  (the capability rung)
	 *   3) last-resort role fallback when Data Permissions is absent.
	 * Capability/trait based, never a role-slug literal for the security decision.
	 * It fails closed, so publishing it can only GRANT the already-data-permitted
	 * set, never invent a capability. Consumers keep their own GPS/property fence.
	 */
	public static function viewer_can_view_others( int $uid ): bool {
		if ( $uid <= 0 ) { return false; }
		if ( user_can( $uid, 'manage_options' ) ) { return true; }
		if ( class_exists( 'ZDZ_Data_Permissions' ) && method_exists( 'ZDZ_Data_Permissions', 'can' ) ) {
			return (bool) ZDZ_Data_Permissions::can( $uid, 'view_others_data' );
		}
		$u = get_userdata( $uid );
		return $u && is_array( $u->roles ) && (bool) array_intersect( array( 'zdz_owner', 'zdz_admin' ), $u->roles );
	}

	/**
	 * The ONE access predicate for a private media row. Every read path — serve() (thumb AND
	 * full), the "Shared With Me" scope, the message card and the drawings — resolves through
	 * this, so a per-recipient grant can never be honored on one surface and missed on another.
	 * team/public rows never call this (they only require a signed-in viewer).
	 *
	 * owner  OR  admin tier (viewer_can_view_others)  OR  a live share grant  OR  an app's grant.
	 *
	 * An app may grant one viewer one private row on the `zdz_media_viewer_can_view` filter (the
	 * jobs app does, for a work photo taken at the address of a Project the viewer may see,
	 * through a short signed grant on the request: zprj_g / zprj_e). Asked only when every rule
	 * above has said no; a filter can only add a yes for a signed-in viewer.
	 */
	public static function viewer_can_view( int $viewer_id, array $row ): bool {
		$owner = (int) ( $row['user_id'] ?? 0 );
		if ( $viewer_id > 0 && $owner === $viewer_id ) { return true; }
		if ( self::viewer_can_view_others( $viewer_id ) ) { return true; }
		$mid = (int) ( $row['id'] ?? 0 );
		if ( $mid > 0 && $viewer_id > 0 && class_exists( 'ZDZ_Media_Share' ) && ZDZ_Media_Share::viewer_has_grant( $viewer_id, $mid ) ) { return true; }
		if ( $viewer_id > 0 && function_exists( 'apply_filters' ) ) {
			return true === (bool) apply_filters( 'zdz_media_viewer_can_view', false, $viewer_id, $row );
		}
		return false;
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
			"SELECT id, user_id, privacy, wp_attachment_id, file_url, thumbnail_url, filename, share_token, created_at FROM " . $wpdb->prefix . self::TABLE . " WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the table is $wpdb->prefix plus the class constant TABLE
			$id
		), ARRAY_A );
		if ( ! $row ) { $fail(); }

		// Constant-time token check.
		$expected = (string) ( $row['share_token'] ?? '' );
		if ( '' === $expected || ! hash_equals( $expected, $tok ) ) { $fail(); }

		// Access by privacy: private -> viewer_can_view() (owner, admins, a live share grant, an
		// app's grant); team/public -> any logged-in. The ONE predicate gates both the thumb and
		// the full byte path, so a shared item is never reachable on one and refused on the other.
		$uid      = (int) get_current_user_id();
		$is_owner = ( $uid > 0 && (int) $row['user_id'] === $uid );
		if ( 'private' === (string) $row['privacy'] ) {
			if ( ! self::viewer_can_view( $uid, $row ) ) { $fail(); }
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
		// A Privacy View account gets a placeholder the photo's size and shape (or a photo an app vouches for, re-drawn
		// without its data), never the file, its name or its location. serve_photo() sends it and stops.
		if ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on() ) {
			ZDZ_Privacy_View::serve_photo( $path, (string) $mime, $row );
		}
		$name = sanitize_file_name( (string) ( $row['filename'] ?: ( 'media-' . (int) $row['id'] ) ) );
		while ( ob_get_level() > 0 ) { ob_end_clean(); }
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff', true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: no-referrer', true );
		// A private item served to a NON-owner (an admin or a share grantee) must not sit in a
		// browser cache, or a REVOKED share could keep rendering from cache after access is
		// pulled. Owner and team/public keep the short cache.
		$no_store = ( 'private' === (string) $row['privacy'] && ! $is_owner );
		header( 'Cache-Control: ' . ( $no_store ? 'private, no-store, must-revalidate' : 'private, max-age=600, must-revalidate' ), true );
		readfile( $path );
		exit;
	}

	/**
	 * Rotate a photo's stored file 90° at a time, IN PLACE, so the new orientation is the one served everywhere.
	 * Browser captures carry no EXIF, so the orientation must live in the pixels: the attachment's original is
	 * re-encoded and every intermediate size regenerated. The row's owner or someone who may see others' photos
	 * (viewer_can_view_others()) only, re-checked here whatever the caller checked; never the shared device unless it
	 * owns the row; photos only (a WordPress image attachment; a video row is refused). updated_at moves, so
	 * secure_url()'s cache-buster refreshes the image at once, and the drawings on it turn with it in the same UPDATE.
	 *
	 * @param int $media_id
	 * @param int $viewer_id The person rotating it.
	 * @param int $cw        Clockwise degrees to apply now: 90, 180 or 270.
	 * @return array|bool    Fresh gated URLs [ 'file_url', 'thumbnail_url', 'marks_n', 'notes_n' ], or false.
	 */
	public static function rotate_photo( int $media_id, int $viewer_id, int $cw ) {
		global $wpdb;
		$cw = ( (int) $cw % 360 + 360 ) % 360;
		if ( ! in_array( $cw, array( 90, 180, 270 ), true ) || $viewer_id <= 0 ) { return false; }

		$row = self::get_by_id( $media_id );
		if ( ! $row ) { return false; }

		// Owner OR the administrator rung (the one sanctioned ladder), re-checked here: defense in depth.
		$is_owner = ( (int) ( $row['user_id'] ?? 0 ) === $viewer_id );
		if ( ! $is_owner && ! self::viewer_can_view_others( $viewer_id ) ) { return false; }

		// Photos only: a WordPress image attachment (a video's file is its .mp4).
		$att = (int) ( $row['wp_attachment_id'] ?? 0 );
		if ( $att <= 0 || ! function_exists( 'wp_attachment_is_image' ) || ! wp_attachment_is_image( $att ) ) { return false; }

		$file = (string) get_attached_file( $att );
		if ( '' === $file || ! file_exists( $file ) ) { return false; }

		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-includes/class-wp-image-editor.php';
		}
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) { return false; }

		// WP_Image_Editor::rotate() turns COUNTER-clockwise; this API is clockwise, so apply (360 - cw).
		$r = $editor->rotate( ( 360 - $cw ) % 360 );
		if ( is_wp_error( $r ) ) { return false; }

		$saved = $editor->save( $file );   // overwrite the attachment's original in place
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) { return false; }

		// Every intermediate size from the turned original, so every place that shows the photo turns with it.
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$new_meta = wp_generate_attachment_metadata( $att, $file );
		if ( is_array( $new_meta ) && ! empty( $new_meta ) ) {
			wp_update_attachment_metadata( $att, $new_meta );
		}

		// Who rotated it and when (beside the tag-edit record), and updated_at for the cache-buster. Every person's
		// drawing on the photo turns with it IN THE SAME UPDATE, which carries the drawings it read: one saved in that
		// instant is read again and turned too, so a drawing is never turned twice or left unturned.
		$when  = current_time( 'mysql' );
		$table = $wpdb->prefix . self::TABLE;
		$ready = class_exists( 'ZDZ_Media_Marks' ) && self::marks_ready();
		for ( $try = 0; $try < 4; $try++ ) {
			$cur  = $wpdb->get_row( $wpdb->prepare(
				'SELECT meta_json' . ( $ready ? ', marks_json' : '' ) . " FROM {$table} WHERE id = %d", $media_id // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the column list is fixed text; {$table} is $wpdb->prefix plus the class constant TABLE
			), ARRAY_A );
			$meta = array();
			if ( $cur && ! empty( $cur['meta_json'] ) ) { $d = json_decode( (string) $cur['meta_json'], true ); if ( is_array( $d ) ) { $meta = $d; } }
			$meta['last_rotated_by'] = $viewer_id;
			$meta['last_rotated_at'] = $when;
			$meta['rotate_rev']      = (int) ( $meta['rotate_rev'] ?? 0 ) + 1;
			$raw = $ready && $cur ? $cur['marks_json'] : null;
			if ( ! $ready ) {
				$wpdb->update( $table, array( 'meta_json' => wp_json_encode( $meta ), 'updated_at' => $when ), array( 'id' => $media_id ), array( '%s', '%s' ), array( '%d' ) );
				break;
			}
			if ( null === $raw || '' === $raw ) {
				// No drawings yet, unless a first one is saved in this instant: then read again and turn it.
				$n = $wpdb->query( $wpdb->prepare(
					"UPDATE {$table} SET meta_json = %s, updated_at = %s WHERE id = %d AND ( marks_json IS NULL OR marks_json = '' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus the class constant TABLE
					wp_json_encode( $meta ), $when, $media_id
				) );
			} else {
				$turned = wp_json_encode( ZDZ_Media_Marks::rotate( ZDZ_Media_Marks::decode( $raw ), $cw ) );
				$n      = $wpdb->query( $wpdb->prepare(
					"UPDATE {$table} SET meta_json = %s, updated_at = %s, marks_json = %s WHERE id = %d AND BINARY marks_json = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus the class constant TABLE
					wp_json_encode( $meta ), $when, $turned, $media_id, (string) $raw
				) );
			}
			if ( $n ) { break; }
			if ( 3 === $try ) {
				// The photo IS turned, so that is recorded whatever happened; the drawings stay as saved, and the log says why.
				$wpdb->update( $table, array( 'meta_json' => wp_json_encode( $meta ), 'updated_at' => $when ), array( 'id' => $media_id ), array( '%s', '%s' ), array( '%d' ) );
				error_log( false === $n
					? sprintf( 'ZDZ_User_Media marks: media #%d was rotated %d but the database refused the update (%s); drawings NOT turned', $media_id, $cw, $wpdb->last_error )
					: sprintf( 'ZDZ_User_Media marks: media #%d was rotated %d but its drawings changed meanwhile; drawings NOT turned', $media_id, $cw ) );
			}
		}

		// Fresh gated URLs (secure_url() adds `v` from the new updated_at).
		$fresh = self::get_by_id( $media_id );
		if ( ! is_array( $fresh ) ) { return true; }
		return array(
			'file_url'      => self::secure_url( $fresh, 'full' ),
			'thumbnail_url' => self::secure_url( $fresh, 'thumb' ),
			'marks_n'       => (int) ( $fresh['marks_n'] ?? 0 ),   // the drawings turned with it; the viewer reads them again
			'notes_n'       => (int) ( $fresh['notes_n'] ?? 0 ),
		);
	}
}

add_action( 'template_redirect', array( 'ZDZ_User_Media', 'maybe_serve' ) );
