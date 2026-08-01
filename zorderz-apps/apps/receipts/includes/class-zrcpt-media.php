<?php
/**
 * ZRCPT Media — pulls the photos the tech ALREADY captured.
 *
 * v3.1.0 north-star: the Receipt app should work like the Prep app. The tech
 * types a name / number / phone, and everything else is pulled in for them —
 * including the installation photos. We do NOT ask them to upload anything by
 * default; we assume the photos are already in Zorderz (captured via the
 * ts-camera app, which writes every shot into the shared ZDZ_User_Media store
 * with EXIF provenance — capture time + GPS — preserved).
 *
 * This class is the bridge to that store. It:
 *   1. Reads the current user's photos out of ZDZ_User_Media (the single
 *      source of truth — no parallel tables, per the V9.9.1 program).
 *   2. Groups them into CAPTURE SESSIONS — a "session" is one trip to one
 *      property on one day. Sessions are split on a time gap (different day /
 *      several hours apart) and, when GPS is present, on location.
 *
 *      v3.4.0 — UPLOAD-BATCH TAGS ARE AUTHORITATIVE. Photos added through the
 *      Media app's bulk upload carry meta_json.batch.id (one id per "Add
 *      Photos" action — a HUMAN grouped those photos by uploading them
 *      together). That tag now beats every time/GPS inference: one batch is
 *      always exactly one session, two different batches NEVER merge, and
 *      tagged photos never mix with untagged ones. This kills the bug where
 *      two different installs photographed at the same TIME OF DAY (duplicate
 *      EXIF timestamps from different installers) fused into one set and
 *      dragged unrelated photos into a receipt. Untagged photos (live ts-camera
 *      shots, legacy rows) keep the time/GPS heuristic below, unchanged.
 *   3. Applies the business rule the shop actually uses:
 *
 *        "Installation photos are different from pre-photos, because pre-photos
 *         were taken on a different day. If there are two sets of photos from
 *         the same house, the MOST RECENT set is the installation set, and the
 *         set before it was probably the estimate walkthrough."
 *
 *      So the newest session for a property is tagged role = 'install', the one
 *      before it role = 'before' (estimate), older ones role = 'older'.
 *
 *   4. Surfaces a captured_at-derived install DATE so the form can pre-fill it
 *      (the tech can still override). The date comes from EXIF, which the theme
 *      records read-only at ingest — so it is forensic, not guessed.
 *
 * Everything is scoped to the current user and is therefore kiosk-safe by the
 * same logic the Media app relies on: under the V9.9 kiosk model the request
 * genuinely *is* the ts_general user, so "your photos" is correctly empty/limited.
 *
 * Degrades gracefully: if the theme's ZDZ_User_Media class is not present (older
 * theme), every method returns an empty result and the caller falls back to the
 * manual upload path. Nothing here is required for the legacy flow to work.
 *
 * @since 3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ZRCPT_Media {

	/**
	 * A new capture session starts when two consecutive photos are more than
	 * this many seconds apart. 4 hours comfortably separates a morning estimate
	 * from an afternoon install on the same day, while keeping a single
	 * walk-around (shots minutes apart) together.
	 */
	const SESSION_GAP_SECONDS = 4 * 3600;

	/**
	 * If two photos are on different calendar days they are ALWAYS different
	 * sessions, regardless of the raw second gap (a 9pm shot and a 6am shot are
	 * ~9h apart but obviously different visits; this guards the inverse — two
	 * shots 3h apart but straddling midnight).
	 */
	const SPLIT_ON_CALENDAR_DAY = true;

	/**
	 * GPS clustering radius (meters). Two photos farther apart than this are
	 * treated as different properties even within the same time window. Only
	 * applied when BOTH photos carry GPS. ~120 m tolerates a large lot / a tech
	 * shooting from the street vs. the back fence, without merging neighbours.
	 */
	const GEO_CLUSTER_METERS = 120.0;

	/** Is the shared media store available on this install? */
	public static function is_available(): bool {
		return class_exists( 'ZDZ_User_Media' )
			&& method_exists( 'ZDZ_User_Media', 'get_user_media' );
	}

	/* ================================================================
	 * PUBLIC API
	 * ================================================================ */

	/**
	 * Get the current user's recent photos, grouped into capture sessions and
	 * role-tagged (install / before / older).
	 *
	 * @param int   $user_id  WordPress user ID.
	 * @param array $opts {
	 *     @type int    $lookback_days   Only consider photos captured/created in
	 *                                   the last N days (default 120). Keeps the
	 *                                   picker short and relevant.
	 *     @type int    $max_photos      Hard cap on photos scanned (default 400).
	 *     @type float  $near_lat        Optional property latitude to gate on.
	 *     @type float  $near_lng        Optional property longitude to gate on.
	 *     @type float  $near_radius_m   Gate radius in meters (default 250).
	 * }
	 * @return array {
	 *     @type bool   available   Whether the store was reachable.
	 *     @type array  sessions    List of sessions, newest first. Each:
	 *         {
	 *           id            string  Stable per-result id ("sess-0", ...).
	 *           role          string  'install' | 'before' | 'older'.
	 *           captured_at   string  Best capture timestamp for the session.
	 *           date_display  string  Human date (e.g. "May 27, 2026").
	 *           photo_count   int
	 *           has_gps       bool
	 *           gps_lat       float|null  Session centroid latitude.
	 *           gps_lng       float|null  Session centroid longitude.
	 *           photos        array   [ { media_id, attachment_id, url, thumb,
	 *                                     captured_at, gps_lat, gps_lng, filename } ]
	 *         }
	 *     @type int    total_photos
	 * }
	 */
	public static function get_sessions_for_user( int $user_id, array $opts = [] ): array {
		$out = [ 'available' => false, 'sessions' => [], 'total_photos' => 0 ];
		if ( $user_id <= 0 || ! self::is_available() ) {
			return $out;
		}
		$out['available'] = true;

		$lookback_days = max( 1, (int) ( $opts['lookback_days'] ?? 120 ) );
		$max_photos    = max( 1, (int) ( $opts['max_photos'] ?? 400 ) );

		// Pull the user's photos. ZDZ_User_Media orders by created_at DESC and
		// filters by media_type. We want photographic captures only — sketches
		// and documents are different media_types and never installation photos.
		$rows = ZDZ_User_Media::get_user_media( $user_id, [
			'media_type' => 'photo',
			'limit'      => $max_photos,
			'order'      => 'DESC',
		] );

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return $out;
		}

		// Normalize each row to a photo we can reason about. Prefer EXIF
		// captured_at (the real moment the photo was taken); fall back to the
		// upload time only when EXIF is absent, so a batch uploaded late still
		// lands somewhere sensible.
		$cutoff_ts = time() - ( $lookback_days * 86400 );
		$photos = [];
		foreach ( $rows as $r ) {
			$cap = self::row_timestamp( $r );
			if ( $cap === null ) continue;
			if ( $cap < $cutoff_ts ) continue; // outside the lookback window

			$lat = isset( $r['gps_lat'] ) && $r['gps_lat'] !== null && $r['gps_lat'] !== '' ? (float) $r['gps_lat'] : null;
			$lng = isset( $r['gps_lng'] ) && $r['gps_lng'] !== null && $r['gps_lng'] !== '' ? (float) $r['gps_lng'] : null;

			// v3.4.0 — the Media app's upload-batch tag (meta_json.batch).
			// Present on bulk-uploaded photos; '' for camera shots/legacy rows.
			$batch_id = ''; $batch_note = '';
			$mj = trim( (string) ( $r['meta_json'] ?? '' ) );
			if ( $mj !== '' ) {
				$meta = json_decode( $mj, true );
				if ( is_array( $meta ) && ! empty( $meta['batch'] ) && is_array( $meta['batch'] ) ) {
					$batch_id   = (string) ( $meta['batch']['id'] ?? '' );
					$batch_note = (string) ( $meta['batch']['note'] ?? '' );
				}
			}

			// v3.8.0 — RESOLVE A DISPLAYABLE THUMB (the "broken ? boxes" fix).
			// thumbnail_url can be empty ('' does NOT trip the old ?? fallback),
			// stale (file deleted/regenerated → 404), or the row's file_url can
			// be a HEIC most browsers won't render. When we have the real WP
			// attachment id, ask WordPress for a genuine intermediate size —
			// that's a browser-safe JPEG/WebP wherever thumbnails exist. The
			// widget adds a JS onerror fallback chain on top, so a photo NEVER
			// renders as the browser's broken-image icon.
			$aid   = (int) ( $r['wp_attachment_id'] ?? 0 );
			$url   = (string) ( $r['file_url'] ?? '' );
			$thumb = trim( (string) ( $r['thumbnail_url'] ?? '' ) );
			$is_heic = (bool) preg_match( '/\.(heic|heif)(\?|#|$)/i', $url );
			if ( ( $thumb === '' || $thumb === $url ) && $aid > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
				$wp_thumb = wp_get_attachment_image_url( $aid, 'medium' );
				if ( ! $wp_thumb ) { $wp_thumb = wp_get_attachment_image_url( $aid, 'thumbnail' ); }
				if ( $wp_thumb ) { $thumb = (string) $wp_thumb; }
			}
			if ( $thumb === '' ) { $thumb = $url; }
			// A HEIC main URL breaks <img> in most browsers AND in the receipt
			// gallery — swap in a browser-safe attachment rendition when one
			// exists (best-effort; leaves the URL alone otherwise).
			if ( $is_heic && $aid > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
				$safe = wp_get_attachment_image_url( $aid, 'large' );
				if ( ! $safe ) { $safe = wp_get_attachment_image_url( $aid, 'full' ); }
				if ( $safe && ! preg_match( '/\.(heic|heif)(\?|#|$)/i', (string) $safe ) ) {
					$url = (string) $safe;
				}
			}

			$photos[] = [
				'media_id'      => (int) ( $r['id'] ?? 0 ),
				// The real WordPress attachment id (distinct from media_id, which
				// is the ZDZ_User_Media ROW id). The receipt generator needs THIS
				// to record provenance (_source_media_ids) and to recognize the
				// set as library captures — without re-parenting them.
				'attachment_id' => $aid,
				'url'           => $url,
				'thumb'         => $thumb,
				'filename'      => (string) ( $r['filename'] ?? '' ),
				'captured_at'   => gmdate( 'Y-m-d H:i:s', $cap ),
				'_ts'           => $cap,
				'gps_lat'       => $lat,
				'gps_lng'       => $lng,
				'_has_exif'     => self::row_has_exif_time( $r ),
				'batch_id'      => $batch_id,
				'batch_note'    => $batch_note,
			];
		}

		$out['total_photos'] = count( $photos );
		if ( empty( $photos ) ) {
			return $out;
		}

		// Optional property gate: if the caller knows where the job is (GPS from
		// the customer record), drop photos clearly somewhere else. Photos with
		// NO GPS are kept — we can't prove they're wrong, and many phones omit it.
		if ( isset( $opts['near_lat'], $opts['near_lng'] ) && is_numeric( $opts['near_lat'] ) && is_numeric( $opts['near_lng'] ) ) {
			$radius = (float) ( $opts['near_radius_m'] ?? 250.0 );
			$nlat   = (float) $opts['near_lat'];
			$nlng   = (float) $opts['near_lng'];
			$photos = array_values( array_filter( $photos, function ( $p ) use ( $nlat, $nlng, $radius ) {
				if ( $p['gps_lat'] === null || $p['gps_lng'] === null ) return true; // unknown → keep
				return self::haversine_m( $p['gps_lat'], $p['gps_lng'], $nlat, $nlng ) <= $radius;
			} ) );
			if ( empty( $photos ) ) {
				return $out; // gated everything out — caller shows "no nearby photos"
			}
		}

		// Newest first for sessionization.
		usort( $photos, function ( $a, $b ) { return $b['_ts'] <=> $a['_ts']; } );

		$sessions = self::sessionize( $photos );

		// Role-tag: newest session = install, the one before = before/estimate.
		foreach ( $sessions as $i => &$s ) {
			if ( $i === 0 )      $s['role'] = 'install';
			elseif ( $i === 1 )  $s['role'] = 'before';
			else                 $s['role'] = 'older';
		}
		unset( $s );

		$out['sessions'] = $sessions;
		return $out;
	}

	/**
	 * Convenience: resolve the photos for a chosen session id out of a sessions
	 * payload, returned as the {url,id} shape the generator expects.
	 *
	 * @param array  $sessions   The 'sessions' array from get_sessions_for_user().
	 * @param string $session_id e.g. "sess-0".
	 * @return array [ { url, id } ]
	 */
	public static function photos_for_session( array $sessions, string $session_id ): array {
		foreach ( $sessions as $s ) {
			if ( ( $s['id'] ?? '' ) === $session_id ) {
				return array_map( function ( $p ) {
					return [ 'url' => $p['url'], 'id' => $p['media_id'] ];
				}, $s['photos'] ?? [] );
			}
		}
		return [];
	}

	/* ================================================================
	 * SESSIONIZATION
	 * ================================================================ */

	/**
	 * Group a newest-first photo list into sessions. Walks consecutively and
	 * starts a new session when the time gap is large, the calendar day changes,
	 * or (with GPS on both) the location jumps.
	 *
	 * @param array $photos Newest-first normalized photos.
	 * @return array Sessions, newest first, each with centroid + display fields.
	 */
	private static function sessionize( array $photos ): array {
		/*
		 * v3.4.0 — BATCHES FIRST. Photos bulk-uploaded through the Media app
		 * share meta_json.batch.id: a human grouped them by uploading them
		 * together, so that tag is AUTHORITATIVE.
		 *   • One batch  = exactly one session, always — even if its photos'
		 *     EXIF times span hours/days (the uploader said they belong
		 *     together).
		 *   • Two batches NEVER merge — even when their timestamps coincide
		 *     (THE bug: different installs photographed at the same time of
		 *     day fused into one set).
		 *   • Tagged photos never mix with untagged ones.
		 * Untagged photos (live camera shots, legacy rows) still go through
		 * the original newest-first time/GPS walk, unchanged.
		 */
		$batches = [];   // batch_id => photos
		$loose   = [];   // untagged
		foreach ( $photos as $p ) {
			$bid = (string) ( $p['batch_id'] ?? '' );
			if ( $bid !== '' ) { $batches[ $bid ][] = $p; }
			else               { $loose[] = $p; }
		}

		// Time/GPS walk over the untagged photos (newest-first, as before).
		$groups  = [];
		$current = [];
		$flush = function () use ( &$groups, &$current ) {
			if ( empty( $current ) ) return;
			$groups[] = $current;
			$current  = [];
		};
		$prev = null;
		foreach ( $loose as $p ) {
			if ( $prev !== null && self::is_new_session( $prev, $p ) ) {
				$flush();
			}
			$current[] = $p;
			$prev = $p;
		}
		$flush();

		// Each batch is one ready-made group.
		foreach ( $batches as $bp ) {
			$groups[] = $bp;
		}

		// Order all groups newest-first by their newest photo, then finalize
		// with stable ids (sess-0 = newest, which the caller role-tags
		// 'install').
		usort( $groups, function ( $a, $b ) {
			$na = 0; foreach ( $a as $p ) { if ( $p['_ts'] > $na ) $na = $p['_ts']; }
			$nb = 0; foreach ( $b as $p ) { if ( $p['_ts'] > $nb ) $nb = $p['_ts']; }
			return $nb <=> $na;
		} );

		$sessions = [];
		foreach ( $groups as $i => $g ) {
			// Keep each group internally newest-first for display.
			usort( $g, function ( $a, $b ) { return $b['_ts'] <=> $a['_ts']; } );
			$sessions[] = self::finalize_session( $g, $i );
		}

		return $sessions;
	}

	/**
	 * Decide whether $b (older, since we walk newest-first) belongs to a new
	 * session relative to $a (newer).
	 */
	private static function is_new_session( array $a, array $b ): bool {
		// v3.4.0 — the upload-batch tag overrides every heuristic: same batch
		// → same session (never split), different/missing batch → new session.
		$ba = (string) ( $a['batch_id'] ?? '' );
		$bb = (string) ( $b['batch_id'] ?? '' );
		if ( $ba !== '' || $bb !== '' ) {
			return $ba !== $bb;
		}

		$gap = abs( $a['_ts'] - $b['_ts'] );

		if ( $gap > self::SESSION_GAP_SECONDS ) return true;

		if ( self::SPLIT_ON_CALENDAR_DAY ) {
			if ( gmdate( 'Y-m-d', $a['_ts'] ) !== gmdate( 'Y-m-d', $b['_ts'] ) ) return true;
		}

		// Location split only when we actually have coordinates for both.
		if ( $a['gps_lat'] !== null && $a['gps_lng'] !== null
			&& $b['gps_lat'] !== null && $b['gps_lng'] !== null ) {
			$d = self::haversine_m( $a['gps_lat'], $a['gps_lng'], $b['gps_lat'], $b['gps_lng'] );
			if ( $d > self::GEO_CLUSTER_METERS ) return true;
		}

		return false;
	}

	/**
	 * Compute centroid + display fields for a finished session.
	 *
	 * @param array $photos Photos in the session (newest-first within the group).
	 * @param int   $index  Zero-based session index (for the stable id).
	 */
	private static function finalize_session( array $photos, int $index ): array {
		$lat_sum = 0.0; $lng_sum = 0.0; $geo_n = 0;
		$newest_ts = 0;
		foreach ( $photos as $p ) {
			if ( $p['gps_lat'] !== null && $p['gps_lng'] !== null ) {
				$lat_sum += $p['gps_lat']; $lng_sum += $p['gps_lng']; $geo_n++;
			}
			if ( $p['_ts'] > $newest_ts ) $newest_ts = $p['_ts'];
		}

		$has_gps = $geo_n > 0;
		$c_lat   = $has_gps ? round( $lat_sum / $geo_n, 7 ) : null;
		$c_lng   = $has_gps ? round( $lng_sum / $geo_n, 7 ) : null;

		// v3.4.0 — batch identity (set when this group came from one Media-app
		// upload batch; every photo in such a group shares the same id).
		$batch_id = ''; $batch_note = '';
		foreach ( $photos as $p ) {
			if ( ! empty( $p['batch_id'] ) ) {
				$batch_id   = (string) $p['batch_id'];
				$batch_note = (string) ( $p['batch_note'] ?? '' );
				break;
			}
		}

		// Strip internal keys from the photos we expose.
		$clean = array_map( function ( $p ) {
			return [
				'media_id'      => $p['media_id'],
				'attachment_id' => $p['attachment_id'] ?? 0,
				'url'           => $p['url'],
				'thumb'         => $p['thumb'],
				'captured_at'   => $p['captured_at'],
				'gps_lat'       => $p['gps_lat'],
				'gps_lng'       => $p['gps_lng'],
				'filename'      => $p['filename'],
			];
		}, $photos );

		return [
			'id'           => 'sess-' . $index,
			'role'         => 'older', // overwritten by caller
			'captured_at'  => gmdate( 'Y-m-d H:i:s', $newest_ts ),
			'date_display' => self::format_date( $newest_ts ),
			'date_input'   => gmdate( 'Y-m-d', $newest_ts ), // for <input type=date>
			'photo_count'  => count( $clean ),
			'has_gps'      => $has_gps,
			'gps_lat'      => $c_lat,
			'gps_lng'      => $c_lng,
			// v3.4.0 — lets the widget show WHY this set is grouped (a chip +
			// the upload note) and distinguishes human-tagged sets from
			// heuristic ones.
			'is_batch'     => ( $batch_id !== '' ),
			'batch_note'   => $batch_note,
			'photos'       => $clean,
		];
	}

	/* ================================================================
	 * SMALL HELPERS
	 * ================================================================ */

	/**
	 * Best timestamp for a media row, as a Unix epoch. EXIF captured_at wins;
	 * otherwise created_at (upload time). Returns null if neither parses.
	 */
	private static function row_timestamp( array $r ): ?int {
		$cap = trim( (string) ( $r['captured_at'] ?? '' ) );
		if ( $cap !== '' && $cap !== '0000-00-00 00:00:00' ) {
			$ts = strtotime( $cap );
			if ( $ts ) return $ts;
		}
		$created = trim( (string) ( $r['created_at'] ?? '' ) );
		if ( $created !== '' ) {
			$ts = strtotime( $created );
			if ( $ts ) return $ts;
		}
		return null;
	}

	private static function row_has_exif_time( array $r ): bool {
		$cap = trim( (string) ( $r['captured_at'] ?? '' ) );
		return $cap !== '' && $cap !== '0000-00-00 00:00:00';
	}

	/** Format an epoch as "May 27, 2026". */
	private static function format_date( int $ts ): string {
		// Use the site's date format if it's set to something friendly; otherwise default.
		return gmdate( 'M j, Y', $ts );
	}

	/**
	 * Great-circle distance between two lat/lng points, in meters.
	 */
	private static function haversine_m( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$R = 6371000.0; // Earth radius in meters
		$dLat = deg2rad( $lat2 - $lat1 );
		$dLng = deg2rad( $lng2 - $lng1 );
		$a = sin( $dLat / 2 ) ** 2
			+ cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dLng / 2 ) ** 2;
		$c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
		return $R * $c;
	}
}
