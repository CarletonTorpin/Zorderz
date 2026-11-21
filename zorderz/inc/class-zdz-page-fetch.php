<?php
/**
 * ZDZ_Page_Fetch — reads one public web page as text, for an address a model wrote.
 *
 * ZDZ_Outbound calls addresses an administrator configured. This class is for the other case: an address that came
 * out of a model's answer (a page a web search cited), which the server opens itself to see whether the words are
 * really on it. Such an address is untrusted from end to end, so everything here is a refusal unless it is plainly a
 * public web page:
 *
 *   address     https only, port 443 only, no user name or password in it, a real host name (never an IP address, a
 *               one-word name, a special-use name such as .local or .internal, or this site itself)
 *   where       every address the name resolves to must be a public one: no loopback, private, shared, link-local,
 *               multicast, documentation or reserved range, IPv4 or IPv6. A name that resolves to a public and a
 *               private address is refused. The request is then tied to the one address that was checked, so a name
 *               cannot answer differently between the check and the connection
 *   redirects   never followed by the HTTP library: each one (three at most) is read here and passes every rule
 *               above again before it is requested
 *   request     GET, no cookies, no credentials, certificate checked, a neutral user agent that does not name the site
 *   answer      text/html or text/plain only, 2xx only, at most MAX_BYTES of body, at most TIMEOUT_S a request
 *   budget      every fetch belongs to a run (the caller names it); a run may make RUN_FETCHES requests and read
 *               RUN_BYTES in all, counted before each request. No run named: nothing is fetched
 *   the lists   the business may deny hosts, or allow only some (option zdz_page_fetch_hosts and the filter of the
 *               same name). Core ships both lists empty. A list can only narrow: nothing on it lifts a rule above
 *   a copy      on a staging or test copy of the site (ZDZ_Staging_Guard) nothing is fetched
 *   a proxy     when the site sends its requests through a proxy, the connection cannot be tied to the checked
 *               address, so nothing is fetched
 *
 * fetch() returns { ok, reason, status, url, host, type, text, bytes, truncated, hops }: the page as text, or the
 * reason it was not read (a code word; reason_words() says it in plain words). It never throws. The text is data:
 * a caller that shows any of it to a model fences it and never reads it as instructions.
 *
 * No hooks, no routes, no screen: nothing reaches this class but an app's own server code.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ZDZ_Page_Fetch {

	const TIMEOUT_S     = 8;          // one request waits at most this long
	const MAX_BYTES     = 2000000;    // of one page's body
	const MAX_REDIRECTS = 3;
	const MAX_URL       = 2000;
	const RUN_FETCHES   = 40;         // requests one run may make
	const RUN_BYTES     = 20000000;   // bytes one run may read in all
	const MAX_ADDRESSES = 16;         // addresses of one name that are checked (more than this: refused)
	const HOSTS_OPTION  = 'zdz_page_fetch_hosts';
	const RUN_OPTION    = 'zdz_page_fetch_run_';
	const USER_AGENT    = 'Mozilla/5.0 (compatible; ZorderzPageCheck/1.0)';

	/** IPv4 ranges that are never a public web site (the special-purpose address registry). */
	const BLOCKED_V4 = array(
		array( '0.0.0.0', 8 ),        // "this network"
		array( '10.0.0.0', 8 ),       // private
		array( '100.64.0.0', 10 ),    // shared address space (carrier NAT, overlay networks)
		array( '127.0.0.0', 8 ),      // loopback
		array( '169.254.0.0', 16 ),   // link-local (cloud metadata services live here)
		array( '172.16.0.0', 12 ),    // private
		array( '192.0.0.0', 24 ),     // protocol assignments
		array( '192.0.2.0', 24 ),     // documentation
		array( '192.88.99.0', 24 ),   // 6to4 relay anycast (retired)
		array( '192.168.0.0', 16 ),   // private
		array( '198.18.0.0', 15 ),    // benchmarking
		array( '198.51.100.0', 24 ),  // documentation
		array( '203.0.113.0', 24 ),   // documentation
		array( '224.0.0.0', 4 ),      // multicast
		array( '240.0.0.0', 4 ),      // reserved, and the broadcast address
	);

	/** Name endings that never name a public site. */
	const SPECIAL_NAMES = array( 'localhost', 'local', 'localdomain', 'internal', 'intranet', 'lan', 'home', 'corp', 'private',
		'home.arpa', 'in-addr.arpa', 'ip6.arpa', 'onion', 'invalid', 'test', 'example' );

	/** Tests: callable( string $host ): string[] — the addresses a name resolves to. */
	public static $resolver = null;

	/** Tests: callable( string $url, array $args, array $pin ): array|WP_Error — the request itself. $pin: { host, ip }. */
	public static $transport = null;

	/** Tests: callable(): float — the clock. */
	public static $clock = null;

	/** Tests: callable( string $url ): bool — would this request go through a proxy? */
	public static $proxied = null;

	/** Tests: callable(): bool — can the request be tied to one address here? */
	public static $can_pin = null;

	/* ================================================================== the fetch */

	/**
	 * Read one page.
	 *
	 * @param string $url the address (https)
	 * @param array  $ctx run (string, required: whose budget this spends), deadline (unix seconds everything must end
	 *                    by), timeout (seconds a request, at most TIMEOUT_S), max_fetches / max_bytes (lower limits
	 *                    for this run; they can only narrow Core's)
	 * @return array{ok:bool,reason:string,status:int,url:string,host:string,type:string,text:string,bytes:int,truncated:bool,hops:int}
	 */
	public static function fetch( string $url, array $ctx = array() ): array {
		$out = array( 'ok' => false, 'reason' => '', 'status' => 0, 'url' => '', 'host' => '', 'type' => '', 'text' => '', 'bytes' => 0, 'truncated' => false, 'hops' => 0 );
		$no  = function ( string $reason ) use ( &$out ) {
			$out['ok']     = false;
			$out['reason'] = $reason;
			$out['text']   = '';
			return $out;
		};
		$run = self::run_key( $ctx['run'] ?? '' );
		if ( '' === $run ) {
			return $no( 'no_run' );
		}
		$ready = self::available();
		if ( ! $ready['ok'] ) {
			return $no( $ready['reason'] );
		}
		$limits   = self::limits( $ctx );
		$timeout  = isset( $ctx['timeout'] ) && is_numeric( $ctx['timeout'] ) ? max( 1, min( self::TIMEOUT_S, (int) $ctx['timeout'] ) ) : self::TIMEOUT_S;
		$deadline = isset( $ctx['deadline'] ) && is_numeric( $ctx['deadline'] ) ? (float) $ctx['deadline'] : self::now() + ( self::MAX_REDIRECTS + 1 ) * $timeout;

		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$out['hops'] = $hop;
			// every rule again for every address this fetch is about to request, a redirect's included
			$c = self::check_url( $url );
			if ( ! $c['ok'] ) {
				return $no( $c['reason'] );
			}
			$out['url']  = $c['url'];
			$out['host'] = $c['host'];
			if ( self::is_proxied( $c['url'] ) ) {
				return $no( 'proxy' );
			}
			$a = self::public_addresses( $c['host'] );
			if ( ! $a['ok'] ) {
				return $no( $a['reason'] );
			}
			$left = (int) floor( $deadline - self::now() );
			if ( $left < 1 ) {
				return $no( 'time' );
			}
			// counted before the request goes out: a request that never comes back is still spent
			if ( ! self::spend( $run, $limits ) ) {
				return $no( 'budget' );
			}
			$res = self::send( $c['url'], $c['host'], self::pin_address( $a['ips'] ), min( $timeout, $left ) );
			if ( ! is_array( $res ) ) {
				return $no( ( is_object( $res ) && method_exists( $res, 'get_error_code' ) && 'zdz_page_fetch_unpinned' === $res->get_error_code() ) ? 'transport' : 'http' );
			}
			$code          = (int) ( $res['response']['code'] ?? 0 );
			$out['status'] = $code;
			if ( $code >= 300 && $code < 400 ) {
				$next = self::resolve_location( $c['url'], self::header_of( $res, 'location' ) );
				if ( '' === $next ) {
					return $no( 'redirect' );
				}
				$url = $next;
				continue;
			}
			if ( $code < 200 || $code >= 300 ) {
				return $no( 'status' );
			}
			$type = self::media_type( self::header_of( $res, 'content-type' ) );
			if ( 'text/html' !== $type && 'text/plain' !== $type ) {
				return $no( 'type' );
			}
			$body = isset( $res['body'] ) && is_string( $res['body'] ) ? $res['body'] : '';
			if ( strlen( $body ) > self::MAX_BYTES ) {
				$body             = substr( $body, 0, self::MAX_BYTES );
				$out['truncated'] = true;
			}
			$out['bytes'] = strlen( $body );
			self::spend_bytes( $run, $out['bytes'] );
			$body         = self::to_utf8( $body, self::header_of( $res, 'content-type' ) );
			$text         = 'text/plain' === $type ? trim( (string) preg_replace( '/\s+/u', ' ', $body ) ) : self::html_to_text( $body );
			if ( '' === $text ) {
				return $no( 'empty' );
			}
			$out['ok']   = true;
			$out['type'] = 'text/plain' === $type ? 'plain' : 'html';
			$out['text'] = $text;
			return $out;
		}
		return $no( 'redirects' );
	}

	/**
	 * Can pages be read on this site at all? { ok, reason }: not on a staging or test copy, and only where a request
	 * can be tied to the address that was checked.
	 */
	public static function available(): array {
		if ( class_exists( 'ZDZ_Staging_Guard' ) && method_exists( 'ZDZ_Staging_Guard', 'is_active' ) && ZDZ_Staging_Guard::is_active() ) {
			return array( 'ok' => false, 'reason' => 'staging' );
		}
		if ( ! self::can_pin() ) {
			return array( 'ok' => false, 'reason' => 'transport' );
		}
		return array( 'ok' => true, 'reason' => '' );
	}

	/** Why a page was not read, in plain words. */
	public static function reason_words( string $reason ): string {
		switch ( $reason ) {
			case 'no_run':
			case 'budget':
				return __( 'this run has read as many pages as one run may', 'zorderz' );
			case 'staging':
				return __( 'pages are not read on a staging or test copy of the site', 'zorderz' );
			case 'transport':
			case 'proxy':
				return __( 'this site cannot read outside pages safely (its server cannot tie a request to a checked address)', 'zorderz' );
			case 'url':
			case 'scheme':
			case 'credentials':
			case 'port':
			case 'host':
			case 'ip_literal':
			case 'special_name':
				return __( 'the address is not a public web page', 'zorderz' );
			case 'own_site':
				return __( 'the address is this site itself', 'zorderz' );
			case 'denied':
			case 'not_allowed':
				return __( 'the site\'s own list of hosts does not let this one be read', 'zorderz' );
			case 'dns':
				return __( 'the site\'s name could not be found', 'zorderz' );
			case 'address':
				return __( 'the name leads to a private or reserved address', 'zorderz' );
			case 'time':
				return __( 'there was no time left to read it', 'zorderz' );
			case 'redirect':
			case 'redirects':
				return __( 'it redirected somewhere that could not be followed', 'zorderz' );
			case 'status':
				return __( 'the page did not open', 'zorderz' );
			case 'type':
				return __( 'it is not a web page of text (a PDF or a picture cannot be checked)', 'zorderz' );
			case 'empty':
				return __( 'the page had no text', 'zorderz' );
		}
		return __( 'the page did not answer', 'zorderz' );
	}

	/* ================================================================== the address */

	/**
	 * The rules an address must pass before anything is resolved or requested: { ok, reason, url, host }. `url` is the
	 * address as it will be requested (lower-case host, no fragment).
	 */
	public static function check_url( string $url ): array {
		$url = trim( $url, " \t\r\n" );
		$no  = function ( string $reason ) {
			return array( 'ok' => false, 'reason' => $reason, 'url' => '', 'host' => '' );
		};
		if ( '' === $url || strlen( $url ) > self::MAX_URL || preg_match( '/[\x00-\x20\x7f"<>\\\\^`{|}]/', $url ) ) {
			return $no( 'url' );
		}
		if ( ! preg_match( '#^https://#i', $url ) ) {
			return $no( 'scheme' );
		}
		$p = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the whole array is needed, and the scheme was checked above
		if ( ! is_array( $p ) || ! isset( $p['host'] ) || '' === (string) $p['host'] ) {
			return $no( 'url' );
		}
		// a user name or a password in the address, however it is written ("@" anywhere before the path)
		$authority = (string) preg_replace( '#^https://([^/?\#]*).*$#is', '$1', $url );
		if ( isset( $p['user'] ) || isset( $p['pass'] ) || false !== strpos( $authority, '@' ) ) {
			return $no( 'credentials' );
		}
		if ( isset( $p['port'] ) && 443 !== (int) $p['port'] ) {
			return $no( 'port' );
		}
		$host = strtolower( rtrim( (string) $p['host'], '.' ) );
		if ( preg_match( '/[^\x21-\x7e]/', $host ) ) {
			$ascii = function_exists( 'idn_to_ascii' ) ? @idn_to_ascii( $host ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a name it cannot convert is refused on the next line
			if ( ! is_string( $ascii ) || '' === $ascii ) {
				return $no( 'host' );
			}
			$host = strtolower( $ascii );
		}
		// an address written as numbers, in any of the forms a resolver accepts (dotted, bracketed, hex, one number)
		if ( '[' === substr( $host, 0, 1 ) || false !== filter_var( $host, FILTER_VALIDATE_IP ) || preg_match( '/(^|\.)(0x[0-9a-f]*|[0-9]+)$/', $host ) ) {
			return $no( 'ip_literal' );
		}
		if ( strlen( $host ) > 253 || ! preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $host ) ) {
			return $no( 'host' );
		}
		foreach ( self::SPECIAL_NAMES as $end ) {
			if ( $host === $end || '.' . $end === substr( $host, -1 - strlen( $end ) ) ) {
				return $no( 'special_name' );
			}
		}
		if ( in_array( $host, self::own_hosts(), true ) ) {
			return $no( 'own_site' );
		}
		$lists = self::hosts();
		if ( self::host_in( $host, $lists['deny'] ) ) {
			return $no( 'denied' );
		}
		if ( $lists['allow'] && ! self::host_in( $host, $lists['allow'] ) ) {
			return $no( 'not_allowed' );
		}
		$path  = isset( $p['path'] ) && '' !== (string) $p['path'] ? (string) $p['path'] : '/';
		$query = isset( $p['query'] ) && '' !== (string) $p['query'] ? '?' . $p['query'] : '';
		return array( 'ok' => true, 'reason' => '', 'url' => 'https://' . $host . $path . $query, 'host' => $host );
	}

	/** This site's own host names (its pages are not outside sources, and a request to itself is never made). */
	private static function own_hosts(): array {
		$out = array();
		foreach ( array( 'home_url', 'site_url' ) as $fn ) {
			if ( function_exists( $fn ) ) {
				$h = strtolower( (string) parse_url( (string) call_user_func( $fn ), PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this site's own address
				if ( '' !== $h ) {
					$out[] = $h;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Where a redirect leads, as a whole address, or '' when it names none. An address on another scheme comes back
	 * as written and is refused by check_url() on the next pass.
	 */
	public static function resolve_location( string $base, string $location ): string {
		$location = trim( $location );
		if ( '' === $location || strlen( $location ) > self::MAX_URL ) {
			return '';
		}
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $location ) ) {
			return $location;
		}
		$b = parse_url( $base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- an address check_url() already passed
		if ( ! is_array( $b ) || empty( $b['host'] ) ) {
			return '';
		}
		$origin = 'https://' . $b['host'];
		if ( 0 === strpos( $location, '//' ) ) {
			return 'https:' . $location;
		}
		if ( '/' === $location[0] ) {
			return $origin . $location;
		}
		if ( '?' === $location[0] || '#' === $location[0] ) {
			return $origin . ( $b['path'] ?? '/' ) . ( '?' === $location[0] ? $location : '' );
		}
		$dir = (string) preg_replace( '#/[^/]*$#', '/', (string) ( $b['path'] ?? '/' ) );
		return $origin . ( '' === $dir ? '/' : $dir ) . $location;
	}

	/* ================================================================== where the name leads */

	/**
	 * Every address the name resolves to, when every one of them is public: { ok, reason, ips }. No address: 'dns'.
	 * One that is not public (or more addresses than are checked): 'address'.
	 */
	public static function public_addresses( string $host ): array {
		$ips = self::resolve( $host );
		if ( ! $ips ) {
			return array( 'ok' => false, 'reason' => 'dns', 'ips' => array() );
		}
		if ( count( $ips ) > self::MAX_ADDRESSES ) {
			return array( 'ok' => false, 'reason' => 'address', 'ips' => array() );
		}
		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return array( 'ok' => false, 'reason' => 'address', 'ips' => array() );
			}
		}
		return array( 'ok' => true, 'reason' => '', 'ips' => $ips );
	}

	/** The addresses a name resolves to (IPv4 and IPv6), each once. */
	public static function resolve( string $host ): array {
		if ( is_callable( self::$resolver ) ) {
			$ips = (array) call_user_func( self::$resolver, $host );
		} else {
			$ips = array();
			if ( function_exists( 'dns_get_record' ) ) {
				foreach ( array( DNS_A, DNS_AAAA ) as $type ) {
					$recs = @dns_get_record( $host, $type ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a name that does not resolve warns; no address is the answer
					foreach ( is_array( $recs ) ? $recs : array() as $r ) {
						if ( ! empty( $r['ip'] ) ) {
							$ips[] = (string) $r['ip'];
						}
						if ( ! empty( $r['ipv6'] ) ) {
							$ips[] = (string) $r['ipv6'];
						}
					}
				}
			}
			if ( ! $ips && function_exists( 'gethostbynamel' ) ) {
				$l   = gethostbynamel( $host );
				$ips = is_array( $l ) ? $l : array();
			}
		}
		$out = array();
		foreach ( $ips as $ip ) {
			$ip = trim( (string) $ip );
			if ( '' !== $ip ) {
				$out[ strtolower( $ip ) ] = $ip;
			}
		}
		return array_values( $out );
	}

	/**
	 * Is this a public address a web page can live on? IPv4: anything outside BLOCKED_V4. IPv6: global unicast
	 * (2000::/3) only, without the protocol, documentation and tunnel ranges inside it; an address that carries an
	 * IPv4 address (mapped, NAT64, 6to4) is judged by that address. Anything unrecognised is not public.
	 */
	public static function is_public_ip( string $ip ): bool {
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$n = ip2long( $ip );
			if ( false === $n ) {
				return false;
			}
			foreach ( self::BLOCKED_V4 as $net ) {
				$mask = -1 << ( 32 - $net[1] );
				if ( ( $n & $mask ) === ( ip2long( $net[0] ) & $mask ) ) {
					return false;
				}
			}
			return true;
		}
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return false;
		}
		$b = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an address it cannot read is not public
		if ( ! is_string( $b ) || 16 !== strlen( $b ) ) {
			return false;
		}
		$v4 = function ( string $four ) {
			$s = inet_ntop( $four );
			return is_string( $s ) && self::is_public_ip( $s );
		};
		if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $b, 0, 12 ) ) {
			return $v4( substr( $b, 12 ) );                                   // ::ffff:a.b.c.d
		}
		if ( "\x00\x64\xff\x9b" . str_repeat( "\0", 8 ) === substr( $b, 0, 12 ) ) {
			return $v4( substr( $b, 12 ) );                                   // 64:ff9b::/96 (NAT64)
		}
		if ( "\x20\x02" === substr( $b, 0, 2 ) ) {
			return $v4( substr( $b, 2, 4 ) );                                 // 2002::/16 (6to4)
		}
		if ( 0x20 !== ( ord( $b[0] ) & 0xe0 ) ) {
			return false;                                                     // not global unicast: loopback, unique-local, link-local, multicast, unassigned
		}
		if ( "\x20\x01" === substr( $b, 0, 2 ) && 0x00 === ( ord( $b[2] ) & 0xfe ) ) {
			return false;                                                     // 2001::/23 (protocol assignments, tunnels)
		}
		if ( "\x20\x01\x0d\xb8" === substr( $b, 0, 4 ) ) {
			return false;                                                     // 2001:db8::/32 (documentation)
		}
		if ( "\x3f\xff" === substr( $b, 0, 2 ) && 0x00 === ( ord( $b[2] ) & 0xf0 ) ) {
			return false;                                                     // 3fff::/20 (documentation)
		}
		return true;
	}

	/** The one address a request is tied to: the first IPv4 one, else the first. */
	private static function pin_address( array $ips ): string {
		foreach ( $ips as $ip ) {
			if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return (string) $ip;
			}
		}
		return (string) reset( $ips );
	}

	/* ================================================================== the business's lists */

	/**
	 * The business's lists: [ 'allow' => host[], 'deny' => host[] ]. Each entry covers the host and everything under
	 * it. Deny wins. An allow list with entries means only those may be read. Core ships both empty.
	 */
	public static function hosts(): array {
		$v = function_exists( 'get_option' ) ? get_option( self::HOSTS_OPTION, array() ) : array();
		$v = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_page_fetch_hosts', is_array( $v ) ? $v : array() ) : $v;
		return self::sanitize_hosts( $v );
	}

	/** Clean lists: lower-case host names, one each; anything that is not a host name is dropped; 200 each at most. */
	public static function sanitize_hosts( $raw ): array {
		$out = array( 'allow' => array(), 'deny' => array() );
		foreach ( array_keys( $out ) as $k ) {
			$list = is_array( $raw ) ? ( $raw[ $k ] ?? array() ) : array();
			$list = is_array( $list ) ? $list : preg_split( '/[\s,]+/', (string) $list );
			foreach ( (array) $list as $h ) {
				if ( ! is_string( $h ) ) {
					continue;
				}
				$h = strtolower( trim( $h ) );
				$h = (string) preg_replace( '#^(?:https?://)?(?:\*\.)?\.?#', '', $h );
				$h = rtrim( (string) preg_replace( '#[/:?\#].*$#', '', $h ), '.' );
				if ( preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $h ) && ! preg_match( '/\.[0-9]+$/', $h ) ) {
					$out[ $k ][ $h ] = $h;
				}
			}
			$out[ $k ] = array_slice( array_values( $out[ $k ] ), 0, 200 );
		}
		return $out;
	}

	/** Is the host on the list, or under a host on it? */
	public static function host_in( string $host, array $list ): bool {
		foreach ( $list as $e ) {
			$e = (string) $e;
			if ( '' !== $e && ( $host === $e || '.' . $e === substr( $host, -1 - strlen( $e ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/* ================================================================== a run's budget */

	/** A run's name as a key: letters, digits, : _ - ; '' when it is none. */
	public static function run_key( $run ): string {
		$run = is_scalar( $run ) ? (string) $run : '';
		return preg_match( '/^[A-Za-z0-9:_\-]{3,64}$/', $run ) ? $run : '';
	}

	/** What one run may spend: Core's limits, lowered by the caller or the zdz_page_fetch_run_limits filter, never raised. */
	public static function limits( array $ctx = array() ): array {
		$l = array( 'fetches' => self::RUN_FETCHES, 'bytes' => self::RUN_BYTES );
		$f = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_page_fetch_run_limits', $l ) : $l;
		foreach ( array( $f, array( 'fetches' => $ctx['max_fetches'] ?? null, 'bytes' => $ctx['max_bytes'] ?? null ) ) as $src ) {
			foreach ( array( 'fetches', 'bytes' ) as $k ) {
				if ( is_array( $src ) && isset( $src[ $k ] ) && is_numeric( $src[ $k ] ) && (int) $src[ $k ] >= 0 ) {
					$l[ $k ] = min( $l[ $k ], (int) $src[ $k ] );
				}
			}
		}
		return $l;
	}

	/** What a run has spent: { n, bytes }. */
	public static function budget( string $run ): array {
		$run = self::run_key( $run );
		$v   = '' !== $run && function_exists( 'get_option' ) ? get_option( self::RUN_OPTION . md5( $run ), array() ) : array();
		return array( 'n' => (int) ( is_array( $v ) ? ( $v['n'] ?? 0 ) : 0 ), 'bytes' => (int) ( is_array( $v ) ? ( $v['bytes'] ?? 0 ) : 0 ) );
	}

	/** A run is over: its count is no longer kept. */
	public static function forget_run( string $run ): void {
		$run = self::run_key( $run );
		if ( '' !== $run && function_exists( 'delete_option' ) ) {
			delete_option( self::RUN_OPTION . md5( $run ) );
		}
	}

	/** Count one request against the run, when it has one left. A count that cannot be kept is a refusal. */
	private static function spend( string $run, array $limits ): bool {
		if ( ! function_exists( 'update_option' ) ) {
			return false;
		}
		$b = self::budget( $run );
		if ( $b['n'] >= $limits['fetches'] || $b['bytes'] >= $limits['bytes'] ) {
			return false;
		}
		update_option( self::RUN_OPTION . md5( $run ), array( 'n' => $b['n'] + 1, 'bytes' => $b['bytes'], 'at' => time() ), false );
		return true;
	}

	private static function spend_bytes( string $run, int $bytes ): void {
		if ( $bytes > 0 && function_exists( 'update_option' ) ) {
			$b = self::budget( $run );
			update_option( self::RUN_OPTION . md5( $run ), array( 'n' => $b['n'], 'bytes' => $b['bytes'] + $bytes, 'at' => time() ), false );
		}
	}

	/* ================================================================== the request */

	private static function now(): float {
		return is_callable( self::$clock ) ? (float) call_user_func( self::$clock ) : microtime( true );
	}

	/**
	 * Can a request be tied to one address here? (The HTTP library's cURL road, with the option that does it.) The
	 * library takes that road for an https address only when cURL can speak TLS on this server; otherwise it would use
	 * a plain socket, which cannot be tied, so nothing is fetched at all.
	 */
	private static function can_pin(): bool {
		if ( is_callable( self::$can_pin ) ) {
			return (bool) call_user_func( self::$can_pin );
		}
		if ( is_callable( self::$transport ) ) {
			return true;
		}
		if ( ! ( function_exists( 'curl_init' ) && function_exists( 'curl_setopt' ) && function_exists( 'curl_exec' )
			&& defined( 'CURLOPT_RESOLVE' ) && function_exists( 'wp_safe_remote_get' ) && function_exists( 'add_action' ) && function_exists( 'remove_action' ) ) ) {
			return false;
		}
		return self::curl_speaks_tls();
	}

	/** Will the HTTP library choose cURL for an https address here? */
	public static function curl_speaks_tls(): bool {
		if ( class_exists( '\\WpOrg\\Requests\\Transport\\Curl' ) ) {
			return (bool) \WpOrg\Requests\Transport\Curl::test( array( 'ssl' => true ) );
		}
		if ( class_exists( 'Requests_Transport_cURL' ) ) {
			return (bool) Requests_Transport_cURL::test( array( 'ssl' => true ) );
		}
		$version = function_exists( 'curl_version' ) ? curl_version() : false;
		return defined( 'CURL_VERSION_SSL' ) && is_array( $version ) && 0 !== ( (int) ( $version['features'] ?? 0 ) & CURL_VERSION_SSL );
	}

	private static function is_proxied( string $url ): bool {
		if ( is_callable( self::$proxied ) ) {
			return (bool) call_user_func( self::$proxied, $url );
		}
		if ( ! class_exists( 'WP_HTTP_Proxy' ) ) {
			return false;
		}
		$proxy = new WP_HTTP_Proxy();
		return $proxy->is_enabled() && $proxy->send_through_proxy( $url );
	}

	/**
	 * One GET, never following a redirect, tied to the address that was checked. Returns the HTTP library's answer, or
	 * an error when the request failed or could not be tied to the address (then nothing it returned is used).
	 */
	private static function send( string $url, string $host, string $ip, int $timeout ) {
		$args = array(
			'method'              => 'GET',
			'timeout'             => $timeout,
			'redirection'         => 0,
			'limit_response_size' => self::MAX_BYTES,
			'sslverify'           => true,
			'reject_unsafe_urls'  => true,
			'cookies'             => array(),
			'user-agent'          => function_exists( 'apply_filters' ) ? (string) apply_filters( 'zdz_page_fetch_user_agent', self::USER_AGENT ) : self::USER_AGENT,
			'headers'             => array( 'Accept' => 'text/html,text/plain;q=0.9' ),
		);
		$pin = array( 'host' => $host, 'ip' => $ip );
		if ( is_callable( self::$transport ) ) {
			return call_user_func( self::$transport, $url, $args, $pin );
		}
		$pinned = false;
		$tie    = function ( $handle ) use ( $host, $ip, &$pinned ) {
			$pinned = self::tie( $handle, $host, $ip );
		};
		add_action( 'http_api_curl', $tie, PHP_INT_MAX, 1 );
		try {
			$res = wp_safe_remote_get( $url, $args );
		} finally {
			remove_action( 'http_api_curl', $tie, PHP_INT_MAX );
		}
		if ( ! $pinned ) {
			// the request did not go the road that ties it to the checked address: what came back is not used
			return new WP_Error( 'zdz_page_fetch_unpinned', 'The request could not be tied to the checked address.' );
		}
		return $res;
	}

	/**
	 * Tie a cURL handle to one address: the host on port 443 resolves to $ip and nothing else, only https is spoken
	 * (on a redirect too), no redirect is followed and no proxy stands in between. False when the handle would not
	 * take it.
	 */
	public static function tie( $handle, string $host, string $ip ): bool {
		if ( ! function_exists( 'curl_setopt' ) || ! defined( 'CURLOPT_RESOLVE' ) || '' === $host || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			self::switch_off( $handle );
			return false;
		}
		$addr = false !== strpos( $ip, ':' ) ? '[' . $ip . ']' : $ip;
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- these options exist only on the handle: the HTTP library has no setting for them
		$ok = curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':443:' . $addr ) );
		if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTPS' ) ) {
			$ok = curl_setopt( $handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS ) && $ok;
			$ok = curl_setopt( $handle, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS ) && $ok;
		}
		$ok = curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false ) && $ok;
		// no proxy from the server's environment either: a proxy would resolve the name itself, past the check
		$ok = curl_setopt( $handle, CURLOPT_PROXY, '' ) && $ok;
		// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_setopt
		if ( ! $ok ) {
			// a handle that would not take every option never connects: the answer would be thrown away anyway, and
			// the request must not go out to an address nobody checked
			self::switch_off( $handle );
		}
		return (bool) $ok;
	}

	/** Make a cURL handle unable to connect: no protocol allowed and no address to go to. */
	public static function switch_off( $handle ): void {
		if ( ! function_exists( 'curl_setopt' ) || ! ( is_resource( $handle ) || ( class_exists( 'CurlHandle' ) && $handle instanceof CurlHandle ) ) ) {
			return;
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- these options exist only on the handle: the HTTP library has no setting for them
		if ( defined( 'CURLOPT_PROTOCOLS' ) ) {
			curl_setopt( $handle, CURLOPT_PROTOCOLS, 0 );
		}
		curl_setopt( $handle, CURLOPT_URL, '' );
		// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_setopt
	}

	/** One header of an answer, lower or upper case, as a string. */
	private static function header_of( $res, string $name ): string {
		$h = is_array( $res ) ? ( $res['headers'] ?? array() ) : array();
		if ( is_object( $h ) && $h instanceof ArrayAccess ) {
			$v = isset( $h[ $name ] ) ? $h[ $name ] : '';
			return is_array( $v ) ? (string) reset( $v ) : (string) $v;
		}
		foreach ( is_array( $h ) ? $h : array() as $k => $v ) {
			if ( strtolower( (string) $k ) === strtolower( $name ) ) {
				return is_array( $v ) ? (string) reset( $v ) : (string) $v;
			}
		}
		return '';
	}

	/** The media type of a Content-Type header, without its parameters ('' when there is none). */
	public static function media_type( string $header ): string {
		return strtolower( trim( (string) strtok( $header, ';' ) ) );
	}

	/** The body as UTF-8: converted from the charset the header names when it can be, and never broken text. */
	private static function to_utf8( string $body, string $content_type ): string {
		if ( preg_match( '/charset\s*=\s*"?([A-Za-z0-9._\-]{2,40})/i', $content_type, $m ) && ! preg_match( '/^utf-?8$/i', $m[1] ) && function_exists( 'mb_convert_encoding' ) ) {
			try {
				$conv = @mb_convert_encoding( $body, 'UTF-8', $m[1] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unknown charset name warns; the body is then read as it is
				if ( is_string( $conv ) && '' !== $conv ) {
					$body = $conv;
				}
			} catch ( \Throwable $e ) {
				unset( $e );   // a charset this server does not know: the body is read as it is
			}
		}
		if ( function_exists( 'mb_scrub' ) ) {
			$body = (string) mb_scrub( $body, 'UTF-8' );
		}
		return $body;
	}

	/** A page's words: scripts, styles, comments and tags out, entities read, white space collapsed. */
	public static function html_to_text( string $html ): string {
		$h = $html;
		foreach ( array( '#<(script|style|noscript|template|svg)\b[^>]*>.*?</\1>#is', '#<!--.*?-->#s', '#<(br|p|div|li|tr|td|th|h[1-6])\b[^>]*>#i' ) as $re ) {
			$r = preg_replace( $re, ' ', $h );
			if ( is_string( $r ) ) {
				$h = $r;   // a page too tangled for the pattern keeps its tags for strip_tags() below
			}
		}
		$h = html_entity_decode( strip_tags( $h ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$r = preg_replace( '/\s+/u', ' ', $h );
		return trim( is_string( $r ) ? $r : (string) preg_replace( '/\s+/', ' ', $h ) );
	}
}
