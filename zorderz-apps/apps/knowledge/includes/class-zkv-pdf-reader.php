<?php
/**
 * TS Knowledge Vault — PDF Image Extractor
 *
 * Many PDFs (especially "Print to PDF" from browsers) store pages as
 * full-page images with NO text layer. This class extracts those images
 * so they can be sent to AI vision models for reading.
 *
 * @package TSKnowledgeVault
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZKV_PDF_Reader {

	/**
	 * Check if a PDF is image-only (no text content, pages are images).
	 *
	 * @param string $file_path Path to the PDF.
	 * @return bool True if the PDF is image-only.
	 */
	public static function is_image_only( $file_path ) {
		$header = file_get_contents( $file_path, false, null, 0, 8192 );
		if ( substr( $header, 0, 5 ) !== '%PDF-' ) { return false; }

		$content = file_get_contents( $file_path );

		// Must have image XObjects to be "image-only".
		if ( ! preg_match( '/\/Subtype\s*\/Image/', $content ) ) {
			return false;
		}

		// Check for actual rendered text in content streams.
		// Font definitions alone don't mean text is rendered (jsPDF includes
		// fonts in every PDF even when only images are present).
		// Look for text operators: Tj, TJ, ', " inside BT...ET blocks.
		// Decompress streams and check for text operators.
		$has_rendered_text = false;

		// Quick check: count BT (begin text) operators in the raw content.
		// In compressed PDFs these won't be visible, but in jsPDF outputs
		// and many simple PDFs they are.
		if ( preg_match_all( '/\bBT\b/', $content, $m ) > 0 ) {
			// BT found — but it might be in compressed streams. Check if
			// there are actual text operators nearby.
			if ( preg_match( '/BT\s.*?(Tj|TJ)\s/s', $content ) ) {
				$has_rendered_text = true;
			}
		}

		// If still unsure, try decompressing streams and checking.
		if ( ! $has_rendered_text ) {
			preg_match_all( '/stream\r?\n(.*?)\r?\nendstream/s', $content, $streams );
			foreach ( $streams[1] ?? array() as $stream_data ) {
				$decoded = @gzuncompress( $stream_data );
				if ( $decoded === false ) { $decoded = @gzinflate( $stream_data ); }
				if ( $decoded && preg_match( '/\b(Tj|TJ)\b/', $decoded ) ) {
					// Check if the text content is substantial (not just whitespace/metadata).
					preg_match_all( '/\(([^)]+)\)\s*Tj/', $decoded, $text_matches );
					$all_text = implode( '', $text_matches[1] ?? array() );
					if ( strlen( trim( $all_text ) ) > 10 ) {
						$has_rendered_text = true;
						break;
					}
				}
			}
		}

		return ! $has_rendered_text;
	}

	/**
	 * Extract page images from an image-only PDF as base64 JPEG strings.
	 *
	 * @param string $file_path  Path to the PDF.
	 * @param int    $max_pages  Max pages to extract (for API cost/speed).
	 * @param int    $max_width  Resize images to this width (saves bandwidth).
	 * @return array Array of base64-encoded JPEG strings, one per page.
	 */
	public static function extract_page_images( $file_path, $max_pages = 3, $max_width = 1000 ) {
		$images = array();

		// Method 1: Try PHP Imagick (best quality, handles any PDF).
		if ( class_exists( 'Imagick' ) ) {
			try {
				$im = new \Imagick();
				$im->setResolution( 150, 150 ); // 150 DPI — good balance of quality/size.
				$pages_to_read = min( $max_pages, self::count_pages( $file_path ) );

				for ( $p = 0; $p < $pages_to_read; $p++ ) {
					$im->readImage( $file_path . '[' . $p . ']' );
					$im->setImageFormat( 'jpeg' );
					$im->setImageCompressionQuality( 60 );

					// Resize if too large.
					$w = $im->getImageWidth();
					if ( $w > $max_width ) {
						$im->resizeImage( $max_width, 0, \Imagick::FILTER_LANCZOS, 1 );
					}

					$images[] = base64_encode( $im->getImageBlob() );
					$im->clear();
				}
				$im->destroy();
				return $images;
			} catch ( \Exception $e ) {
				error_log( 'ZKV PDF Reader: Imagick failed — ' . $e->getMessage() );
				// Fall through to Method 2.
			}
		}

		// Method 2: Extract raw image data from PDF and convert with GD.
		if ( function_exists( 'imagecreatetruecolor' ) ) {
			$raw_images = self::extract_raw_images( $file_path, $max_pages );
			foreach ( $raw_images as $img_data ) {
				$gd_img = self::raw_to_gd( $img_data['data'], $img_data['width'], $img_data['height'], $img_data['colorspace'] );
				if ( $gd_img ) {
					// Resize if needed.
					$w = imagesx( $gd_img );
					if ( $w > $max_width ) {
						$ratio = $max_width / $w;
						$new_h = (int) ( imagesy( $gd_img ) * $ratio );
						$resized = imagecreatetruecolor( $max_width, $new_h );
						imagecopyresampled( $resized, $gd_img, 0, 0, 0, 0, $max_width, $new_h, $w, imagesy( $gd_img ) );
						imagedestroy( $gd_img );
						$gd_img = $resized;
					}
					ob_start();
					imagejpeg( $gd_img, null, 60 );
					$jpeg_data = ob_get_clean();
					imagedestroy( $gd_img );
					if ( ! empty( $jpeg_data ) ) {
						$images[] = base64_encode( $jpeg_data );
					}
				}
			}
		}

		return $images;
	}

	/**
	 * Count pages in a PDF.
	 */
	private static function count_pages( $file_path ) {
		$content = file_get_contents( $file_path );
		preg_match_all( '/\/Type\s*\/Page[^s]/', $content, $matches );
		return max( 1, count( $matches[0] ) );
	}

	/**
	 * Extract raw image streams from PDF binary.
	 */
	private static function extract_raw_images( $file_path, $max_pages ) {
		$content = file_get_contents( $file_path );
		$images = array();

		// Find image XObject definitions.
		preg_match_all(
			'/\/Type\s*\/XObject\s*\/Subtype\s*\/Image\s*\/Width\s+(\d+)\s*\/Height\s+(\d+)\s*\/ColorSpace\s+(\S+).*?\/Length\s+(\d+)\s*\/Filter\s*\/FlateDecode\s*>>\s*stream\r?\n/s',
			$content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
		);

		foreach ( $matches as $i => $m ) {
			if ( $i >= $max_pages ) { break; }

			$width      = (int) $m[1][0];
			$height     = (int) $m[2][0];
			$colorspace = $m[3][0]; // e.g., "6 0 R" or "/DeviceGray"
			$length     = (int) $m[4][0];
			$stream_start = $m[0][1] + strlen( $m[0][0] );

			$compressed = substr( $content, $stream_start, $length );
			$raw = @gzuncompress( $compressed );

			if ( $raw === false ) {
				$raw = @gzinflate( $compressed );
			}

			if ( $raw !== false ) {
				// Determine if grayscale or RGB.
				$expected_gray = $width * $height;
				$expected_rgb  = $width * $height * 3;
				$cs = 'gray';
				if ( strlen( $raw ) >= $expected_rgb ) {
					$cs = 'rgb';
				}

				$images[] = array(
					'data'       => $raw,
					'width'      => $width,
					'height'     => $height,
					'colorspace' => $cs,
				);
			}
		}

		return $images;
	}

	/**
	 * Convert raw pixel data to a GD image.
	 */
	private static function raw_to_gd( $raw_data, $width, $height, $colorspace ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) { return null; }

		$img = imagecreatetruecolor( $width, $height );
		if ( ! $img ) { return null; }

		$expected_size = $width * $height * ( $colorspace === 'rgb' ? 3 : 1 );
		if ( strlen( $raw_data ) < $expected_size ) {
			imagedestroy( $img );
			return null;
		}

		$offset = 0;
		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				if ( $colorspace === 'rgb' ) {
					$r = ord( $raw_data[ $offset++ ] );
					$g = ord( $raw_data[ $offset++ ] );
					$b = ord( $raw_data[ $offset++ ] );
				} else {
					$g = ord( $raw_data[ $offset++ ] );
					$r = $g; $b = $g;
				}
				imagesetpixel( $img, $x, $y, imagecolorallocate( $img, $r, $g, $b ) );
			}
		}

		return $img;
	}
}
