<?php
/**
 * ZL_OAuth_State — the CSRF `state` for Leads' billing sign-in (OAuth connect → callback).
 *
 * Without it, anyone could send an admin a link to the callback carrying the attacker's own authorization code, and
 * the site would quietly connect to the attacker's billing account. So the Connect button goes through a start
 * action that issues a state, and the callback refuses to exchange a code unless that state comes back intact:
 *
 *   state = <nonce>.<expiry>.<signature>
 *     nonce      32 random hex characters, single use
 *     expiry     a unix time TTL seconds ahead
 *     signature  HMAC-SHA256 over "<user id>|<nonce>|<expiry>" with the site's auth salt
 *
 * The nonce is also stored as a transient (keyed by the nonce, holding the user id) for exactly as long as the state
 * lives; verify() deletes it, so a state works once, for the person it was issued to, before it expires.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZL_OAuth_State {

	/** How long a sign-in may take, in seconds. */
	const TTL = 600;

	/** Transient key prefix (the nonce follows). */
	const PREFIX = 'zl_oauth_state_';

	/** The signature over one user, nonce and expiry. PURE given the salt. */
	public static function sign( int $uid, string $nonce, int $exp ): string {
		$salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
		return hash_hmac( 'sha256', $uid . '|' . $nonce . '|' . $exp, 'zl-oauth-state|' . $salt );
	}

	/**
	 * Issue a state for this person and remember its nonce until it expires.
	 *
	 * @param int      $uid The signed-in admin.
	 * @param int|null $now Unix time (tests).
	 * @return string The state to send with the authorize request ('' when nobody is signed in).
	 */
	public static function issue( int $uid, ?int $now = null ): string {
		if ( $uid <= 0 ) {
			return '';
		}
		$now   = null === $now ? time() : $now;
		$nonce = bin2hex( random_bytes( 16 ) );
		$exp   = $now + self::TTL;
		set_transient( self::PREFIX . $nonce, $uid, self::TTL );
		return $nonce . '.' . $exp . '.' . self::sign( $uid, $nonce, $exp );
	}

	/**
	 * Check a state that came back on the callback, and use it up.
	 *
	 * @param string   $state What the callback received.
	 * @param int      $uid   The signed-in admin on the callback.
	 * @param int|null $now   Unix time (tests).
	 * @return string '' when it is good; else why not: missing | malformed | bad-signature | expired | used.
	 */
	public static function verify( string $state, int $uid, ?int $now = null ): string {
		$now = null === $now ? time() : $now;
		if ( '' === $state ) {
			return 'missing';
		}
		if ( ! preg_match( '/^([a-f0-9]{32})\.([0-9]{9,11})\.([a-f0-9]{64})$/', $state, $m ) ) {
			return 'malformed';
		}
		list( , $nonce, $exp, $sig ) = $m;
		$exp = (int) $exp;
		if ( $uid <= 0 || ! hash_equals( self::sign( $uid, $nonce, $exp ), $sig ) ) {
			return 'bad-signature'; // forged, altered, or issued to someone else
		}
		$key    = self::PREFIX . $nonce;
		$holder = get_transient( $key );
		if ( $exp < $now ) {
			delete_transient( $key );
			return 'expired';
		}
		if ( false === $holder || (int) $holder !== $uid ) {
			return 'used'; // already spent (or never issued on this site)
		}
		delete_transient( $key );
		return '';
	}

	/** The sentence a person sees for a refused state. */
	public static function refusal( string $why ): string {
		if ( 'expired' === $why ) {
			return 'This billing sign-in took too long and has expired. Go back to the Leads settings and press Connect again.';
		}
		if ( 'used' === $why ) {
			return 'This billing sign-in link was already used. Go back to the Leads settings and press Connect again.';
		}
		return 'This billing sign-in did not start from this site, so it was refused and nothing was connected. Go back to the Leads settings and press Connect.';
	}
}
