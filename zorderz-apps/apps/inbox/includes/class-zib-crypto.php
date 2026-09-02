<?php
/**
 * ZIB_Crypto — content-at-rest encryption for the Ballast (D4 hybrid).
 *
 * Mirrors ZIB_Vault's sealing (libsodium secretbox, or AES-256-CTR + HMAC
 * fallback) but with a SEPARATE key domain ('zib-content-v1'), so mail bodies
 * and OAuth tokens never share a key. Used to encrypt message BODIES at rest;
 * subject / snippet / participants stay plaintext in the sealed, Gatekeeper-only
 * tables so MySQL FULLTEXT search stays fast (the D4 hybrid the owner chose).
 *
 * Threat model: a DB-only compromise (backup leak, cross-plugin SQLi, DB-level
 * access) yields ciphertext for every mail BODY — the bulk of the sensitive
 * content. It does NOT defend a full-server compromise where wp-config leaks
 * too (the key derives from wp_salt); that ceiling was disclosed and accepted.
 *
 * @since 0.2.0 (P1 — Ballast + ingest)
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Crypto {

	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . 'zib-content-v1', true );
	}

	private static function mac_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . 'zib-content-mac-v1', true );
	}

	/** Seal content for storage. '' stays ''. Never stores anything it cannot read back. */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$sealed = sodium_crypto_secretbox( $plain, $nonce, self::key() );
			$out    = 'c1s:' . base64_encode( $nonce . $sealed );
		} else {
			$iv     = random_bytes( 16 );
			$cipher = openssl_encrypt( $plain, 'aes-256-ctr', self::key(), OPENSSL_RAW_DATA, $iv );
			if ( false === $cipher ) {
				return '';
			}
			$mac = hash_hmac( 'sha256', $iv . $cipher, self::mac_key(), true );
			$out = 'c1o:' . base64_encode( $iv . $cipher . $mac );
		}
		return ( self::decrypt( $out ) === $plain ) ? $out : ''; // round-trip guard
	}

	/** Open sealed content. Returns '' for empty / undecryptable / tampered input. */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}
		$prefix = substr( $stored, 0, 4 );
		$raw    = base64_decode( substr( $stored, 4 ), true );
		if ( false === $raw ) {
			return '';
		}
		if ( 'c1s:' === $prefix && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain = sodium_crypto_secretbox_open( $box, $nonce, self::key() );
			return ( false === $plain ) ? '' : $plain;
		}
		if ( 'c1o:' === $prefix ) {
			if ( strlen( $raw ) <= 48 ) {
				return '';
			}
			$iv     = substr( $raw, 0, 16 );
			$mac    = substr( $raw, -32 );
			$cipher = substr( $raw, 16, -32 );
			$expect = hash_hmac( 'sha256', $iv . $cipher, self::mac_key(), true );
			if ( ! hash_equals( $expect, $mac ) ) {
				return '';
			}
			$plain = openssl_decrypt( $cipher, 'aes-256-ctr', self::key(), OPENSSL_RAW_DATA, $iv );
			return ( false === $plain ) ? '' : $plain;
		}
		return '';
	}
}
