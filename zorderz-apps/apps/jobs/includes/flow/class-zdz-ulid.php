<?php
/**
 * Zdz_Ulid — a time-ordered, Crockford base32 ULID generator.
 *
 * 128 bits: a 48-bit millisecond timestamp (most-significant) followed by 80 bits
 * of randomness, encoded as 26 uppercase Crockford base32 characters. Because the
 * timestamp is the high half, ULIDs sort lexicographically in creation order — which
 * is why work-item ids and transition ids are stored as CHAR(26) and ordered by id.
 *
 * PART OF THE Zdz_Flow SUBSTRATE (jobs-app-local, Flow-shaped). Promotion path:
 * move this file to `zorderz/inc/` unchanged to promote Flow to a Core service — the
 * class name and API do not change.
 *
 * No company/person/product/place/provider literal appears here. Ships EMPTY.
 *
 * @package Zorderz\Jobs\Flow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Zdz_Ulid' ) ) {

	class Zdz_Ulid {

		/** Crockford base32 alphabet (excludes I, L, O, U to avoid transcription errors). */
		const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

		/**
		 * Generate a new ULID (26 uppercase Crockford base32 chars).
		 *
		 * @return string
		 */
		public static function generate(): string {
			$ms = (int) ( microtime( true ) * 1000 );

			// 48-bit millisecond timestamp as 6 big-endian bytes (low 6 of an 8-byte pack).
			$time_bytes = substr( pack( 'J', $ms ), 2 );

			// 80 bits (10 bytes) of randomness; fall back only if the CSPRNG is unavailable.
			try {
				$rand = random_bytes( 10 );
			} catch ( \Throwable $e ) {
				$rand = '';
				for ( $i = 0; $i < 10; $i++ ) {
					$rand .= chr( wp_rand( 0, 255 ) );
				}
			}

			return self::encode( $time_bytes . $rand );
		}

		/**
		 * Encode 16 bytes (128 bits) as 26 Crockford base32 chars.
		 *
		 * Two zero bits are prepended so 2 + 128 = 130 bits divide evenly into 26 groups
		 * of 5 — the canonical ULID layout (the first character is therefore 0–7).
		 *
		 * @param string $bytes exactly 16 bytes.
		 * @return string
		 */
		private static function encode( string $bytes ): string {
			$out       = '';
			$buffer    = 0;
			$bits_left = 2; // leading pad so the total bit count is a multiple of 5
			$len       = strlen( $bytes );

			for ( $i = 0; $i < $len; $i++ ) {
				$buffer     = ( $buffer << 8 ) | ord( $bytes[ $i ] );
				$bits_left += 8;
				while ( $bits_left >= 5 ) {
					$bits_left -= 5;
					$out       .= self::ALPHABET[ ( $buffer >> $bits_left ) & 0x1F ];
				}
			}
			if ( $bits_left > 0 ) {
				$out .= self::ALPHABET[ ( $buffer << ( 5 - $bits_left ) ) & 0x1F ];
			}
			return $out;
		}

		/**
		 * Is $s a syntactically valid ULID? (Shape check only, not a checksum.)
		 *
		 * @param mixed $s
		 * @return bool
		 */
		public static function is_valid( $s ): bool {
			return is_string( $s ) && (bool) preg_match( '/^[0-9A-HJKMNP-TV-Z]{26}$/', $s );
		}
	}
}
