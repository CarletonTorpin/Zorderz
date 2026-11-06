<?php
/**
 * ZDZ_Login_Next — "take me back to what I asked for" after signing in.
 *
 * A link to a page inside the app (a knowledge-vault document, /vault/{slug}) that is opened
 * somewhere signed OUT — a phone browser whose Home Screen app holds the session, a desktop
 * browser, a message e-mail — would otherwise lose its address: the page sends the visitor to
 * /login/, and every sign-in path (the login page, the magic-link sign-in, the PWA bridge's token
 * and code claims) then goes to the home page.
 *
 * The page that needs a sign-in remembers its own PATH (never a full URL) in two places — a
 * `zdz_next` query argument on /login/ and a short-lived `zdz_next` cookie — and every sign-in
 * path asks this class where to go next. Only a plain same-site path under an allowed prefix is
 * ever followed, so this cannot become an open redirect:
 *
 *   - same site: a path, or an absolute URL on THIS site's host (reduced to its path);
 *   - plain: letters, digits, "/", "_", "-", "." only; no "//", "\", "..", query or fragment;
 *   - never the sign-in pages themselves, wp-admin, or "/";
 *   - allow-listed: the path starts with a prefix from allowed_prefixes() — "/vault/" by default;
 *     an app adds its own in-app pages on the `zdz_login_next_prefixes` filter.
 *
 * Which pages remember themselves is the caller's business (the knowledge app calls remember());
 * this class only validates and hands the path back.
 *
 * @package Zorderz
 */

defined( 'ABSPATH' ) || exit;

class ZDZ_Login_Next {

	/** Query argument and cookie name. */
	const KEY = 'zdz_next';

	/** In-app pages a sign-in may return to when no app has added its own. */
	const DEFAULT_PREFIXES = array( '/vault/' );

