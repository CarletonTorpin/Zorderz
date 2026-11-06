<?php
/**
 * ZDZ_Outbound — how the platform calls an address an administrator configured (a map layer, a geocoder, a model
 * server, the Charts hub).
 *
 * send() makes the request through wp_safe_remote_request(), which checks the host's address again when the request is
 * made and refuses a loopback or private one, and validates every redirect the same way. Redirects are capped at what
 * the caller allows (0 for anything that carries a key). An explicit port in the configured address stays allowed for
 * that one request (a map server on 6443, a gateway on 8443), so the safe call never breaks a working setting.
 *
 * Some services sit on a private address by design: the Charts hub listens only on loopback or a private overlay
 * network, and a local model server answers on the business's own network. A caller that says so ($private_ok) is
 * sent there directly when the host is private (private_host(): loopback, the private ranges, the shared range
 * 100.64.0.0/10 that overlay networks use, link-local, "localhost", or a name that resolves only to such addresses).
 * A public host still goes through the safe call. Everything else may reach a private address only through
 * WordPress's own `http_request_host_is_external` filter.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ZDZ_Outbound {

	/** Tests: callable( string $how, string $method, string $url, array $args ) — $how is 'safe' or 'direct'. */
	public static $transport = null;

	/** Tests: callable( string $host ): string[] — the addresses a name resolves to. */
	public static $resolve = null;

	/**
	 * Request an administrator-configured address.
	 *
	 * @param string $method        GET or POST
	 * @param bool   $private_ok    the service sits on a private address by design
	 * @param int    $max_redirects at most this many redirects (each one checked again)
	 * @return array|WP_Error
	 */
	public static function send( string $method, string $url, array $args, bool $private_ok = false, int $max_redirects = 0 ) {
		$args['method']      = strtoupper( $method );
		$args['redirection'] = max( 0, min( $max_redirects, isset( $args['redirection'] ) ? (int) $args['redirection'] : $max_redirects ) );
		$direct              = $private_ok && self::private_host( $url );
		if ( is_callable( self::$transport ) ) {
			return call_user_func( self::$transport, $direct ? 'direct' : 'safe', $args['method'], $url, $args );
		}
		if ( $direct ) {
			$args['reject_unsafe_urls'] = false;
			return wp_remote_request( $url, $args );
		}
		$port  = (int) parse_url( $url, PHP_URL_PORT );
		$allow = null;
		if ( $port > 0 && function_exists( 'add_filter' ) ) {
			$host  = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
			$allow = function ( $ports, $h = '' ) use ( $port, $host ) {
				return strtolower( (string) $h ) === $host ? array_values( array_unique( array_merge( (array) $ports, array( $port ) ) ) ) : $ports;
			};
			add_filter( 'http_allowed_safe_ports', $allow, 10, 2 );
		}
		$r = wp_safe_remote_request( $url, $args );
		if ( null !== $allow && function_exists( 'remove_filter' ) ) {
			remove_filter( 'http_allowed_safe_ports', $allow, 10 );
		}
		return $r;
	}

	/** Is the URL's host on a loopback, private, shared (overlay) or link-local address, or named so? */
	public static function private_host( string $url ): bool {
		$host = strtolower( trim( (string) parse_url( $url, PHP_URL_HOST ), '[]' ) );
		if ( '' === $host ) {
			return false;
		}
		if ( 'localhost' === $host || '.localhost' === substr( $host, -10 ) ) {
			return true;
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::is_private_ip( $host );
		}
		$ips = is_callable( self::$resolve ) ? (array) call_user_func( self::$resolve, $host )
			: ( function_exists( 'gethostbynamel' ) ? (array) gethostbynamel( $host ) : array() );
		$ips = array_values( array_filter( array_map( 'strval', $ips ) ) );
		if ( ! $ips ) {
			return false;
		}
		foreach ( $ips as $ip ) {
			if ( ! self::is_private_ip( $ip ) ) {
				return false;
			}
		}
		return true;
	}

	/** Loopback, the private ranges, 100.64.0.0/10, link-local, unspecified; IPv6 loopback, unique-local and link-local. */
	public static function is_private_ip( string $ip ): bool {
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$n = ip2long( $ip );
			foreach ( array( array( '0.0.0.0', 8 ), array( '10.0.0.0', 8 ), array( '100.64.0.0', 10 ), array( '127.0.0.0', 8 ),
				array( '169.254.0.0', 16 ), array( '172.16.0.0', 12 ), array( '192.168.0.0', 16 ) ) as $net ) {
				$mask = -1 << ( 32 - $net[1] );
				if ( ( $n & $mask ) === ( ip2long( $net[0] ) & $mask ) ) {
					return true;
				}
			}
			return false;
		}
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$b = inet_pton( $ip );
			if ( false === $b ) {
				return false;
			}
			if ( str_repeat( "\0", 15 ) . "\1" === $b || str_repeat( "\0", 16 ) === $b ) {
				return true;                                              // ::1, ::
			}
			if ( 0xfc === ( ord( $b[0] ) & 0xfe ) ) {
				return true;                                              // fc00::/7
			}
			if ( 0xfe === ord( $b[0] ) && 0x80 === ( ord( $b[1] ) & 0xc0 ) ) {
				return true;                                              // fe80::/10
			}
			if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $b, 0, 12 ) ) {
				return self::is_private_ip( (string) inet_ntop( substr( $b, 12 ) ) );   // ::ffff:a.b.c.d
			}
		}
		return false;
	}
}
