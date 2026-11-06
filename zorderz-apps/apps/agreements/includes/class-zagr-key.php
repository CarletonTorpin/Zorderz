<?php
/**
 * ZAGR_Key - the dedicated agreements key, and the sealing it does. [CORE]
 *
 * The key is 32 random bytes an administrator makes in their own browser (the Key panel) or restores from a password
 * manager. The site never generates, prints, logs or returns it. It is kept:
 *   - sealed under the site's own salts (wp-config.php): libsodium secretbox under a generichash of
 *     "zdz-sealed-v1|agreements-key|" and the SECURE_AUTH salt, so a database copy alone opens nothing;
 *   - with a check value: an HMAC of a fixed label under the key (never the key), so a key entered again after a salt
 *     change is checked against the records already sealed;
 *   - or in wp-config.php as ZDZ_AGREEMENTS_KEY (base64), which wins over the stored one.
 *
 * Sealed fields: XChaCha20-Poly1305 (libsodium AEAD), a fresh 24-byte nonce per value, under a subkey derived from the
 * key (sodium_crypto_kdf_derive_from_key, context "zagr_key", subkey 1); the field's own label is the associated data,
 * so a value moved to another column does not open. Files (the signed PDFs) use subkey 2, the code MACs subkey 3, the
 * record's hash chain subkey 4.
 *
 * Fails closed: no libsodium, no real secret keys in wp-config.php, no key, a key that can't be unsealed (the salts
 * changed: "Enter your agreements key again"), or a key whose check value differs from the stored one: usable() is
 * false, seal() and open() return null, and no signature is taken.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Key {

	const OPT         = 'zagr_key';            // { v, sealed, check, set_at, set_by }: never the key in the clear
	const CHECK_LABEL = 'zdz-agreements key check v1';
	const KDF_CTX     = 'zagr_key';             // 8 bytes, libsodium's KDF context
	const SUB_FIELDS  = 1;                      // purpose "agreements": sealed fields
	const SUB_FILES   = 2;                      // the signed PDFs on disk
	const SUB_CODES   = 3;                      // the emailed codes' MACs
	const SUB_CHAIN   = 4;                      // purpose "agreements-chain": the record's keyed hash chain
	const PREFIX      = 'a1:';
	const FILE_MAGIC  = 'ZAGR1';

	/** @var array|null { state, source, key } for this request */
	private static $mem = null;

	public static function sodium(): bool {
		return function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
			&& function_exists( 'sodium_crypto_kdf_derive_from_key' )
			&& function_exists( 'sodium_crypto_secretbox' );
	}

	/** 32 bytes from pasted base64 (or base64url, spaces and line breaks allowed); null otherwise. */
	public static function parse( $b64 ): ?string {
		$s = (string) preg_replace( '/\s+/', '', (string) $b64 );
		$s = strtr( $s, '-_', '+/' );
		$p = strlen( $s ) % 4;
		if ( $p ) {
			$s .= str_repeat( '=', 4 - $p );
		}
		$raw = base64_decode( $s, true );
		return ( false !== $raw && 32 === strlen( $raw ) ) ? $raw : null;
	}

	/** The check value of a key: an HMAC of a fixed label under it (never the key). */
	public static function check_value( string $key ): string {
		return hash_hmac( 'sha256', self::CHECK_LABEL, $key );
	}

	/**
	 * are the site's secret keys really in wp-config.php? Without them WordPress keeps its salts in the
	 * database, and a database copy alone would open the stored key. The rule: AUTH_KEY, AUTH_SALT, SECURE_AUTH_KEY
	 * and SECURE_AUTH_SALT defined, 32 characters or more, and
	 * not the placeholder.
	 */
	public static function salts_real(): bool {
		foreach ( array( 'AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_KEY', 'SECURE_AUTH_SALT' ) as $c ) {
			if ( ! defined( $c ) || strlen( (string) constant( $c ) ) < 32 || 'put your unique phrase here' === constant( $c ) ) {
				return false;
			}
		}
		return true;
	}

	/** The site-salt key the stored key is sealed under. */
	private static function wrap_key(): ?string {
		if ( ! function_exists( 'wp_salt' ) || ! self::salts_real() ) {
			return null;
		}
		$salt = (string) wp_salt( 'secure_auth' );
		if ( '' === $salt ) {
			return null;
		}
		return sodium_crypto_generichash( 'zdz-sealed-v1|agreements-key|' . $salt, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	private static function wrap( string $key ): ?string {
		$wk = self::wrap_key();
		if ( null === $wk ) {
			return null;
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$out   = 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $key, $nonce, $wk ) );
		sodium_memzero( $wk );
		return $out;
	}

	private static function unwrap( string $w ): ?string {
		if ( 0 !== strpos( $w, 'v1:' ) ) {
			return null;
		}
		$raw = base64_decode( substr( $w, 3 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}
		$wk = self::wrap_key();
		if ( null === $wk ) {
			return null;
		}
		$k = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $wk );
		sodium_memzero( $wk );
		return ( false === $k || 32 !== strlen( $k ) ) ? null : $k;
	}

	public static function record(): array {
		$r = get_option( self::OPT, array() );
		return is_array( $r ) ? $r : array();
	}

	/**
	 * Where the key stands: { state, source }. state: ok | none | locked | mismatch | bad_constant | no_sodium.
	 * The key itself stays inside this class.
	 */
	public static function state(): array {
		$s = self::resolve();
		return array( 'state' => $s['state'], 'source' => $s['source'] );
	}

	private static function resolve(): array {
		if ( null !== self::$mem ) {
			return self::$mem;
		}
		if ( ! self::sodium() ) {
			return self::$mem = array( 'state' => 'no_sodium', 'source' => '', 'key' => null );
		}
		$rec   = self::record();
		$check = (string) ( $rec['check'] ?? '' );
		if ( defined( 'ZDZ_AGREEMENTS_KEY' ) ) {
			$k = self::parse( (string) constant( 'ZDZ_AGREEMENTS_KEY' ) );
			if ( null === $k ) {
				return self::$mem = array( 'state' => 'bad_constant', 'source' => 'constant', 'key' => null );
			}
			if ( '' !== $check && ! hash_equals( $check, self::check_value( $k ) ) ) {
				return self::$mem = array( 'state' => 'mismatch', 'source' => 'constant', 'key' => null );
			}
			if ( '' === $check ) {
				// Remember the constant's check value (never the key), so a later different constant is caught.
				$rec['check'] = self::check_value( $k );
				$rec['v']     = 1;
				update_option( self::OPT, $rec, false );
			}
			return self::$mem = array( 'state' => 'ok', 'source' => 'constant', 'key' => $k );
		}
		if ( ! self::salts_real() ) {
			return self::$mem = array( 'state' => 'no_salts', 'source' => '', 'key' => null );
		}
		if ( empty( $rec['sealed'] ) ) {
			return self::$mem = array( 'state' => 'none', 'source' => '', 'key' => null );
		}
		$k = self::unwrap( (string) $rec['sealed'] );
		if ( null === $k ) {
			return self::$mem = array( 'state' => 'locked', 'source' => 'stored', 'key' => null );
		}
		if ( '' !== $check && ! hash_equals( $check, self::check_value( $k ) ) ) {
			return self::$mem = array( 'state' => 'mismatch', 'source' => 'stored', 'key' => null );
		}
		return self::$mem = array( 'state' => 'ok', 'source' => 'stored', 'key' => $k );
	}

	public static function usable(): bool {
		return 'ok' === self::resolve()['state'];
	}

	/** Plain words for where the key stands (the Key panel, and the not-ready reason). Never a value. */
	public static function state_words(): string {
		switch ( self::resolve()['state'] ) {
			case 'ok':
				return 'constant' === self::resolve()['source'] ? 'The agreements key is set in wp-config.php.' : 'The agreements key is entered.';
			case 'none':
				return 'No agreements key has been entered yet.';
			case 'locked':
				return 'Enter your agreements key again. The site\'s secret keys changed, so the stored copy no longer opens.';
			case 'mismatch':
				return 'The agreements key does not match the one your agreements were sealed with. Enter the key you saved.';
			case 'bad_constant':
				return 'ZDZ_AGREEMENTS_KEY in wp-config.php is not a key (it must be 32 bytes written in base64).';
			case 'no_salts':
				return 'This site\'s secret keys aren\'t set in wp-config.php (AUTH_KEY, AUTH_SALT, SECURE_AUTH_KEY, SECURE_AUTH_SALT), so a key kept here would open from a copy of the database. Set them, or put the key in ZDZ_AGREEMENTS_KEY.';
			default:
				return 'This server cannot seal (PHP sodium is missing).';
		}
	}

	/** Forget this request's answer (after a change, and in the suites). */
	public static function forget(): void {
		if ( null !== self::$mem && is_string( self::$mem['key'] ?? null ) ) {
			$k = self::$mem['key'];
			sodium_memzero( $k );
		}
		self::$mem = null;
	}

	/**
	 * Enter a key (from the Key panel): the pasted base64. Returns { ok, message }; the message never holds the key.
	 * A key whose check value differs from the stored one is refused, unless no agreement exists yet and $replace is set.
	 */
	public static function enter( string $b64, int $uid, bool $replace = false ): array {
		if ( ! self::sodium() ) {
			return array( 'ok' => false, 'message' => 'This server cannot seal (PHP sodium is missing). Nothing was saved.' );
		}
		if ( defined( 'ZDZ_AGREEMENTS_KEY' ) ) {
			return array( 'ok' => false, 'message' => 'The key is set in wp-config.php (ZDZ_AGREEMENTS_KEY), which wins over this screen. Change it there. Nothing was saved.' );
		}
		if ( ! self::salts_real() ) {
			return array( 'ok' => false, 'message' => 'This site\'s secret keys aren\'t set in wp-config.php, so the key can\'t be kept safely here. Set them, or put the key in ZDZ_AGREEMENTS_KEY. Nothing was saved.' );
		}
		$k = self::parse( $b64 );
		if ( null === $k ) {
			return array( 'ok' => false, 'message' => 'That isn\'t a key from this screen. A key is 44 characters of letters, numbers, +, /, and =. Nothing was saved.' );
		}
		$rec   = self::record();
		$check = (string) ( $rec['check'] ?? '' );
		$new   = self::check_value( $k );
		$again = '' !== $check && hash_equals( $check, $new );
		if ( '' !== $check && ! $again ) {
			if ( ! $replace || ZAGR_Store::count_all() > 0 ) {
				sodium_memzero( $k );
				return array( 'ok' => false, 'message' => 'That key doesn\'t match the one your agreements were sealed with. Enter the key you saved when you made it. Nothing was saved.' );
			}
		}
		$sealed = self::wrap( $k );
		sodium_memzero( $k );
		if ( null === $sealed ) {
			return array( 'ok' => false, 'message' => 'This site\'s secret keys aren\'t set in wp-config.php, so the key can\'t be kept safely. Nothing was saved.' );
		}
		update_option( self::OPT, array(
			'v'      => 1,
			'sealed' => $sealed,
			'check'  => $new,
			'set_at' => ZAGR_Clock::utc(),
			'set_by' => $uid,
		), false );
		self::forget();
		return array( 'ok' => true, 'message' => $again ? 'The key is entered again. Agreements can be signed.' : 'The key is saved. Keep your copy in your password manager: without it, signed agreements can\'t be opened after the site\'s secret keys change.' );
	}

	/* ---------------------------------------------------------------- sealing */

	private static function subkey( int $id ): ?string {
		$s = self::resolve();
		if ( 'ok' !== $s['state'] ) {
			return null;
		}
		return sodium_crypto_kdf_derive_from_key( 32, $id, self::KDF_CTX, $s['key'] );
	}

	/** Seal one value; $ad names the field (associated data). Null when there is no usable key (fail closed). */
	public static function seal( string $plain, string $ad ): ?string {
		$sk = self::subkey( self::SUB_FIELDS );
		if ( null === $sk ) {
			return null;
		}
		$nonce = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$ct    = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plain, 'zagr|' . $ad, $nonce, $sk );
		sodium_memzero( $sk );
		return self::PREFIX . base64_encode( $nonce . $ct );
	}

	/** Open one sealed value; null for no key, a wrong key, another field's value, or anything forged. */
	public static function open( ?string $sealed, string $ad ): ?string {
		if ( null === $sealed || 0 !== strpos( $sealed, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $sealed, strlen( self::PREFIX ) ), true );
		$nl  = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
		if ( false === $raw || strlen( $raw ) < $nl + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES ) {
			return null;
		}
		$sk = self::subkey( self::SUB_FIELDS );
		if ( null === $sk ) {
			return null;
		}
		$pt = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( substr( $raw, $nl ), 'zagr|' . $ad, substr( $raw, 0, $nl ), $sk );
		sodium_memzero( $sk );
		return false === $pt ? null : $pt;
	}

	/** Encrypt a file's bytes (the signed PDF): magic + nonce + ciphertext; null without a key. */
	public static function seal_file( string $bytes, string $ad ): ?string {
		$sk = self::subkey( self::SUB_FILES );
		if ( null === $sk ) {
			return null;
		}
		$nonce = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$ct    = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $bytes, 'zagr-file|' . $ad, $nonce, $sk );
		sodium_memzero( $sk );
		return self::FILE_MAGIC . $nonce . $ct;
	}

	public static function open_file( string $blob, string $ad ): ?string {
		$m  = strlen( self::FILE_MAGIC );
		$nl = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
		if ( 0 !== strpos( $blob, self::FILE_MAGIC ) || strlen( $blob ) < $m + $nl + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES ) {
			return null;
		}
		$sk = self::subkey( self::SUB_FILES );
		if ( null === $sk ) {
			return null;
		}
		$pt = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( substr( $blob, $m + $nl ), 'zagr-file|' . $ad, substr( $blob, $m, $nl ), $sk );
		sodium_memzero( $sk );
		return false === $pt ? null : $pt;
	}

	/**
	 * The record's link hash: an HMAC under the "agreements-chain" subkey, so someone who can only write the
	 * database can't recompute the chain after editing it. Null without a usable key.
	 */
	public static function chain_mac( string $line ): ?string {
		$sk = self::subkey( self::SUB_CHAIN );
		if ( null === $sk ) {
			return null;
		}
		$m = hash_hmac( 'sha256', 'agreements-chain|' . $line, $sk );
		sodium_memzero( $sk );
		return $m;
	}

	/** A MAC under the codes subkey (the emailed codes are kept only as this). Null without a key. */
	public static function mac( string $msg ): ?string {
		$sk = self::subkey( self::SUB_CODES );
		if ( null === $sk ) {
			return null;
		}
		$m = hash_hmac( 'sha256', $msg, $sk );
		sodium_memzero( $sk );
		return $m;
	}
}
