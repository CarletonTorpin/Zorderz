<?php
/**
 * ZDZ_Core_Crypto — reads every at-rest credential format the platform writes, exactly.
 *
 * Apps have stored service keys in three ways. A reader that understands only its own
 * format passes anything else on as if it were the key, and the service answers 401 /
 * invalid_client:
 *
 *   plain     stored as typed.
 *   fixed-IV  AES-256-CBC, key = wp_salt('auth'), iv = first 16 hex of sha256(key),
 *             openssl base64 output. Deterministic.
 *   pair-IV   base64( iv16 . '::' . openssl_b64( AES-256-CBC, key = first 32 hex of
 *             sha256(wp_salt('auth')), random iv ) ).
 *
 * decode() recognises a format only by an EXACT round trip (decrypt, re-encrypt,
 * require the stored bytes back), never by guessing from length or alphabet, so a
 * plaintext key is never mistaken for ciphertext. encode() writes any of the three,
 * so a value can be served to each app in the one format that app can read.
 *
 * Everything is keyed off wp_salt('auth'): rotating AUTH_KEY / AUTH_SALT in
 * wp-config.php makes every encrypted credential unreadable. Re-save every key under
 * Zorderz → Credentials after rotating them.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Core_Crypto {

	const PLAIN = 'plain';
	const FIXED = 'fixed-IV';
	const PAIR  = 'pair-IV';

	/**
	 * @return array{0:string,1:?string} [ format, plaintext ]. Format is one of
	 *         'empty', PLAIN, FIXED, PAIR, 'vault' (sodium, owned by its plugin),
	 *         or 'undecodable' (ciphertext under some other key). Plaintext is null
	 *         when it cannot be recovered.
	 */
	public static function decode( string $s ): array {
		if ( '' === $s ) {
			return array( 'empty', '' );
		}
		if ( preg_match( '/^(v1s|v1o|c1s):/', $s ) ) {
			return array( 'vault', null );
		}
		if ( function_exists( 'openssl_decrypt' ) ) {
			$salt = (string) wp_salt( 'auth' );

			$iv  = substr( hash( 'sha256', $salt ), 0, 16 );
			$out = @openssl_decrypt( $s, 'AES-256-CBC', $salt, 0, $iv );
			// A plaintext that is itself valid base64 of whole 16-byte blocks can "decrypt"
			// to padded garbage that re-encrypts to the same string (about 1 key in 256);
			// a real credential is printable, so garbage is not accepted.
			if ( is_string( $out ) && '' !== $out && self::printable( $out ) ) {
				$rt = @openssl_encrypt( $out, 'AES-256-CBC', $salt, 0, $iv );
				if ( is_string( $rt ) && hash_equals( $s, $rt ) ) {
					return array( self::FIXED, $out );
				}
			}

			$bin = base64_decode( $s, true );
			if ( false !== $bin && strlen( $bin ) > 18 && '::' === substr( $bin, 16, 2 ) ) {
				$iv2    = substr( $bin, 0, 16 );
				$cipher = substr( $bin, 18 );
				$k32    = substr( hash( 'sha256', $salt ), 0, 32 );
				$out2   = @openssl_decrypt( $cipher, 'AES-256-CBC', $k32, 0, $iv2 );
				if ( is_string( $out2 ) && '' !== $out2 && self::printable( $out2 ) ) {
					$rt2 = @openssl_encrypt( $out2, 'AES-256-CBC', $k32, 0, $iv2 );
					if ( is_string( $rt2 ) && hash_equals( $cipher, $rt2 ) ) {
						return array( self::PAIR, $out2 );
					}
				}
				return array( 'undecodable', null );
			}
		}
		// A long unbroken base64 run that decoded under neither scheme is ciphertext
		// we cannot read (another site's salt, a rotated AUTH_KEY) — not a key.
		if ( strlen( $s ) >= 80 && preg_match( '#^[A-Za-z0-9+/]+={0,2}$#', $s ) ) {
			return array( 'undecodable', null );
		}
		return array( self::PLAIN, $s );
	}

	/** Printable ASCII only: every key, secret and login the platform stores. */
	public static function printable( string $s ): bool {
		return 1 === preg_match( '/^[\x20-\x7E]+$/', $s );
	}

	/** The usable value, or '' when the stored string cannot be read. */
	public static function plain( string $s ): string {
		list( , $p ) = self::decode( $s );
		return null === $p ? '' : (string) $p;
	}

	/**
	 * Store $plain in $format.
	 *
	 * pair-IV: the IV is drawn again until it holds no ':' byte. Readers of this format
	 * split the decoded string at the FIRST '::', so an IV containing one (about 1 in
	 * 4,000 random IVs) could never be read back by them.
	 */
	public static function encode( string $plain, string $format ): string {
		if ( '' === $plain || self::PLAIN === $format || ! function_exists( 'openssl_encrypt' ) ) {
			return $plain;
		}
		$salt = (string) wp_salt( 'auth' );
		if ( self::FIXED === $format ) {
			return (string) openssl_encrypt( $plain, 'AES-256-CBC', $salt, 0, substr( hash( 'sha256', $salt ), 0, 16 ) );
		}
		if ( self::PAIR === $format ) {
			$k32 = substr( hash( 'sha256', $salt ), 0, 32 );
			do {
				$iv = random_bytes( 16 );
			} while ( false !== strpos( $iv, ':' ) );
			return base64_encode( $iv . '::' . openssl_encrypt( $plain, 'AES-256-CBC', $k32, 0, $iv ) );
		}
		return $plain;
	}

	/** First 8 hex of SHA-256 of the usable value — what screens show instead of a key. */
	public static function fingerprint( string $plain ): string {
		return '' === $plain ? '' : substr( hash( 'sha256', $plain ), 0, 8 );
	}
}
