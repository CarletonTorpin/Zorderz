<?php
/**
 * The Charts sealed envelope: the website's half of the protocol.
 *
 * Every request to the Charts hub and every answer back is one libsodium crypto_box
 * (X25519 key agreement, XSalsa20-Poly1305). A box made with the site's secret key and
 * the hub's public key opens only on the hub, and only proves itself if it was made with
 * the site's secret: one operation gives secrecy and proof of sender. PHP's built-in
 * sodium extension does the work, so there is nothing to install.
 *
 * Wire format (the HTTP body, JSON):
 *   { "v": 1, "kid_from": "<12 hex>", "kid_to": "<12 hex>", "nonce": "<base64url>", "ct": "<base64url>" }
 * Inside the box:
 *   { "v": 1, "from": <party>, "to": <party>, "typ": "request"|"response", "ts": <unix s>, "rid": "<id>", "body": {…} }
 *
 * A receiver refuses, in this order: an unknown version or a box not addressed to its key;
 * an unknown sender key; a box that does not open; the wrong pair of parties; the wrong
 * message type; a timestamp more than two minutes off; for a response, a request id that
 * is not the one asked; a nonce already seen in the last ten minutes.
 *
 * The hub in hub/zorderz_hub/envelope.py implements the same format; the cross-language
 * test in tests/ proves the two interoperate. Keys never reach the browser: only PHP on
 * this server seals and opens.
 *
 * @package Zorderz\Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCH_Envelope_Error extends Exception {}

class ZCH_Envelope {
	const VERSION               = 1;
	const MAX_SKEW_SECONDS      = 120;
	const REPLAY_WINDOW_SECONDS = 600;
	const PARTIES               = array( ZCH_WEB_PARTY, ZCH_HUB_PARTY );

	private $me;
	private $sk;
	private $pk;
	private $my_kid;
	private $peers = array();   // party => [kid => public key bytes], newest first.
	private $replay;            // callable(string $nonce): bool, true when the nonce is new.

	/**
	 * @param string        $me         This side's party label.
	 * @param string        $secret_b64 This side's secret key, base64url.
	 * @param array         $peers_b64  party => list of base64url public keys (newest first).
	 * @param callable|null $replay     Replay check; defaults to WordPress transients.
	 */
	public function __construct( $me, $secret_b64, array $peers_b64, $replay = null ) {
		if ( ! in_array( $me, self::PARTIES, true ) ) {
			throw new InvalidArgumentException( 'unknown party' );
		}
		$this->me = $me;
		$this->sk = self::b64d( $secret_b64 );
		if ( strlen( $this->sk ) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES ) {
			throw new ZCH_Envelope_Error( 'bad key' );
		}
		$this->pk     = sodium_crypto_box_publickey_from_secretkey( $this->sk );
		$this->my_kid = self::key_id( $this->pk );
		foreach ( $peers_b64 as $party => $keys ) {
			foreach ( (array) $keys as $k ) {
				$raw = self::b64d( trim( (string) $k ) );
				if ( strlen( $raw ) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES ) {
					throw new ZCH_Envelope_Error( 'bad key' );
				}
				$this->peers[ $party ][ self::key_id( $raw ) ] = $raw;
			}
		}
		$this->replay = $replay ? $replay : array( $this, 'transient_replay_check' );
	}

	public static function b64e( $raw ) {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	public static function b64d( $text ) {
		$text = (string) $text;
		$out  = base64_decode( strtr( $text, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $text ) % 4 ) % 4 ), true );
		if ( false === $out ) {
			throw new ZCH_Envelope_Error( 'bad encoding' );
		}
		return $out;
	}

	/** The first 12 hex characters of the SHA-256 of a public key. */
	public static function key_id( $public_key ) {
		return substr( hash( 'sha256', $public_key ), 0, 12 );
	}

	/** This side's public key, base64url (for the settings page). */
	public function public_key() {
		return self::b64e( $this->pk );
	}

	/** A new key pair: [ secret base64url, public base64url ]. Used by the settings page and the tests. */
	public static function keygen() {
		$pair = sodium_crypto_box_keypair();
		return array( self::b64e( sodium_crypto_box_secretkey( $pair ) ), self::b64e( sodium_crypto_box_publickey( $pair ) ) );
	}

	public function seal( $to, $typ, $body, $rid = null, $now = null ) {
		if ( empty( $this->peers[ $to ] ) || ! in_array( $typ, array( 'request', 'response' ), true ) ) {
			throw new InvalidArgumentException( 'bad seal arguments' );
		}
		// The newest key first. A key id that happens to be all digits becomes an integer array key in PHP, so it is
		// turned back into the string the other side compares against.
		$kid_to = (string) array_key_first( $this->peers[ $to ] );
		$inner  = array(
			'v'    => self::VERSION,
			'from' => $this->me,
			'to'   => $to,
			'typ'  => $typ,
			'ts'   => (int) ( null !== $now ? $now : time() ),
			'rid'  => null !== $rid ? $rid : self::b64e( random_bytes( 12 ) ),
			'body' => $body,
		);
		$nonce = random_bytes( SODIUM_CRYPTO_BOX_NONCEBYTES );
		$pair  = sodium_crypto_box_keypair_from_secretkey_and_publickey( $this->sk, $this->peers[ $to ][ $kid_to ] );
		$ct    = sodium_crypto_box( json_encode( $inner, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), $nonce, $pair );
		return array(
			'v'        => self::VERSION,
			'kid_from' => $this->my_kid,
			'kid_to'   => $kid_to,
			'nonce'    => self::b64e( $nonce ),
			'ct'       => self::b64e( $ct ),
		);
	}

	public function open( array $outer, $expect_from, $expect_typ, $expect_rid = null, $now = null ) {
		$now = null !== $now ? $now : time();
		if ( ( $outer['v'] ?? null ) !== self::VERSION || (string) ( $outer['kid_to'] ?? '' ) !== $this->my_kid ) {
			throw new ZCH_Envelope_Error( 'wrong version or not addressed to this key' );
		}
		$pk = $this->peers[ $expect_from ][ $outer['kid_from'] ?? '' ] ?? null;
		if ( null === $pk ) {
			throw new ZCH_Envelope_Error( 'unknown sender key' );
		}
		$nonce = self::b64d( $outer['nonce'] ?? '' );
		if ( strlen( $nonce ) !== SODIUM_CRYPTO_BOX_NONCEBYTES ) {
			throw new ZCH_Envelope_Error( 'cannot open' );
		}
		$pair  = sodium_crypto_box_keypair_from_secretkey_and_publickey( $this->sk, $pk );
		$plain = sodium_crypto_box_open( self::b64d( $outer['ct'] ?? '' ), $nonce, $pair );
		if ( false === $plain ) {
			throw new ZCH_Envelope_Error( 'cannot open' );
		}
		$inner = json_decode( $plain, true );
		if ( ! is_array( $inner ) || ( $inner['v'] ?? null ) !== self::VERSION
			|| ( $inner['from'] ?? '' ) !== $expect_from || ( $inner['to'] ?? '' ) !== $this->me ) {
			throw new ZCH_Envelope_Error( 'wrong parties' );
		}
		if ( ( $inner['typ'] ?? '' ) !== $expect_typ ) {
			throw new ZCH_Envelope_Error( 'wrong message type' );
		}
		if ( abs( $now - (int) ( $inner['ts'] ?? 0 ) ) > self::MAX_SKEW_SECONDS ) {
			throw new ZCH_Envelope_Error( 'stale or future timestamp' );
		}
		if ( null !== $expect_rid && ! hash_equals( (string) $expect_rid, (string) ( $inner['rid'] ?? '' ) ) ) {
			throw new ZCH_Envelope_Error( 'response does not match request' );
		}
		if ( ! call_user_func( $this->replay, $outer['nonce'] ) ) {
			throw new ZCH_Envelope_Error( 'replay' );
		}
		return $inner;
	}

	/** A nonce may be used once in the replay window (WordPress transients). */
	public function transient_replay_check( $nonce ) {
		$key = 'zch_n_' . substr( hash( 'sha256', (string) $nonce ), 0, 32 );
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, self::REPLAY_WINDOW_SECONDS );
		return true;
	}
}