	/**
	 * The path prefixes a sign-in may return to: DEFAULT_PREFIXES plus what apps add on the
	 * `zdz_login_next_prefixes` filter. A prefix must itself be a plain path that starts and ends
	 * with "/" and is not "/" alone, so a filter can never open the whole site.
	 *
	 * @return string[]
	 */
	public static function allowed_prefixes() {
		$p = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_login_next_prefixes', self::DEFAULT_PREFIXES ) : self::DEFAULT_PREFIXES;
		$out = array();
		foreach ( (array) $p as $x ) {
			if ( is_string( $x ) && '/' !== $x && preg_match( '#^/[A-Za-z0-9_\-./]+/$#', $x ) && false === strpos( $x, '..' ) && false === strpos( $x, '//' ) ) {
				$out[] = $x;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Cookie lifetime: long enough to request a code and read an email. */
	const TTL = 1800;

	/**
	 * Reduce a candidate to a safe same-site path, or ''.
	 *
	 * Accepts "/vault/x" or an absolute URL on THIS site's host (reduced to its
	 * path). Refuses: other hosts, protocol-relative "//", backslashes, "..",
	 * query strings and fragments, the sign-in pages themselves, wp-admin, "/",
	 * and any path outside allowed_prefixes().
	 *
	 * @param mixed $candidate
	 * @return string
	 */
	public static function clean( $candidate ) {
		if ( ! is_string( $candidate ) ) {
			return '';
		}
		$c = trim( $candidate );
		// One level of URL-encoding is normal (a query argument); never more.
		if ( false !== strpos( $c, '%' ) ) {
			$c = rawurldecode( $c );
		}
		if ( '' === $c || strlen( $c ) > 300 ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $c ) ) {
			$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
			$host      = strtolower( (string) wp_parse_url( $c, PHP_URL_HOST ) );
			if ( '' === $home_host || $host !== $home_host ) {
				return '';
			}
			$path = wp_parse_url( $c, PHP_URL_PATH );
			$c    = is_string( $path ) ? $path : '';
		}
		if ( '' === $c || '/' !== $c[0] || 0 === strpos( $c, '//' ) ) {
			return '';
		}
		if ( ! preg_match( '#^/[A-Za-z0-9/_\-.]*$#', $c ) || false !== strpos( $c, '..' ) ) {
			return '';
		}
		if ( '/' === $c || preg_match( '#^/(?:login|register|wp-login\.php|wp-admin)(?:/|$)#i', $c ) ) {
			return '';
		}
		foreach ( self::allowed_prefixes() as $prefix ) {
			if ( 0 === strpos( $c, $prefix ) && strlen( $c ) > strlen( $prefix ) ) {
				return $c;
			}
		}
		return '';
	}

	/**
	 * Remember a path in the short-lived cookie (call before any output).
	 *
	 * @param string $path
	 * @return string the cleaned path that was remembered, or ''.
	 */
	public static function remember( $path ) {
		$path = self::clean( $path );
		if ( '' === $path || headers_sent() ) {
			return $path;
		}
		setcookie( self::KEY, $path, array(
			'expires'  => time() + self::TTL,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		) );
		$_COOKIE[ self::KEY ] = $path;
		return $path;
	}

	/** Drop the cookie once it has been used. */
	public static function forget() {
		if ( isset( $_COOKIE[ self::KEY ] ) && ! headers_sent() ) {
			setcookie( self::KEY, '', array(
				'expires'  => time() - 3600,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			) );
		}
		unset( $_COOKIE[ self::KEY ] );
	}

	/**
	 * Read the zdz_next argument out of a URL's query string (also when that URL
	 * was itself URL-encoded inside another one, as a magic-link sign-in sometimes does).
	 *
	 * @param string $url
	 * @return string cleaned path or ''
	 */
	public static function from_url( $url ) {
		if ( ! is_string( $url ) || false === strpos( $url, self::KEY ) ) {
			return '';
		}
		foreach ( array( $url, rawurldecode( $url ) ) as $u ) {
			if ( preg_match( '/[?&]' . self::KEY . '=([^&#\s"\']+)/', $u, $m ) ) {
				$p = self::clean( $m[1] );
				if ( '' !== $p ) {
					return $p;
				}
			}
		}
		return '';
	}

	/**
	 * Where to go after signing in: the first valid path among the given URLs'
	 * zdz_next arguments, this request's ?zdz_next=, then the cookie.
	 *
	 * @param string[] $urls e.g. the login_redirect filter's $redirect_to / $requested
	 * @return string cleaned path or ''
	 */
	public static function from_request( array $urls = array() ) {
		foreach ( $urls as $u ) {
			$p = self::from_url( (string) $u );
			if ( '' !== $p ) {
				return $p;
			}
		}
		if ( isset( $_GET[ self::KEY ] ) ) {
			$p = self::clean( wp_unslash( (string) $_GET[ self::KEY ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- self::clean() keeps only a same-site path matching ^/[A-Za-z0-9/_.-]*$ and returns '' for anything else
			if ( '' !== $p ) {
				return $p;
			}
		}
		if ( isset( $_COOKIE[ self::KEY ] ) ) {
			return self::clean( wp_unslash( (string) $_COOKIE[ self::KEY ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- self::clean() keeps only a same-site path matching ^/[A-Za-z0-9/_.-]*$ and returns '' for anything else
		}
		return '';
	}

	/**
	 * The full URL to land on after signing in, or $fallback. Forgets the cookie
	 * when a remembered path is used.
	 *
	 * @param string   $fallback
	 * @param string[] $urls
	 * @return string
	 */
	public static function url_or( $fallback, array $urls = array() ) {
		$p = self::from_request( $urls );
		if ( '' === $p ) {
			return $fallback;
		}
		self::forget();
		return home_url( $p );
	}

	/**
	 * template_redirect (priority 9): a signed-in visitor who lands on the home page
	 * carrying ?zdz_next= (a magic-link redirect that bypassed login_redirect) goes on
	 * to the remembered page instead of stopping at the dashboard.
	 */
	public static function maybe_continue_from_home() {
		if ( ! is_user_logged_in() || ! isset( $_GET[ self::KEY ] ) ) {
			return;
		}
		if ( wp_doing_ajax() || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
			return;
		}
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- only its path is compared with '/' and ''; never printed or stored
		if ( '/' !== $path && '' !== $path ) {
			return; // only the home page; a remembered path is never followed from elsewhere
		}
		$next = self::clean( wp_unslash( (string) $_GET[ self::KEY ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- self::clean() keeps only a same-site path matching ^/[A-Za-z0-9/_.-]*$ and returns '' for anything else
		if ( '' === $next ) {
			return;
		}
		self::forget();
		wp_safe_redirect( home_url( $next ) );
		exit;
	}
}

add_action( 'template_redirect', array( 'ZDZ_Login_Next', 'maybe_continue_from_home' ), 9 );
