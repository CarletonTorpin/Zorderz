<?php
/**
 * ZRCPT HEIC — server-side HEIC → JPEG conversion.
 *
 * Pre-v2.9.0 the plugin relied on Poe's bot to decode HEIC photos from
 * iPhones. Some Poe models handle HEIC; others silently return empty
 * content. Per Trap 6 we convert server-side before sending.
 *
 * Uses Imagick when available (preferred — best fidelity). Falls back
 * to an error-with-log entry on failure, which the caller surfaces to
 * the tech as "please re-upload as JPEG".
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ZRCPT_HEIC {

	/**
	 * Convert a HEIC/HEIF file in-place to JPEG. Returns the JPEG path on
	 * success, WP_Error on failure.
	 *
	 * @param string $path Filesystem path to the HEIC file.
	 * @return string|\WP_Error Path to the new .jpg file, or WP_Error.
	 */
	public static function to_jpeg( string $path ) {
		if ( ! file_exists( $path ) ) {
			return new \WP_Error( 'zrcpt_heic_not_found', 'HEIC file not found: ' . $path );
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, [ 'heic', 'heif' ], true ) ) {
			// Not a HEIC — return the path unchanged, it's already in a usable format.
			return $path;
		}

		if ( ! class_exists( '\Imagick' ) ) {
			error_log( '[ZRCPT_HEIC] Imagick not installed — cannot convert ' . basename( $path ) );
			return new \WP_Error( 'zrcpt_heic_no_imagick', 'Server is missing Imagick. Please re-upload the photo as JPEG.' );
		}

		try {
			$im = new \Imagick();
			$im->readImage( $path );
			$im->autoOrient();
			$im->setImageFormat( 'jpeg' );
			$im->setImageCompressionQuality( 85 );
			$im->stripImage();
			// Resize down if huge (matches the existing invoice-compress thresholds).
			$w = $im->getImageWidth();
			$h = $im->getImageHeight();
			if ( $w > 2400 || $h > 2400 ) {
				$im->thumbnailImage( 2400, 2400, true );
			}
			$jpeg_path = preg_replace( '/\.(heic|heif)$/i', '.jpg', $path );
			// If regex somehow didn't apply, append .jpg.
			if ( $jpeg_path === $path ) $jpeg_path = $path . '.jpg';
			$im->writeImage( $jpeg_path );
			$im->destroy();

			error_log( sprintf( '[ZRCPT_HEIC] Converted %s → %s (%dx%d)', basename( $path ), basename( $jpeg_path ), $w, $h ) );
			return $jpeg_path;
		} catch ( \Exception $e ) {
			error_log( '[ZRCPT_HEIC] Conversion failed for ' . basename( $path ) . ': ' . $e->getMessage() );
			return new \WP_Error( 'zrcpt_heic_fail', 'HEIC conversion failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Convert if needed — pass-through for non-HEIC files, convert for HEIC.
	 * Returns [ 'path' => ..., 'converted' => bool, 'error' => string|null ].
	 */
	public static function maybe_convert( string $path ): array {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, [ 'heic', 'heif' ], true ) ) {
			return [ 'path' => $path, 'converted' => false, 'error' => null ];
		}
		$result = self::to_jpeg( $path );
		if ( is_wp_error( $result ) ) {
			return [ 'path' => $path, 'converted' => false, 'error' => $result->get_error_message() ];
		}
		return [ 'path' => $result, 'converted' => true, 'error' => null ];
	}
}
