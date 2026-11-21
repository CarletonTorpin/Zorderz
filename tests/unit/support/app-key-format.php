<?php
/**
 * The app key's stored format, written out a second time for its tests: from the documented rules alone, never by
 * calling the class under test. A test that seals a value "as another site's key would have", or reads back what the
 * service sealed, does it with these, so the format is checked against its description and not only against itself.
 *
 *   printed key   32 bytes as RFC 4648 base32 without padding, in 13 groups of four
 *   key id        first 8 hex of HMAC-SHA256( 'zdzk1 key id', key )
 *   subkey        libsodium KDF, 32 bytes, the purpose's fixed id, context 'zdzkeys1'
 *   stored value  zdzk1:<key id>:<base64url( nonce[24] . XChaCha20-Poly1305( plain, AD, nonce, subkey ) )>
 *   AD            'zdzk1|' . purpose . '|' . key id . '|' . bind
 *   signature     HMAC-SHA256( purpose . '|' . message, subkey ), hex
 *
 * Used by the PHPUnit tests (AppKey*Test.php) and by the process they start (app-key-site.php).
 *
 * @package Zorderz\Tests
 */

if ( ! function_exists( 'zdzk_printed' ) ) {

	/** 32 bytes as a printed key. */
	function zdzk_printed( $raw ) {
		$bits = '';
		foreach ( str_split( (string) $raw ) as $c ) {
			$bits .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT );
		}
		$s = '';
		foreach ( str_split( $bits, 5 ) as $b ) {
			$s .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'[ bindec( str_pad( $b, 5, '0' ) ) ];
		}
		return implode( ' ', str_split( $s, 4 ) );
	}

	/** A printed key's 32 bytes. */
	function zdzk_raw( $printed ) {
		$s    = strtr( strtoupper( (string) preg_replace( '/[\s\-]/', '', (string) $printed ) ), array( '0' => 'O', '1' => 'I', '8' => 'B' ) );
		$bits = '';
		foreach ( str_split( $s ) as $c ) {
			$bits .= str_pad( decbin( (int) strpos( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $c ) ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( substr( $bits, 0, 256 ), 8 ) as $b ) {
			$out .= chr( bindec( $b ) );
		}
		return $out;
	}

	/** A printed key's id. */
	function zdzk_kid( $printed ) {
		return substr( hash_hmac( 'sha256', 'zdzk1 key id', zdzk_raw( $printed ) ), 0, 8 );
	}

	function zdzk_subkey( $printed, $purpose_id ) {
		return sodium_crypto_kdf_derive_from_key( 32, (int) $purpose_id, 'zdzkeys1', zdzk_raw( $printed ) );
	}

	/** Seal a value under any printed key, as another site's or an earlier key would have. */
	function zdzk_seal_under( $printed, $purpose_id, $purpose, $plain, $bind ) {
		$kid = zdzk_kid( $printed );
		$n   = random_bytes( 24 );
		$ct  = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( (string) $plain, "zdzk1|$purpose|$kid|$bind", $n, zdzk_subkey( $printed, $purpose_id ) );
		return 'zdzk1:' . $kid . ':' . rtrim( strtr( base64_encode( $n . $ct ), '+/', '-_' ), '=' );
	}

	/** Open a stored value under a printed key. False when it does not open. */
	function zdzk_open_under( $printed, $purpose_id, $purpose, $stored, $bind ) {
		if ( ! preg_match( '/^zdzk1:([0-9a-f]{8}):([A-Za-z0-9_-]+)$/D', (string) $stored, $m ) ) {
			return false;
		}
		$raw = base64_decode( strtr( $m[2], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $m[2] ) % 4 ) % 4 ), true );
		if ( false === $raw || strlen( $raw ) < 40 ) {
			return false;
		}
		return sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( substr( $raw, 24 ), "zdzk1|$purpose|{$m[1]}|$bind", substr( $raw, 0, 24 ), zdzk_subkey( $printed, $purpose_id ) );
	}

	/** A signature under any printed key. */
	function zdzk_mac_under( $printed, $purpose_id, $purpose, $message ) {
		return hash_hmac( 'sha256', $purpose . '|' . $message, zdzk_subkey( $printed, $purpose_id ) );
	}
}
