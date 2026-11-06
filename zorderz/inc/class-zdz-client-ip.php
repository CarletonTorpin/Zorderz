<?php
/**
 * ZDZ_Client_IP — the address a request came from, as the platform records it.
 *
 * get() is REMOTE_ADDR: the address of the connection the server accepted, which the person on the
 * other end cannot type. Headers such as X-Forwarded-For, X-Real-IP or CF-Connecting-IP are sent by
 * the client and say whatever the client likes, so they never stand in for it. A site behind a
 * reverse proxy it trusts (one that rewrites the connection) returns that proxy's verified client
 * address on the filter `zdz_client_ip`; whatever comes back must still be a valid IP address, or
 * get() answers ''.
 *
 * forwarded_chain() is the X-Forwarded-For list as the client sent it, valid addresses only and at
 * most five, for an accountability row to keep beside get() under its own label ("forwarded for").
 * It is never used to decide anything.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ZDZ_Client_IP {

	const MAX_FORWARDED = 5;

	/** The request's address: REMOTE_ADDR, or a trusted proxy's value on `zdz_client_ip`. '' when it is not a valid IP. */
	public static function get(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- validated by FILTER_VALIDATE_IP before it is returned; anything else becomes ''
		if ( function_exists( 'apply_filters' ) ) {
			$ip = apply_filters( 'zdz_client_ip', $ip );
		}
		$ip = is_string( $ip ) ? trim( $ip ) : '';
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * The X-Forwarded-For entries as sent: valid IP addresses only, at most MAX_FORWARDED, in order. Client-supplied:
	 * record it under its own label, never as the address and never for a decision.
	 *
	 * @return string[]
	 */
	public static function forwarded_chain(): array {
		$raw = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- only parts that pass FILTER_VALIDATE_IP are kept below
		$out = array();
		foreach ( explode( ',', substr( $raw, 0, 1000 ) ) as $part ) {
			$part = trim( $part );
			if ( '' !== $part && false !== filter_var( $part, FILTER_VALIDATE_IP ) ) {
				$out[] = $part;
				if ( count( $out ) >= self::MAX_FORWARDED ) {
					break;
				}
			}
		}
		return $out;
	}
}
