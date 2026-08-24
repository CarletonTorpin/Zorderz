<?php
/**
 * Zorderz Jobs — finish photos.
 *
 * The mandatory geo-tagged finish photos a worker attaches when marking their part
 * of a job complete:
 *   1. Upload  (zjob_upload_photo) — receive one image + the browser's Geolocation
 *      fix, REJECT an image already uploaded (sha256 dedup — the anti-fraud check
 *      when the location fix can't confirm on-site), store it in the theme's Media
 *      Library (ZDZ_User_Media) tagged to the job + GPS, and return a token link.
 *   2. Serve   (/?zjob_photo=<id>&t=<token>) — a login-free serve confined to Jobs
 *      finish photos (source_app='jobs') so a CRM deep-link is clickable by anyone
 *      with the link (the unguessable token is the credential), WITHOUT weakening the
 *      theme's media privacy for other apps.
 *
 * GPS comes from navigator.geolocation at capture (NOT EXIF — mobile browsers strip
 * EXIF-GPS from web uploads).
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Photos {

	const SOURCE_APP = 'jobs';

	public static function init(): void {
		add_action( 'template_redirect', [ __CLASS__, 'serve' ] );
		add_action( 'wp_ajax_zjob_upload_photo', [ __CLASS__, 'ajax_upload' ] );
	}

	/** The theme's shared media table. */
	private static function media_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zdz_user_media';
	}

	/* =======================================================================
	 * UPLOAD
	 * ======================================================================= */

	public static function ajax_upload(): void {
		check_ajax_referer( ZJOB_NONCE, 'nonce' );
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			wp_send_json_error( [ 'message' => 'not_logged_in' ], 403 );
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			wp_send_json_error( [ 'message' => 'kiosk_forbidden' ], 403 );
		}

		$job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		$row    = ZJOB_Jobs::get( $job_id );
		if ( ! $row ) {
			wp_send_json_error( [ 'message' => 'bad_job' ], 400 );
		}
		$assignee = (int) ( $row['assigned_user_id'] ?? 0 );
		$is_admin = class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_admin( $uid );
		if ( $uid !== $assignee && ! $is_admin && ! ZJOB_Jobs::actor_can_manage( $uid, $row ) ) {
			wp_send_json_error( [ 'message' => 'not_permitted' ], 403 );
		}

		if ( empty( $_FILES['photo'] ) || empty( $_FILES['photo']['tmp_name'] ) || ! is_uploaded_file( $_FILES['photo']['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => 'no_file' ], 400 );
		}
		$tmp = $_FILES['photo']['tmp_name'];

		if ( false === @getimagesize( $tmp ) ) {
			wp_send_json_error( [ 'message' => 'not_image' ], 400 );
		}

		// Dedup: refuse an image already uploaded as a Jobs finish photo.
		$sha = (string) hash_file( 'sha256', $tmp );
		if ( self::hash_exists( $sha ) ) {
			wp_send_json_error( [ 'message' => 'duplicate', 'duplicate' => true ], 409 );
		}

		if ( ! class_exists( 'ZDZ_User_Media' ) || ! method_exists( 'ZDZ_User_Media', 'save' ) ) {
			wp_send_json_error( [ 'message' => 'media_unavailable' ], 500 );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$att_id = media_handle_upload( 'photo', 0 );
		if ( is_wp_error( $att_id ) ) {
			wp_send_json_error( [ 'message' => 'upload_failed', 'detail' => $att_id->get_error_message() ], 500 );
		}
		$file_url = (string) wp_get_attachment_url( $att_id );

		$lat      = ( isset( $_POST['gps_lat'] ) && $_POST['gps_lat'] !== '' ) ? (string) (float) $_POST['gps_lat'] : '';
		$lng      = ( isset( $_POST['gps_lng'] ) && $_POST['gps_lng'] !== '' ) ? (string) (float) $_POST['gps_lng'] : '';
		$acc      = ( isset( $_POST['gps_accuracy'] ) && $_POST['gps_accuracy'] !== '' ) ? max( 0, (int) $_POST['gps_accuracy'] ) : null;
		$captured = isset( $_POST['captured_at'] ) ? sanitize_text_field( wp_unslash( $_POST['captured_at'] ) ) : '';
		$verified = ( $lat !== '' && $lng !== '' && null !== $acc && $acc <= ZJOB_Jobs::gps_accuracy_max_m() );

		$saved = ZDZ_User_Media::save( [
			'user_id'          => $uid,
			'file_url'         => $file_url,
			'wp_attachment_id' => $att_id,
			'filename'         => sanitize_file_name( (string) ( $_FILES['photo']['name'] ?? ( 'job-' . $job_id . '.jpg' ) ) ),
			'media_type'       => 'photo',
			'source_app'       => self::SOURCE_APP,
			'source_ref'       => 'jobphoto:' . $job_id,
			'title'            => 'Finish photo - job #' . $job_id,
			'description'      => trim( (string) ( $row['component'] ?? '' ) . ' ' . (string) ( $row['customer_name'] ?? '' ) ),
			'privacy'          => 'private',
			'gps_lat'          => $lat,
			'gps_lng'          => $lng,
			'captured_at'      => $captured,
			'meta'             => [
				'job_id'       => $job_id,
				'sha256'       => $sha,
				'gps_accuracy' => $acc,
				'verified'     => $verified ? 1 : 0,
				'customer'     => (string) ( $row['customer_name'] ?? '' ),
				'component'    => (string) ( $row['component'] ?? '' ),
			],
		] );
		if ( ! is_array( $saved ) || empty( $saved['id'] ) ) {
			wp_send_json_error( [ 'message' => 'save_failed' ], 500 );
		}
		$media_id = (int) $saved['id'];
		$token    = self::token_for_media( $media_id );

		wp_send_json_success( [
			'media_id'  => $media_id,
			'url'       => self::public_url( $media_id, $token, 'full' ),
			'thumb_url' => self::public_url( $media_id, $token, 'thumb' ),
			'verified'  => (bool) $verified,
			'accuracy'  => $acc,
		] );
	}

	/** True if this exact image (sha256) is already stored as a Jobs finish photo. */
	private static function hash_exists( string $sha ): bool {
		global $wpdb;
		if ( $sha === '' ) {
			return false;
		}
		$mtable = self::media_table();
		$needle = '%' . $wpdb->esc_like( '"sha256":"' . $sha . '"' ) . '%';
		$found  = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$mtable} WHERE source_app = %s AND meta_json LIKE %s LIMIT 1",
			self::SOURCE_APP, $needle
		) );
		return ! empty( $found );
	}

	/** Mint (via the theme) + return the media row's share_token. */
	private static function token_for_media( int $media_id ): string {
		if ( class_exists( 'ZDZ_User_Media' ) && method_exists( 'ZDZ_User_Media', 'get_by_id' ) ) {
			$row = ZDZ_User_Media::get_by_id( $media_id ); // shape_out mints + persists share_token
			if ( is_array( $row ) && ! empty( $row['share_token'] ) ) {
				return (string) $row['share_token'];
			}
		}
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT share_token FROM ' . self::media_table() . ' WHERE id = %d',
			$media_id
		) );
	}

	/** The login-free, token-gated public URL for a Jobs finish photo. */
	public static function public_url( int $media_id, string $token, string $size = 'full' ): string {
		if ( $media_id <= 0 || $token === '' ) {
			return '';
		}
		$args = [ 'zjob_photo' => $media_id, 't' => $token ];
		if ( $size === 'thumb' ) {
			$args['s'] = 'thumb';
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/* =======================================================================
	 * SERVE  (/?zjob_photo=<id>&t=<token>[&s=thumb]) — no login; token is auth.
	 * ======================================================================= */

	public static function serve(): void {
		if ( ! isset( $_GET['zjob_photo'] ) ) {
			return;
		}
		$id  = absint( $_GET['zjob_photo'] );
		$tok = isset( $_GET['t'] ) ? (string) wp_unslash( $_GET['t'] ) : '';
		if ( $id <= 0 || $tok === '' ) {
			self::fail();
		}

		global $wpdb;
		$mtable = self::media_table();
		$row    = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, source_app, wp_attachment_id, file_url, thumbnail_url, filename, share_token FROM {$mtable} WHERE id = %d",
			$id
		), ARRAY_A );
		if ( ! $row ) {
			self::fail();
		}
		// Confined to Jobs finish photos — never a bypass for other apps' media.
		if ( (string) ( $row['source_app'] ?? '' ) !== self::SOURCE_APP ) {
			self::fail();
		}
		$expected = (string) ( $row['share_token'] ?? '' );
		if ( $expected === '' || ! hash_equals( $expected, $tok ) ) {
			self::fail();
		}

		$want_thumb = ( ( $_GET['s'] ?? '' ) === 'thumb' );
		$path       = self::resolve_path( $row, $want_thumb );
		if ( $path === '' || ! is_readable( $path ) ) {
			self::fail();
		}

		$mime = wp_check_filetype( $path )['type'] ?: 'application/octet-stream';
		$name = sanitize_file_name( (string) ( $row['filename'] ?: ( 'job-photo-' . $id ) ) );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff', true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: no-referrer', true );
		header( 'Cache-Control: private, max-age=600', true );
		readfile( $path );
		exit;
	}

	private static function resolve_path( array $row, bool $want_thumb ): string {
		$att  = (int) ( $row['wp_attachment_id'] ?? 0 );
		$path = '';
		if ( $want_thumb && $att > 0 && function_exists( 'image_get_intermediate_size' ) ) {
			$thumb = image_get_intermediate_size( $att, 'medium' );
			if ( ! empty( $thumb['path'] ) ) {
				$path = trailingslashit( wp_get_upload_dir()['basedir'] ) . $thumb['path'];
			}
		}
		if ( $path === '' && $att > 0 ) {
			$path = (string) get_attached_file( $att );
		}
		if ( $path === '' ) {
			$src  = $want_thumb ? ( $row['thumbnail_url'] ?: $row['file_url'] ) : $row['file_url'];
			$path = self::url_to_local_path( (string) $src );
		}
		return $path;
	}

	/** Map a stored wp-uploads URL to a confined local path (never escapes uploads). */
	private static function url_to_local_path( string $url ): string {
		if ( $url === '' ) {
			return '';
		}
		$up = wp_get_upload_dir();
		if ( strpos( $url, (string) $up['baseurl'] ) !== 0 ) {
			return '';
		}
		$rel  = ltrim( substr( $url, strlen( (string) $up['baseurl'] ) ), '/' );
		$path = trailingslashit( $up['basedir'] ) . $rel;
		$real = realpath( $path );
		$base = realpath( $up['basedir'] );
		return ( $real && $base && strpos( $real, $base ) === 0 ) ? $real : '';
	}

	private static function fail(): void {
		status_header( 404 );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		exit;
	}

	/**
	 * Build the public photo links for a set of media ids (for the CRM note / the
	 * close queue). Returns [ { id, url, thumb_url } ].
	 */
	public static function links_for( array $media_ids ): array {
		$out = [];
		foreach ( $media_ids as $mid ) {
			$mid = (int) $mid;
			if ( $mid <= 0 ) {
				continue;
			}
			$tok = self::token_for_media( $mid );
			if ( $tok === '' ) {
				continue;
			}
			$out[] = [
				'id'        => $mid,
				'url'       => self::public_url( $mid, $tok, 'full' ),
				'thumb_url' => self::public_url( $mid, $tok, 'thumb' ),
			];
		}
		return $out;
	}

	/* =======================================================================
	 * DOSSIER: the confidence matcher (for_jobs)
	 *
	 * Surface the photos that belong to a set of jobs and RECORD HOW each one matched,
	 * reading the Core media store (ZDZ_User_Media's table) — never re-implementing it.
	 * Every returned URL is minted through the token proxy (ZDZ_User_Media::secure_url,
	 * with this app's own token proxy as the only fallback): a geo-stamped photo never
	 * leaves as a raw uploads URL.
	 *
	 * Five match kinds, ranked by confidence:
	 *   asserted (confirmed) - the media was uploaded FOR this job (source_ref jobphoto:<id>)
	 *   finish   (confirmed) - the media id is in the job's own finish_media_ids
	 *   estimate (probable)  - the media points at the job's source estimate (source_ref estimate:<id>)
	 *   schedule (probable)  - the job's people captured it inside the appointment window
	 *   geo      (suggested) - the media's GPS fix is within the geo radius of the finish fix
	 *
	 * for_jobs() is CONFIRMED-ONLY by default; asking for a lower floor (min_confidence)
	 * runs the extra matchers. A photo matched several ways keeps its STRONGEST kind.
	 * ======================================================================= */

	const MATCH_ASSERTED = 'asserted';
	const MATCH_FINISH   = 'finish';
	const MATCH_ESTIMATE = 'estimate';
	const MATCH_SCHEDULE = 'schedule';
	const MATCH_GEO      = 'geo';

	const CONF_CONFIRMED = 'confirmed';
	const CONF_PROBABLE  = 'probable';
	const CONF_SUGGESTED = 'suggested';

	/** Default geo-proximity radius (metres) for the suggested `geo` match. */
	const GEO_RADIUS_M_DEFAULT   = 120;
	/** Default +/- padding (minutes) around the appointment for the `schedule` match. */
	const SCHEDULE_PAD_MIN_DEFAULT = 120;

	/** In-request cache of raw media rows keyed by id (populated by every matcher). */
	private static array $media_cache = [];

	/** Filterable geo radius (metres). */
	public static function geo_radius_m(): int {
		return max( 1, (int) apply_filters( 'zdz_job_photo_geo_radius_m', self::GEO_RADIUS_M_DEFAULT ) );
	}

	/** Filterable appointment-window padding (minutes). */
	public static function schedule_pad_min(): int {
		return max( 0, (int) apply_filters( 'zdz_job_photo_schedule_pad_min', self::SCHEDULE_PAD_MIN_DEFAULT ) );
	}

	/**
	 * Matched photos for a set of jobs, each tagged with how it matched + confidence.
	 *
	 * @param array $jobs Job rows (preferred) or job ids. A row carries id, estimate_id,
	 *                    finish_media_ids, finish_gps_lat/lng, scheduled_start_utc/
	 *                    scheduled_end_utc, assigned_user_id, created_by, scheduled_by.
	 * @param array $opts { min_confidence:'confirmed'|'probable'|'suggested'='confirmed',
	 *                      per_job_limit:int=60 }
	 * @return array<int,array<int,array>> job_id => [ { media_id, match, confidence,
	 *                    url, thumb_url, captured_at, gps_lat, gps_lng } ], strongest first.
	 */
	public static function for_jobs( array $jobs, array $opts = [] ): array {
		$jobs = self::normalize_jobs( $jobs );
		if ( empty( $jobs ) ) {
			return [];
		}

		$want = self::confidences_at_least(
			self::clamp_confidence( (string) ( $opts['min_confidence'] ?? self::CONF_CONFIRMED ) )
		);
		$per_job_limit = max( 1, min( 500, (int) ( $opts['per_job_limit'] ?? 60 ) ) );

		// job_id => media_id => [ match, confidence, rank, spec ]
		$hits          = [];
		$need_media_ids = [];

		// --- CONFIRMED: finish (the job's own finish_media_ids column) ---
		foreach ( $jobs as $jid => $j ) {
			foreach ( $j['finish_media_ids'] as $mid ) {
				self::record_hit( $hits, $jid, $mid, self::MATCH_FINISH );
				$need_media_ids[ $mid ] = $mid;
			}
		}

		// --- CONFIRMED: asserted (media uploaded FOR the job: source_ref jobphoto:<id>) ---
		$ref_map = [];
		foreach ( $jobs as $jid => $j ) {
			$ref_map[ 'jobphoto:' . $jid ] = $jid;
		}
		foreach ( self::media_rows_by_source_refs( array_keys( $ref_map ), self::SOURCE_APP ) as $row ) {
			$jid = $ref_map[ (string) $row['source_ref'] ] ?? 0;
			if ( $jid > 0 ) {
				self::record_hit( $hits, $jid, (int) $row['id'], self::MATCH_ASSERTED );
			}
		}

		// --- PROBABLE: estimate + schedule ---
		if ( in_array( self::CONF_PROBABLE, $want, true ) ) {
			// estimate: media whose source_ref is estimate:<estimate_id>.
			$est_map = [];
			foreach ( $jobs as $jid => $j ) {
				if ( $j['estimate_id'] > 0 ) {
					$est_map[ 'estimate:' . $j['estimate_id'] ][] = $jid;
				}
			}
			if ( ! empty( $est_map ) ) {
				foreach ( self::media_rows_by_source_refs( array_keys( $est_map ), '' ) as $row ) {
					foreach ( (array) ( $est_map[ (string) $row['source_ref'] ] ?? [] ) as $jid ) {
						self::record_hit( $hits, $jid, (int) $row['id'], self::MATCH_ESTIMATE );
					}
				}
			}

			// schedule: the job's people captured a photo inside the appointment window.
			$pad = self::schedule_pad_min();
			foreach ( $jobs as $jid => $j ) {
				if ( '' === $j['start_utc'] || empty( $j['people'] ) ) {
					continue;
				}
				$end = '' !== $j['end_utc'] ? $j['end_utc'] : $j['start_utc'];
				foreach ( self::media_rows_in_window( $j['people'], $j['start_utc'], $end, $pad ) as $row ) {
					self::record_hit( $hits, $jid, (int) $row['id'], self::MATCH_SCHEDULE );
				}
			}
		}

		// --- SUGGESTED: geo (within the radius of the finish fix) ---
		if ( in_array( self::CONF_SUGGESTED, $want, true ) ) {
			$radius = self::geo_radius_m();
			foreach ( $jobs as $jid => $j ) {
				if ( null === $j['gps_lat'] || null === $j['gps_lng'] ) {
					continue;
				}
				foreach ( self::media_rows_near( $j['gps_lat'], $j['gps_lng'], $radius ) as $row ) {
					self::record_hit( $hits, $jid, (int) $row['id'], self::MATCH_GEO );
				}
			}
		}

		// Load any finish-id rows the reference/geo/time queries did not already cache.
		$missing = [];
		foreach ( $need_media_ids as $mid ) {
			if ( ! isset( self::$media_cache[ $mid ] ) ) {
				$missing[] = $mid;
			}
		}
		if ( ! empty( $missing ) ) {
			self::media_rows_by_ids( $missing ); // populates the cache
		}

		// Build the output — filter to the requested confidences, mint secure URLs.
		$out = array_fill_keys( array_keys( $jobs ), [] );
		foreach ( $hits as $jid => $by_media ) {
			$list = [];
			foreach ( $by_media as $mid => $h ) {
				if ( ! in_array( $h['confidence'], $want, true ) ) {
					continue;
				}
				$row = self::$media_cache[ $mid ] ?? null;
				if ( ! is_array( $row ) ) {
					continue;
				}
				[ $url, $thumb ] = self::secure_pair( $row );
				if ( '' === $url ) {
					continue; // never emit a raw/unproxied URL
				}
				$list[] = [
					'media_id'    => $mid,
					'match'       => $h['match'],
					'confidence'  => $h['confidence'],
					'rank'        => $h['rank'],
					'spec'        => $h['spec'],
					'url'         => $url,
					'thumb_url'   => $thumb,
					'captured_at' => (string) ( $row['captured_at'] ?? '' ),
					'gps_lat'     => isset( $row['gps_lat'] ) && '' !== $row['gps_lat'] ? (float) $row['gps_lat'] : null,
					'gps_lng'     => isset( $row['gps_lng'] ) && '' !== $row['gps_lng'] ? (float) $row['gps_lng'] : null,
				];
			}
			// Strongest confidence first, then most-specific match, then newest id.
			usort( $list, static function ( $a, $b ) {
				return ( $b['rank'] <=> $a['rank'] )
					?: ( $a['spec'] <=> $b['spec'] )
					?: ( $b['media_id'] <=> $a['media_id'] );
			} );
			// Drop the internal sort keys before returning.
			$out[ $jid ] = array_map( static function ( $m ) {
				unset( $m['rank'], $m['spec'] );
				return $m;
			}, array_slice( $list, 0, $per_job_limit ) );
		}

		return $out;
	}

	/**
	 * Per-job count of confirmed photo matches (spans the job regardless of who may
	 * open it — a count discloses nothing). The Record panel uses this for an honest
	 * "N photos" even when the visible strip is shorter.
	 *
	 * @param array $jobs Job rows or ids.
	 * @return array<int,int> job_id => confirmed match count
	 */
	public static function counts_for( array $jobs ): array {
		$matched = self::for_jobs( $jobs, [ 'min_confidence' => self::CONF_CONFIRMED, 'per_job_limit' => 500 ] );
		$out     = [];
		foreach ( $matched as $jid => $list ) {
			$out[ (int) $jid ] = count( $list );
		}
		return $out;
	}

	/* =======================================================================
	 * MATCHER INTERNALS
	 * ======================================================================= */

	/** Keep the strongest (job, media) hit: higher confidence wins; ties -> more specific. */
	private static function record_hit( array &$hits, int $job_id, int $media_id, string $match ): void {
		if ( $job_id <= 0 || $media_id <= 0 ) {
			return;
		}
		$conf = self::confidence_for( $match );
		$rank = self::confidence_rank( $conf );
		$spec = self::match_specificity( $match );

		$cur = $hits[ $job_id ][ $media_id ] ?? null;
		if ( null === $cur
			|| $rank > $cur['rank']
			|| ( $rank === $cur['rank'] && $spec < $cur['spec'] ) ) {
			$hits[ $job_id ][ $media_id ] = [
				'match'      => $match,
				'confidence' => $conf,
				'rank'       => $rank,
				'spec'       => $spec,
			];
		}
	}

	private static function confidence_for( string $match ): string {
		switch ( $match ) {
			case self::MATCH_ASSERTED:
			case self::MATCH_FINISH:
				return self::CONF_CONFIRMED;
			case self::MATCH_ESTIMATE:
			case self::MATCH_SCHEDULE:
				return self::CONF_PROBABLE;
			case self::MATCH_GEO:
			default:
				return self::CONF_SUGGESTED;
		}
	}

	private static function confidence_rank( string $conf ): int {
		$order = [ self::CONF_SUGGESTED => 0, self::CONF_PROBABLE => 1, self::CONF_CONFIRMED => 2 ];
		return $order[ $conf ] ?? 0;
	}

	private static function match_specificity( string $match ): int {
		$order = [
			self::MATCH_ASSERTED => 0,
			self::MATCH_FINISH   => 1,
			self::MATCH_ESTIMATE => 2,
			self::MATCH_SCHEDULE => 3,
			self::MATCH_GEO      => 4,
		];
		return $order[ $match ] ?? 9;
	}

	private static function clamp_confidence( string $c ): string {
		$c = sanitize_key( $c );
		return in_array( $c, [ self::CONF_CONFIRMED, self::CONF_PROBABLE, self::CONF_SUGGESTED ], true )
			? $c : self::CONF_CONFIRMED;
	}

	/** The confidence labels at or above a floor. */
	private static function confidences_at_least( string $min ): array {
		$min_rank = self::confidence_rank( $min );
		$out      = [];
		foreach ( [ self::CONF_SUGGESTED, self::CONF_PROBABLE, self::CONF_CONFIRMED ] as $c ) {
			if ( self::confidence_rank( $c ) >= $min_rank ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	/** Normalise mixed job input (rows or ids) to a keyed shape the matchers read. */
	private static function normalize_jobs( array $jobs ): array {
		$out = [];
		foreach ( $jobs as $j ) {
			if ( is_array( $j ) ) {
				$id  = (int) ( $j['id'] ?? 0 );
				$row = $j;
			} else {
				$id  = (int) $j;
				$row = ( $id > 0 && class_exists( 'ZJOB_Jobs' ) ) ? ( ZJOB_Jobs::get( $id ) ?? [] ) : [];
			}
			if ( $id <= 0 ) {
				continue;
			}
			$out[ $id ] = [
				'id'               => $id,
				'estimate_id'      => (int) ( $row['estimate_id'] ?? 0 ),
				'finish_media_ids' => self::parse_media_ids( $row['finish_media_ids'] ?? '' ),
				'gps_lat'          => ( isset( $row['finish_gps_lat'] ) && '' !== $row['finish_gps_lat'] && null !== $row['finish_gps_lat'] ) ? (float) $row['finish_gps_lat'] : null,
				'gps_lng'          => ( isset( $row['finish_gps_lng'] ) && '' !== $row['finish_gps_lng'] && null !== $row['finish_gps_lng'] ) ? (float) $row['finish_gps_lng'] : null,
				'start_utc'        => (string) ( $row['scheduled_start_utc'] ?? '' ),
				'end_utc'          => (string) ( $row['scheduled_end_utc'] ?? '' ),
				'people'           => array_values( array_unique( array_filter( array_map( 'intval', [
					$row['assigned_user_id'] ?? 0,
					$row['created_by'] ?? 0,
					$row['scheduled_by'] ?? 0,
				] ) ) ) ),
			];
		}
		return $out;
	}

	/** finish_media_ids is stored as a JSON array; accept a CSV fallback defensively. */
	private static function parse_media_ids( $raw ): array {
		$raw = (string) $raw;
		if ( '' === trim( $raw ) ) {
			return [];
		}
		$ids = json_decode( $raw, true );
		if ( ! is_array( $ids ) ) {
			$ids = explode( ',', $raw );
		}
		$out = [];
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$out[ $id ] = $id;
			}
		}
		return array_values( $out );
	}

	/* ---- media-store readers (read Core's table; never duplicate its writers) ---- */

	/** Fetch + cache media rows by id (one IN() query). Returns the rows. */
	private static function media_rows_by_ids( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return [];
		}
		$mtable = self::media_table();
		$ph     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$mtable} WHERE id IN ({$ph})", $ids ), ARRAY_A );
		return self::cache_rows( $rows );
	}

	/** Fetch + cache media rows by source_ref (optionally scoped to a source_app). */
	private static function media_rows_by_source_refs( array $refs, string $source_app ): array {
		global $wpdb;
		$refs = array_values( array_unique( array_filter( array_map( 'strval', $refs ) ) ) );
		if ( empty( $refs ) ) {
			return [];
		}
		$mtable = self::media_table();
		$ph     = implode( ',', array_fill( 0, count( $refs ), '%s' ) );
		$params = $refs;
		$sql    = "SELECT * FROM {$mtable} WHERE source_ref IN ({$ph})";
		if ( '' !== $source_app ) {
			$sql     .= ' AND source_app = %s';
			$params[] = $source_app;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return self::cache_rows( $rows );
	}

	/** Media captured by a set of users within [start-pad, end+pad]. */
	private static function media_rows_in_window( array $user_ids, string $start_utc, string $end_utc, int $pad_min ): array {
		global $wpdb;
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( empty( $user_ids ) || '' === $start_utc ) {
			return [];
		}
		$from = gmdate( 'Y-m-d H:i:s', strtotime( $start_utc ) - $pad_min * 60 );
		$to   = gmdate( 'Y-m-d H:i:s', strtotime( '' !== $end_utc ? $end_utc : $start_utc ) + $pad_min * 60 );
		$mtable = self::media_table();
		$ph     = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$params = array_merge( $user_ids, [ $from, $to ] );
		$sql    = "SELECT * FROM {$mtable}
			WHERE user_id IN ({$ph}) AND captured_at IS NOT NULL AND captured_at BETWEEN %s AND %s";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return self::cache_rows( $rows );
	}

	/** Media whose GPS fix is within $radius_m of a point (bounding box + haversine). */
	private static function media_rows_near( float $lat, float $lng, int $radius_m ): array {
		global $wpdb;
		$dlat = $radius_m / 111320.0;
		$cos  = cos( deg2rad( $lat ) );
		$dlng = $radius_m / ( 111320.0 * ( abs( $cos ) > 0.000001 ? $cos : 0.000001 ) );
		$dlng = abs( $dlng );

		$mtable = self::media_table();
		$sql    = "SELECT * FROM {$mtable}
			WHERE gps_lat IS NOT NULL AND gps_lng IS NOT NULL
			  AND gps_lat BETWEEN %f AND %f AND gps_lng BETWEEN %f AND %f";
		$rows = $wpdb->get_results( $wpdb->prepare(
			$sql, $lat - $dlat, $lat + $dlat, $lng - $dlng, $lng + $dlng
		), ARRAY_A );

		// Tighten the box to a true radius.
		$near = [];
		foreach ( (array) $rows as $row ) {
			if ( self::haversine_m( $lat, $lng, (float) $row['gps_lat'], (float) $row['gps_lng'] ) <= $radius_m ) {
				$near[] = $row;
			}
		}
		return self::cache_rows( $near );
	}

	/** Store rows in the per-request cache keyed by id; return them. */
	private static function cache_rows( $rows ): array {
		$rows = is_array( $rows ) ? $rows : [];
		foreach ( $rows as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id > 0 ) {
				self::$media_cache[ $id ] = $row;
			}
		}
		return $rows;
	}

	/** Great-circle distance in metres. */
	private static function haversine_m( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$r    = 6371000.0;
		$dlat = deg2rad( $lat2 - $lat1 );
		$dlng = deg2rad( $lng2 - $lng1 );
		$a    = sin( $dlat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dlng / 2 ) ** 2;
		return $r * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	/**
	 * A membership/privacy-gated [full, thumb] URL pair for a media row — the Core
	 * token proxy first, this app's own token proxy as the only fallback. Never a raw
	 * uploads URL (geo-stamped photos are geo-PII).
	 *
	 * @return array{0:string,1:string}
	 */
	private static function secure_pair( array $row ): array {
		if ( class_exists( 'ZDZ_User_Media' ) && method_exists( 'ZDZ_User_Media', 'secure_url' ) ) {
			return [ (string) ZDZ_User_Media::secure_url( $row, 'full' ), (string) ZDZ_User_Media::secure_url( $row, 'thumb' ) ];
		}
		// Fallback: our own login-free token proxy, but only for this app's own media.
		if ( (string) ( $row['source_app'] ?? '' ) === self::SOURCE_APP ) {
			$mid = (int) ( $row['id'] ?? 0 );
			$tok = (string) ( $row['share_token'] ?? '' );
			if ( '' === $tok && $mid > 0 ) {
				$tok = self::token_for_media( $mid );
			}
			if ( $mid > 0 && '' !== $tok ) {
				return [ self::public_url( $mid, $tok, 'full' ), self::public_url( $mid, $tok, 'thumb' ) ];
			}
		}
		return [ '', '' ];
	}
}
