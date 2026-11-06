<?php
/**
 * ZDZ_Push_Endpoint — the one check every app runs on a Web Push endpoint before it stores one and
 * again before it sends to one.
 *
 * A Web Push endpoint is a URL the browser hands to the page, and the server later POSTs to it. Left
 * unchecked it is a URL any signed-in person chooses, and the server would POST to an internal host,
 * a loopback port or a redirector on their behalf. So an endpoint is accepted only when it is:
 *
 *   - https, on port 443 (none written, or 443), with no user name or password in it;
 *   - on a host name (never an IP address in any spelling) that is one of the browser push services:
 *       fcm.googleapis.com                  Chrome, Edge on Android, Opera, Samsung Internet, Brave, Vivaldi
 *       updates.push.services.mozilla.com   Firefox
 *       web.push.apple.com, *.push.apple.com Safari (macOS, iOS and iPadOS home-screen apps)
 *       *.notify.windows.com                Edge on Windows
 *     ("*." matches any name below that domain, never the domain itself);
 *   - plain text: no spaces, quotes, angle brackets, backslashes or fragment, at most 2048 characters.
 *
 * A business whose people use another push service adds its host on the filter
 * `zdz_push_endpoint_hosts` (an array of host rules in the same form). The filter can only add: the
 * hosts above always stay, and a rule that is an IP address, carries a scheme, port or path, or is a
 * wildcard over a bare top-level name ("*.com") is ignored.
 *
 * post() is the only way an app sends: it checks the endpoint again (rows stored before this check
 * existed are refused there, and the caller drops them), then sends with wp_safe_remote_post(), which
 * also refuses a name that resolves to a private or loopback address, and with redirects off. The
 * caller gets the status code back, never the push service's response body; status_words() turns the
 * code into words for a person.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ZDZ_Push_Endpoint {

	/** The browser push services every install sends to. */
	const CORE_HOSTS = array(
		'fcm.googleapis.com',
		'updates.push.services.mozilla.com',
		'web.push.apple.com',
		'*.push.apple.com',
		'*.notify.windows.com',
	);

	const MAX_LENGTH = 2048;

	/** Tests hand in a transport: callable( string $url, array $args ): array|WP_Error. */
	public static $transport = null;

	/**
	 * The host rules in force: Core's, plus any valid rule added on `zdz_push_endpoint_hosts`.
	 *
	 * @return string[]
	 */
	public static function hosts(): array {
		$rules = self::CORE_HOSTS;
		$added = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_push_endpoint_hosts', self::CORE_HOSTS ) : array();
		foreach ( is_array( $added ) ? $added : array() as $rule ) {
			$rule = self::clean_rule( $rule );
			if ( '' !== $rule && ! in_array( $rule, $rules, true ) ) {
				$rules[] = $rule;
			}
		}
		return $rules;
	}

	/** One host rule as written on the filter, lower-cased; '' when it is not a plain host name (or "*." + one). */
	public static function clean_rule( $rule ): string {
		if ( ! is_string( $rule ) ) {
			return '';
		}
		$r    = strtolower( trim( $rule ) );
		$wild = 0 === strpos( $r, '*.' );
		$name = $wild ? substr( $r, 2 ) : $r;
		if ( ! self::is_host_name( $name ) ) {
			return '';
		}
		if ( $wild && substr_count( $name, '.' ) < 1 ) {
			return '';   // "*.com" would match half the internet
		}
		return $r;
	}

	/**
	 * The endpoint, trimmed, when it may be stored and sent to; '' when it may not.
	 *
	 * @param mixed $url
	 */
	public static function validate( $url ): string {
		if ( ! is_string( $url ) ) {
			return '';
		}
		$u = trim( $url );
		if ( '' === $u || strlen( $u ) > self::MAX_LENGTH || preg_match( '/[\x00-\x20\x7f<>"\'\\\\`{}|^#]/', $u ) ) {
			return '';
		}
		if ( 0 !== strncasecmp( $u, 'https://', 8 ) ) {
			return '';
		}
		// The authority as written, read by hand: it must agree with what the URL parser reads.
		$rest      = substr( $u, 8 );
		$cut       = strcspn( $rest, '/?' );
		$authority = strtolower( substr( $rest, 0, $cut ) );
		if ( '' === $authority || false !== strpos( $authority, '@' ) || false !== strpos( $authority, '[' ) ) {
			return '';
		}
		$p = parse_url( $u );
		if ( ! is_array( $p ) || 'https' !== strtolower( (string) ( $p['scheme'] ?? '' ) ) || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['fragment'] ) ) {
			return '';
		}
		$host = strtolower( (string) ( $p['host'] ?? '' ) );
		$port = isset( $p['port'] ) ? (int) $p['port'] : 0;
		if ( 0 !== $port && 443 !== $port ) {
			return '';
		}
		if ( $authority !== $host && $authority !== $host . ':443' ) {
			return '';
		}
		return self::host_allowed( $host ) ? $u : '';
	}

	/** Is this host name one of the push services in force? An IP address never is. */
	public static function host_allowed( string $host ): bool {
		$host = strtolower( $host );
		if ( ! self::is_host_name( $host ) ) {
			return false;
		}
		foreach ( self::hosts() as $rule ) {
			if ( 0 === strpos( $rule, '*.' ) ) {
				$suffix = substr( $rule, 1 );   // ".push.apple.com"
				if ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) {
					return true;
				}
			} elseif ( $host === $rule ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A DNS host name: letters, digits and hyphens in dot-separated labels, at least two labels, and a last
	 * label that is not all digits (so 127.0.0.1, 127.1, 2130706433 and 0x7f.0x1 are never names).
	 */
	public static function is_host_name( string $host ): bool {
		if ( '' === $host || strlen( $host ) > 253 || false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host ) ) {
			return false;
		}
		$labels = explode( '.', $host );
		$last   = (string) end( $labels );
		return ! preg_match( '/^(?:[0-9]+|0x[0-9a-f]*)$/', $last );
	}

	/**
	 * POST to a push endpoint. The endpoint is checked first (a WP_Error with code `zdz_push_endpoint_refused`
	 * when it fails: the caller drops that subscription), then sent with wp_safe_remote_post() and redirects off.
	 *
	 * @return array|WP_Error The HTTP response, or a WP_Error.
	 */
	public static function post( string $url, array $args ) {
		if ( '' === self::validate( $url ) ) {
			return new WP_Error( 'zdz_push_endpoint_refused', 'This subscription is not on a known push service.' );
		}
		$args['redirection']        = 0;
		$args['reject_unsafe_urls'] = true;
		if ( is_callable( self::$transport ) ) {
			return call_user_func( self::$transport, $url, $args );
		}
		if ( ! function_exists( 'wp_safe_remote_post' ) ) {
			return new WP_Error( 'zdz_push_no_transport', 'No HTTP transport.' );
		}
		return wp_safe_remote_post( $url, $args );
	}

	/** A push service's status code in a person's words (never the service's own text). */
	public static function status_words( int $status ): string {
		if ( 0 === $status ) {
			return __( 'it could not be reached', 'zorderz' );
		}
		if ( 401 === $status || 403 === $status ) {
			return __( 'it did not accept this site\'s key', 'zorderz' );
		}
		if ( 404 === $status || 410 === $status ) {
			return __( 'this subscription has ended', 'zorderz' );
		}
		if ( 413 === $status ) {
			return __( 'the message was too large', 'zorderz' );
		}
		if ( 429 === $status ) {
			return __( 'too many messages for now; try again later', 'zorderz' );
		}
		if ( $status >= 500 ) {
			return __( 'it had a problem of its own; try again later', 'zorderz' );
		}
		if ( $status >= 300 && $status < 400 ) {
			return __( 'it tried to send the message elsewhere', 'zorderz' );
		}
		return __( 'it refused the message', 'zorderz' );
	}
}
