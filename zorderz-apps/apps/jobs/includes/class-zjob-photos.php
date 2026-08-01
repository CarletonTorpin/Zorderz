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
}
