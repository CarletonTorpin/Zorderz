<?php
/**
 * ZDZ_Page_Fetch: an address a model wrote is read only when it is plainly a public web page.
 *
 *   - the address: https only, port 443 only, no user name or password, a real host name (never numbers, a one-word
 *     or special-use name, or this site itself);
 *   - where it leads: every address the name resolves to is public (IPv4 and IPv6 ranges, one by one), a name with a
 *     private address among its answers is refused, and the request is tied to the address that was checked;
 *   - redirects: read here, each one checked again from the start (another scheme, a private host, a name that
 *     answers differently the second time), three at most;
 *   - the request: GET, no cookies, no credentials, the certificate checked, redirects never followed by the library;
 *   - the answer: text/html or text/plain, 2xx, cut at the size cap;
 *   - the budget: no run named means nothing is fetched; a run's requests and bytes are counted before each request;
 *     a caller or a filter can lower a limit, never raise it;
 *   - the business's lists ship empty, only ever narrow, and never lift an address rule;
 *   - a staging copy, a proxy, or a server that cannot tie a request to an address: nothing is fetched.
 *
 * Reserved example names only; the public addresses are the ones the example domains use.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); }
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-staging-guard.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-page-fetch.php';

final class PageFetchTest extends TestCase {

	const PUBLIC_V4 = '93.184.216.34';
	const PUBLIC_V6 = '2606:2800:220:1:248:1893:25c8:1946';

	/** @var array[] every request the fetcher made: [ url, args, pin ] */
	private $sent = array();
	/** @var string[] every name it resolved */
	private $asked = array();
	/** @var array host => addresses (or a list of answers, one per lookup) */
	private $dns = array();
	/** @var array url => answer */
	private $pages = array();
	private $now = 1000.0;

	protected function setUp(): void {
		unset( $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_home'] );
		$GLOBALS['zdz_test_options'] = array();
		$this->sent  = array();
		$this->asked = array();
		$this->now   = 1000.0;
		$this->dns   = array(
			'example.org'      => array( self::PUBLIC_V4 ),
			'www.example.org'  => array( self::PUBLIC_V4 ),
			'news.example.net' => array( self::PUBLIC_V4, self::PUBLIC_V6 ),
			'six.example.net'  => array( self::PUBLIC_V6 ),
		);
		$this->pages = array();
		ZDZ_Page_Fetch::$resolver = function ( $host ) {
			$this->asked[] = $host;
			$a             = $this->dns[ $host ] ?? array();
			if ( $a && is_array( $a[0] ?? null ) ) {
				// a list of answers: one per lookup, the last one repeating
				$n = count( array_keys( $this->asked, $host, true ) );
				return $a[ min( $n, count( $a ) ) - 1 ];
			}
			return $a;
		};
		ZDZ_Page_Fetch::$transport = function ( $url, $args, $pin ) {
			$this->sent[] = array( $url, $args, $pin );
			return $this->pages[ $url ] ?? self::page( '<p>Hello</p>' );
		};
		ZDZ_Page_Fetch::$clock   = function () { return $this->now; };
		ZDZ_Page_Fetch::$proxied = null;
		ZDZ_Page_Fetch::$can_pin = null;
		self::guard_status( null );
	}

	protected function tearDown(): void {
		ZDZ_Page_Fetch::$resolver = ZDZ_Page_Fetch::$transport = ZDZ_Page_Fetch::$clock = ZDZ_Page_Fetch::$proxied = ZDZ_Page_Fetch::$can_pin = null;
		unset( $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_home'] );
		self::guard_status( null );
	}

	/** The staging guard's decision for this request (null: let it decide again). */
	private static function guard_status( $status ): void {
		$p = new ReflectionProperty( 'ZDZ_Staging_Guard', 'status' );
		$p->setAccessible( true );
		$p->setValue( null, $status );
	}

	private static function page( string $body, string $type = 'text/html; charset=utf-8', int $code = 200, array $headers = array() ): array {
		return array( 'response' => array( 'code' => $code ), 'headers' => array( 'content-type' => $type ) + $headers, 'body' => $body );
	}

	private static function redirect( string $to, int $code = 302 ): array {
		return array( 'response' => array( 'code' => $code ), 'headers' => array( 'Location' => $to ), 'body' => '' );
	}

	private function get( string $url, array $ctx = array() ): array {
		return ZDZ_Page_Fetch::fetch( $url, $ctx + array( 'run' => 'test:1' ) );
	}

	/* ── a page that is read ─────────────────────────────────────────────────────────────────────────── */

	public function test_a_public_https_page_is_read_as_text(): void {
		$this->pages['https://example.org/prices'] = self::page( '<html><head><style>p{color:red}</style><script>var x = "<p>no</p>";</script></head><body><h1>Prices</h1><p>A&nbsp;standard panel costs &pound;1,200 &amp; up.</p><!-- hidden --></body></html>' );
		$r = $this->get( 'https://Example.org/prices#top' );
		$this->assertTrue( $r['ok'], $r['reason'] );
		$this->assertSame( 'https://example.org/prices', $r['url'], 'lower-case host, no fragment' );
		$this->assertSame( 'example.org', $r['host'] );
		$this->assertSame( 'html', $r['type'] );
		$this->assertStringContainsString( 'Prices', $r['text'] );
		$this->assertStringContainsString( 'costs £1,200 & up.', $r['text'] );
		$this->assertStringNotContainsString( 'color:red', $r['text'] );
		$this->assertStringNotContainsString( 'var x', $r['text'] );
		$this->assertStringNotContainsString( 'hidden', $r['text'] );
		$this->assertSame( 200, $r['status'] );
		$this->assertCount( 1, $this->sent );
	}

	public function test_the_request_carries_no_cookie_no_credential_and_is_never_redirected_by_the_library(): void {
		$_COOKIE['wordpress_logged_in_test'] = 'someone';
		try {
			$this->get( 'https://example.org/a?b=1' );
		} finally {
			unset( $_COOKIE['wordpress_logged_in_test'] );
		}
		list( $url, $args, $pin ) = $this->sent[0];
		$this->assertSame( 'https://example.org/a?b=1', $url );
		$this->assertSame( 'GET', $args['method'] );
		$this->assertSame( 0, $args['redirection'], 'the library follows nothing' );
		$this->assertSame( array(), $args['cookies'] );
		$this->assertTrue( $args['sslverify'] );
		$this->assertTrue( $args['reject_unsafe_urls'] );
		$this->assertSame( ZDZ_Page_Fetch::MAX_BYTES, $args['limit_response_size'] );
		$this->assertLessThanOrEqual( ZDZ_Page_Fetch::TIMEOUT_S, $args['timeout'] );
		$this->assertSame( array( 'Accept' ), array_keys( $args['headers'] ), 'no Authorization, no Cookie header' );
		$this->assertStringNotContainsString( 'example.com', $args['user-agent'], 'the user agent does not name this site' );
		$this->assertSame( array( 'host' => 'example.org', 'ip' => self::PUBLIC_V4 ), $pin, 'tied to the address that was checked' );
	}

	public function test_a_name_with_both_kinds_of_address_is_tied_to_its_ipv4_one_and_an_ipv6_only_name_to_that(): void {
		$this->get( 'https://news.example.net/' );
		$this->assertSame( self::PUBLIC_V4, $this->sent[0][2]['ip'] );
		$this->get( 'https://six.example.net/' );
		$this->assertSame( self::PUBLIC_V6, $this->sent[1][2]['ip'] );
	}

	/* ── the address ─────────────────────────────────────────────────────────────────────────────────── */

	public static function not_https(): array {
		return array(
			'plain http'       => array( 'http://example.org/', 'scheme' ),
			'ftp'              => array( 'ftp://example.org/file', 'scheme' ),
			'a local file'     => array( 'file:///etc/hostname', 'scheme' ),
			'gopher'           => array( 'gopher://example.org:70/', 'scheme' ),
			'a script'         => array( 'javascript:alert(1)', 'scheme' ),
			'inline data'      => array( 'data:text/html,hello', 'scheme' ),
			'no scheme'        => array( '//example.org/x', 'scheme' ),
			'a bare name'      => array( 'example.org', 'scheme' ),
			'a dictionary url' => array( 'dict://example.org:11211/stat', 'scheme' ),
			'php wrapper'      => array( 'php://filter/resource=index.php', 'scheme' ),
			'nothing'          => array( '', 'url' ),
			'a space'          => array( 'https://example.org/a b', 'url' ),
			'a line break'     => array( "https://example.org/\r\nHost: other.example.net", 'url' ),
			'a null byte'      => array( "https://example.org/\0", 'url' ),
			'a backslash'      => array( 'https://example.org\\@other.example.net/', 'url' ),
			'too long'         => array( 'https://example.org/' . str_repeat( 'a', 2100 ), 'url' ),
			'no host'          => array( 'https:///path', 'url' ),
		);
	}

	/** @dataProvider not_https */
	public function test_only_an_https_address_is_read( string $url, string $reason ): void {
		$r = $this->get( $url );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( $reason, $r['reason'] );
		$this->assertSame( '', $r['text'] );
		$this->assertSame( array(), $this->sent, 'nothing was requested' );
		$this->assertSame( array(), $this->asked, 'nothing was even resolved' );
	}

	public static function with_credentials(): array {
		return array(
			array( 'https://alex:secret@example.org/' ),
			array( 'https://alex@example.org/' ),
			array( 'https://example.org@other.example.net/' ),
			array( 'https://example.org:pw@other.example.net/path' ),
			array( 'https://:@example.org/' ),
		);
	}

	/** @dataProvider with_credentials */
	public function test_a_user_name_or_password_in_the_address_is_refused( string $url ): void {
		$r = $this->get( $url );
		$this->assertSame( 'credentials', $r['reason'], $url );
		$this->assertSame( array(), $this->sent );
		$this->assertSame( array(), $this->asked );
	}

	public function test_only_port_443(): void {
		foreach ( array( 80, 8080, 8443, 22, 3306, 6379, 4430 ) as $port ) {
			$this->assertSame( 'port', $this->get( 'https://example.org:' . $port . '/' )['reason'], (string) $port );
		}
		$this->assertSame( array(), $this->sent );
		$this->assertTrue( $this->get( 'https://example.org:443/x' )['ok'] );
		$this->assertSame( 'https://example.org/x', $this->sent[0][0] );
	}

	public static function not_a_public_name(): array {
		return array(
			'loopback in numbers'      => array( 'https://127.0.0.1/', 'ip_literal' ),
			'a private address'        => array( 'https://10.0.0.8/admin', 'ip_literal' ),
			'the metadata address'     => array( 'https://169.254.169.254/latest/meta-data/', 'ip_literal' ),
			'a public address too'     => array( 'https://' . self::PUBLIC_V4 . '/', 'ip_literal' ),
			'ipv6 loopback'            => array( 'https://[::1]/', 'ip_literal' ),
			'ipv6 mapped'              => array( 'https://[::ffff:127.0.0.1]/', 'ip_literal' ),
			'one decimal number'       => array( 'https://2130706433/', 'ip_literal' ),
			'one hex number'           => array( 'https://0x7f000001/', 'ip_literal' ),
			'hex parts'                => array( 'https://0x7f.0.0.1/', 'ip_literal' ),
			'short dotted'             => array( 'https://127.1/', 'ip_literal' ),
			'octal'                    => array( 'https://017700000001/', 'ip_literal' ),
			'a name ending in digits'  => array( 'https://example.123/', 'ip_literal' ),
			'one word'                 => array( 'https://localhost/', 'host' ),
			'an intranet word'         => array( 'https://intranet/wiki', 'host' ),
			'an underscore'            => array( 'https://bad_name.example.org/', 'host' ),
			'a percent sign'           => array( 'https://exa%6dple.org/', 'host' ),
			'a leading hyphen'         => array( 'https://-a.example.org/', 'host' ),
			'mdns'                     => array( 'https://printer.local/', 'special_name' ),
			'internal'                 => array( 'https://db.internal/', 'special_name' ),
			'under localhost'          => array( 'https://app.localhost/', 'special_name' ),
			'home network'             => array( 'https://router.home.arpa/', 'special_name' ),
			'a lan name'               => array( 'https://nas.lan/', 'special_name' ),
			'onion'                    => array( 'https://abcdefghijklmnop.onion/', 'special_name' ),
			'reserved for tests'       => array( 'https://service.test/', 'special_name' ),
			'reserved as an example'   => array( 'https://www.example/', 'special_name' ),
			'a corporate suffix'       => array( 'https://files.corp/', 'special_name' ),
		);
	}

	/** @dataProvider not_a_public_name */
	public function test_the_host_must_be_a_real_public_name( string $url, string $reason ): void {
		$r = $this->get( $url );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( $reason, $r['reason'] );
		$this->assertSame( array(), $this->sent );
		$this->assertSame( array(), $this->asked );
	}

	public function test_this_site_itself_is_never_fetched(): void {
		foreach ( array( 'https://app.example.com/', 'https://APP.example.com/wp-admin/admin-ajax.php?action=anything', 'https://app.example.com./wp-cron.php' ) as $url ) {
			$this->assertSame( 'own_site', $this->get( $url )['reason'], $url );
		}
		$GLOBALS['zdz_test_options']['siteurl'] = 'https://wp.example.com/core';
		$this->assertSame( 'own_site', $this->get( 'https://wp.example.com/core/wp-login.php' )['reason'], 'the WordPress address too' );
		$this->assertSame( array(), $this->sent );
	}

	/* ── where the name leads ────────────────────────────────────────────────────────────────────────── */

	public static function private_v4(): array {
		$out = array();
		foreach ( array(
			'0.0.0.0', '0.255.255.255', '10.0.0.1', '10.255.255.255', '100.64.0.1', '100.127.255.254', '127.0.0.1', '127.255.255.255',
			'169.254.0.1', '169.254.169.254', '172.16.0.1', '172.31.255.255', '192.0.0.1', '192.0.2.44', '192.88.99.1', '192.168.0.1',
			'192.168.255.255', '198.18.0.1', '198.19.255.255', '198.51.100.7', '203.0.113.9', '224.0.0.1', '239.255.255.250', '240.0.0.1',
			'255.255.255.255',
		) as $ip ) {
			$out[ $ip ] = array( $ip );
		}
		return $out;
	}

	/** @dataProvider private_v4 */
	public function test_an_ipv4_address_in_a_private_or_reserved_range_is_not_public( string $ip ): void {
		$this->assertFalse( ZDZ_Page_Fetch::is_public_ip( $ip ) );
		$this->dns['odd.example.net'] = array( $ip );
		$r = $this->get( 'https://odd.example.net/' );
		$this->assertSame( 'address', $r['reason'] );
		$this->assertSame( array(), $this->sent, 'no request to a private address' );
	}

	public function test_the_addresses_just_outside_each_ipv4_range_are_public(): void {
		foreach ( array(
			'1.0.0.1', '9.255.255.255', '11.0.0.1', '100.63.255.255', '100.128.0.1', '126.255.255.255', '128.0.0.1', '169.253.255.255',
			'169.255.0.1', '172.15.255.255', '172.32.0.1', '192.0.1.1', '192.0.3.1', '192.88.98.1', '192.88.100.1', '192.167.255.255',
			'192.169.0.1', '198.17.255.255', '198.20.0.1', '198.51.99.1', '198.51.101.1', '203.0.112.1', '203.0.114.1', '223.255.255.255',
			self::PUBLIC_V4,
		) as $ip ) {
			$this->assertTrue( ZDZ_Page_Fetch::is_public_ip( $ip ), $ip );
		}
	}

	public static function private_v6(): array {
		$out = array();
		foreach ( array(
			'::'                    => 'unspecified',
			'::1'                   => 'loopback',
			'::7f00:1'              => 'IPv4-compatible loopback',
			'::ffff:127.0.0.1'      => 'mapped loopback',
			'::ffff:10.1.2.3'       => 'mapped private',
			'::ffff:169.254.169.254' => 'mapped link-local',
			'64:ff9b::7f00:1'       => 'NAT64 of loopback',
			'64:ff9b::a00:1'        => 'NAT64 of a private address',
			'64:ff9b:1::1'          => 'local-use NAT64',
			'100::1'                => 'discard only',
			'2001::1'               => 'a tunnel range',
			'2001:10::1'            => 'protocol assignments',
			'2001:db8::1'           => 'documentation',
			'2001:db8:ffff::1'      => 'documentation',
			'2002:7f00:1::1'        => '6to4 of loopback',
			'2002:a00:1::1'         => '6to4 of a private address',
			'2002:c0a8:101::1'      => '6to4 of a private address',
			'3fff::1'               => 'documentation',
			'fc00::1'               => 'unique local',
			'fd12:3456:789a::1'     => 'unique local',
			'fe80::1'               => 'link-local',
			'febf::1'               => 'link-local',
			'fec0::1'               => 'site-local',
			'ff02::1'               => 'multicast',
			'ff0e::1'               => 'multicast',
			'4000::1'               => 'outside global unicast',
			'8000::1'               => 'outside global unicast',
		) as $ip => $what ) {
			$out[ $what . ' ' . $ip ] = array( $ip );
		}
		return $out;
	}

	/** @dataProvider private_v6 */
	public function test_an_ipv6_address_that_is_not_plain_global_unicast_is_not_public( string $ip ): void {
		$this->assertFalse( ZDZ_Page_Fetch::is_public_ip( $ip ) );
		$this->dns['odd.example.net'] = array( $ip );
		$this->assertSame( 'address', $this->get( 'https://odd.example.net/' )['reason'] );
		$this->assertSame( array(), $this->sent );
	}

	public function test_public_ipv6_addresses_and_the_ones_that_carry_a_public_ipv4_address(): void {
		foreach ( array( self::PUBLIC_V6, '2a00:1450:4001:81b::200e', '2001:200::1', '2400:cb00:2048:1::c629:d7a2', '3ffe::1',
			'::ffff:' . self::PUBLIC_V4, '64:ff9b::5db8:d822', '2002:5db8:d822::1' ) as $ip ) {
			$this->assertTrue( ZDZ_Page_Fetch::is_public_ip( $ip ), $ip );
		}
	}

	public function test_what_is_not_an_address_is_not_public(): void {
		foreach ( array( '', 'example.org', '1.2.3', '1.2.3.4.5', '256.1.1.1', '01.02.03.04', ':::', 'gggg::1', '127.0.0.1 ', ' 8.8.8.8', '0x7f.0.0.1' ) as $ip ) {
			$this->assertFalse( ZDZ_Page_Fetch::is_public_ip( $ip ), var_export( $ip, true ) );
		}
	}

	public function test_a_name_with_one_private_address_among_public_ones_is_refused(): void {
		$this->dns['mixed.example.net'] = array( self::PUBLIC_V4, '10.0.0.5' );
		$this->assertSame( 'address', $this->get( 'https://mixed.example.net/' )['reason'] );
		$this->dns['mixed6.example.net'] = array( self::PUBLIC_V4, 'fe80::1' );
		$this->assertSame( 'address', $this->get( 'https://mixed6.example.net/' )['reason'] );
		$this->assertSame( array(), $this->sent );
	}

	public function test_a_name_that_does_not_resolve_and_one_with_too_many_addresses(): void {
		$this->assertSame( 'dns', $this->get( 'https://nowhere.example.net/' )['reason'] );
		$many = array();
		for ( $i = 1; $i <= ZDZ_Page_Fetch::MAX_ADDRESSES + 1; $i++ ) {
			$many[] = '93.184.216.' . $i;
		}
		$this->dns['many.example.net'] = $many;
		$this->assertSame( 'address', $this->get( 'https://many.example.net/' )['reason'] );
		$this->assertSame( array(), $this->sent );
	}

	/* ── redirects ───────────────────────────────────────────────────────────────────────────────────── */

	public function test_a_redirect_is_followed_here_and_checked_again(): void {
		$this->pages['https://example.org/old']     = self::redirect( 'https://www.example.org/new' );
		$this->pages['https://www.example.org/new'] = self::page( '<p>Moved here</p>' );
		$r = $this->get( 'https://example.org/old' );
		$this->assertTrue( $r['ok'], $r['reason'] );
		$this->assertSame( 'https://www.example.org/new', $r['url'] );
		$this->assertSame( 1, $r['hops'] );
		$this->assertSame( array( 'example.org', 'www.example.org' ), $this->asked, 'each host resolved before its own request' );
		$this->assertSame( 'www.example.org', $this->sent[1][2]['host'] );
		$this->assertSame( 0, $this->sent[1][1]['redirection'] );
	}

	public function test_relative_redirects_are_resolved_against_the_page_that_sent_them(): void {
		$this->assertSame( 'https://example.org/b', ZDZ_Page_Fetch::resolve_location( 'https://example.org/a/x?q=1', '/b' ) );
		$this->assertSame( 'https://example.org/a/c', ZDZ_Page_Fetch::resolve_location( 'https://example.org/a/x', 'c' ) );
		$this->assertSame( 'https://example.org/x?p=2', ZDZ_Page_Fetch::resolve_location( 'https://example.org/x?p=1', '?p=2' ) );
		$this->assertSame( 'https://other.example.net/y', ZDZ_Page_Fetch::resolve_location( 'https://example.org/x', '//other.example.net/y' ) );
		$this->assertSame( 'http://example.org/', ZDZ_Page_Fetch::resolve_location( 'https://example.org/x', 'http://example.org/' ), 'as written; the next check refuses it' );
		$this->assertSame( '', ZDZ_Page_Fetch::resolve_location( 'https://example.org/x', '' ) );
		$this->pages['https://example.org/a/x'] = self::redirect( '../b', 301 );
		$this->pages['https://example.org/a/../b'] = self::page( '<p>ok</p>' );
		$this->assertTrue( $this->get( 'https://example.org/a/x' )['ok'] );
	}

	public static function bad_redirects(): array {
		return array(
			'to plain http'              => array( 'http://example.org/', 'scheme' ),
			'to a local file'            => array( 'file:///etc/hostname', 'scheme' ),
			'to a script'                => array( 'javascript:alert(1)', 'scheme' ),
			'to loopback in numbers'     => array( 'https://127.0.0.1/', 'ip_literal' ),
			'to the metadata address'    => array( 'https://169.254.169.254/latest/', 'ip_literal' ),
			'to ipv6 loopback'           => array( 'https://[::1]/', 'ip_literal' ),
			'to a name with a password'  => array( 'https://alex:pw@www.example.org/', 'credentials' ),
			'to another port'            => array( 'https://www.example.org:8443/', 'port' ),
			'to an internal name'        => array( 'https://billing.internal/', 'special_name' ),
			'to this site'               => array( 'https://app.example.com/wp-admin/', 'own_site' ),
			'to a host that is private'  => array( 'https://inside.example.net/', 'address' ),
			'to a host that is nowhere'  => array( 'https://gone.example.net/', 'dns' ),
			'scheme-relative to private' => array( '//inside.example.net/x', 'address' ),
		);
	}

	/** @dataProvider bad_redirects */
	public function test_a_redirect_is_refused_for_the_same_reasons_a_first_address_is( string $location, string $reason ): void {
		$this->dns['inside.example.net']         = array( '192.168.1.20' );
		$this->pages['https://example.org/start'] = self::redirect( $location );
		$r = $this->get( 'https://example.org/start' );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( $reason, $r['reason'] );
		$this->assertSame( '', $r['text'] );
		$this->assertCount( 1, $this->sent, 'only the first address was ever requested' );
	}

	public function test_a_name_that_answers_differently_on_the_second_look_is_checked_on_that_look(): void {
		// public for the first request, private when the redirect comes back to it
		$this->dns['flip.example.net']              = array( array( self::PUBLIC_V4 ), array( '10.0.0.9' ) );
		$this->pages['https://flip.example.net/a']  = self::redirect( '/b' );
		$this->pages['https://flip.example.net/b']  = self::page( '<p>secret</p>' );
		$r = $this->get( 'https://flip.example.net/a' );
		$this->assertSame( 'address', $r['reason'] );
		$this->assertSame( '', $r['text'] );
		$this->assertCount( 1, $this->sent );
		$this->assertSame( array( 'flip.example.net', 'flip.example.net' ), $this->asked, 'resolved again for the second request' );
	}

	public function test_each_request_is_tied_to_the_address_checked_for_it(): void {
		$this->dns['a.example.net']               = array( self::PUBLIC_V4 );
		$this->dns['b.example.net']               = array( '151.101.1.1' );
		$this->pages['https://a.example.net/']    = self::redirect( 'https://b.example.net/' );
		$this->get( 'https://a.example.net/' );
		$this->assertSame( array( 'host' => 'a.example.net', 'ip' => self::PUBLIC_V4 ), $this->sent[0][2] );
		$this->assertSame( array( 'host' => 'b.example.net', 'ip' => '151.101.1.1' ), $this->sent[1][2] );
	}

	public function test_three_redirects_at_most_and_one_without_a_destination_is_refused(): void {
		for ( $i = 0; $i < 6; $i++ ) {
			$this->pages[ 'https://example.org/r' . $i ] = self::redirect( '/r' . ( $i + 1 ) );
		}
		$r = $this->get( 'https://example.org/r0' );
		$this->assertSame( 'redirects', $r['reason'] );
		$this->assertCount( ZDZ_Page_Fetch::MAX_REDIRECTS + 1, $this->sent );
		$this->pages['https://example.org/nowhere'] = array( 'response' => array( 'code' => 302 ), 'headers' => array(), 'body' => '' );
		$this->assertSame( 'redirect', $this->get( 'https://example.org/nowhere', array( 'run' => 'test:2' ) )['reason'] );
	}

	/* ── the answer ──────────────────────────────────────────────────────────────────────────────────── */

	public static function content_types(): array {
		return array(
			array( 'text/html', true ),
			array( 'text/html; charset=UTF-8', true ),
			array( 'TEXT/HTML', true ),
			array( 'text/plain; charset=us-ascii', true ),
			array( 'application/pdf', false ),
			array( 'image/png', false ),
			array( 'application/json', false ),
			array( 'application/xhtml+xml', false ),
			array( 'application/octet-stream', false ),
			array( 'text/html-like', false ),
			array( 'text/css', false ),
			array( 'text/javascript', false ),
			array( 'multipart/form-data', false ),
			array( '', false ),
		);
	}

	/** @dataProvider content_types */
	public function test_only_html_and_plain_text_are_read( string $type, bool $ok ): void {
		$this->pages['https://example.org/doc'] = self::page( 'Some words here', $type );
		$r = $this->get( 'https://example.org/doc' );
		$this->assertSame( $ok, $r['ok'], $type );
		if ( ! $ok ) {
			$this->assertSame( 'type', $r['reason'] );
			$this->assertSame( '', $r['text'] );
		}
	}

	public function test_a_plain_text_page_keeps_its_words_and_nothing_is_read_as_markup(): void {
		$this->pages['https://example.org/robots.txt'] = self::page( "Line one\n\n  Line   two <b>not a tag</b>", 'text/plain' );
		$r = $this->get( 'https://example.org/robots.txt' );
		$this->assertSame( 'plain', $r['type'] );
		$this->assertSame( 'Line one Line two <b>not a tag</b>', $r['text'] );
	}

	public function test_a_body_larger_than_the_cap_is_cut_at_the_cap(): void {
		$big = '<p>' . str_repeat( 'word ', 600000 ) . '</p>';   // 3,000,007 bytes
		$this->assertGreaterThan( ZDZ_Page_Fetch::MAX_BYTES, strlen( $big ) );
		$this->pages['https://example.org/big'] = self::page( $big );
		$r = $this->get( 'https://example.org/big' );
		$this->assertTrue( $r['ok'] );
		$this->assertTrue( $r['truncated'] );
		$this->assertSame( ZDZ_Page_Fetch::MAX_BYTES, $r['bytes'] );
		$this->assertLessThanOrEqual( ZDZ_Page_Fetch::MAX_BYTES, strlen( $r['text'] ) );
		$this->assertSame( ZDZ_Page_Fetch::MAX_BYTES, ZDZ_Page_Fetch::budget( 'test:1' )['bytes'], 'what was read counts against the run' );
		$this->assertFalse( $this->get( 'https://example.org/' )['truncated'] );
	}

	public function test_a_page_that_did_not_open_or_has_no_words(): void {
		foreach ( array( 401, 403, 404, 410, 429, 500, 503, 199, 600 ) as $code ) {
			$this->pages['https://example.org/s'] = self::page( '<p>Sign in</p>', 'text/html', $code );
			$this->assertSame( 'status', $this->get( 'https://example.org/s', array( 'run' => 'status:' . $code ) )['reason'], (string) $code );
		}
		$this->pages['https://example.org/e'] = self::page( '<script>only();</script>  ', 'text/html', 204 );
		$this->assertSame( 'empty', $this->get( 'https://example.org/e' )['reason'] );
	}

	public function test_a_request_that_failed_or_could_not_be_tied_to_the_address(): void {
		$this->pages['https://example.org/x'] = new WP_Error( 'http_request_failed', 'cURL error 28' );
		$this->assertSame( 'http', $this->get( 'https://example.org/x' )['reason'] );
		$this->pages['https://example.org/y'] = new WP_Error( 'zdz_page_fetch_unpinned', 'not tied' );
		$r = $this->get( 'https://example.org/y' );
		$this->assertSame( 'transport', $r['reason'] );
		$this->assertSame( '', $r['text'] );
	}

	public function test_a_handle_that_cannot_be_tied_to_the_address_never_connects(): void {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'cURL is not installed here.' );
		}
		$h = curl_init( 'https://example.invalid/' );
		$this->assertFalse( ZDZ_Page_Fetch::tie( $h, 'example.invalid', 'not an address' ) );
		curl_setopt( $h, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $h, CURLOPT_CONNECTTIMEOUT, 1 );
		$this->assertFalse( curl_exec( $h ), 'the switched-off handle fails before it connects' );
		$this->assertContains( curl_errno( $h ), array( 1, 3 ), 'no protocol allowed, or no address to go to' );
		$this->assertSame( 0, (int) curl_getinfo( $h, CURLINFO_CONNECT_TIME_T ), 'nothing was connected' );

		$ok = curl_init( 'https://example.invalid/' );
		$this->assertTrue( ZDZ_Page_Fetch::tie( $ok, 'example.invalid', self::PUBLIC_V4 ), 'a handle that takes every option stays usable' );
	}

	public function test_the_library_takes_the_curl_road_only_where_curl_speaks_tls(): void {
		if ( ! function_exists( 'curl_version' ) ) {
			$this->markTestSkipped( 'cURL is not installed here.' );
		}
		$v = curl_version();
		$this->assertSame( 0 !== ( (int) $v['features'] & CURL_VERSION_SSL ), ZDZ_Page_Fetch::curl_speaks_tls() );
	}

	public function test_another_charset_is_read_as_its_own_letters(): void {
		$this->pages['https://example.org/latin'] = self::page( "<p>Caf\xe9 au lait</p>", 'text/html; charset=ISO-8859-1' );
		$this->assertSame( 'Café au lait', $this->get( 'https://example.org/latin' )['text'] );
		$this->pages['https://example.org/odd'] = self::page( "<p>Plain \xff words</p>", 'text/html; charset=no-such-charset' );
		$r = $this->get( 'https://example.org/odd' );
		$this->assertTrue( $r['ok'] );
		$this->assertStringContainsString( 'Plain', $r['text'] );
		$this->assertTrue( mb_check_encoding( $r['text'], 'UTF-8' ), 'never broken text' );
	}

	/* ── the budget ──────────────────────────────────────────────────────────────────────────────────── */

	public function test_no_run_named_means_nothing_is_fetched(): void {
		foreach ( array( array(), array( 'run' => '' ), array( 'run' => 'x' ), array( 'run' => 'has space' ), array( 'run' => array( 'a' ) ), array( 'run' => str_repeat( 'a', 65 ) ) ) as $ctx ) {
			$r = ZDZ_Page_Fetch::fetch( 'https://example.org/', $ctx );
			$this->assertSame( 'no_run', $r['reason'] );
		}
		$this->assertSame( array(), $this->sent );
		$this->assertSame( array(), $this->asked );
	}

	public function test_a_run_may_make_only_so_many_requests_counted_before_each_one(): void {
		for ( $i = 0; $i < ZDZ_Page_Fetch::RUN_FETCHES; $i++ ) {
			$this->assertTrue( $this->get( 'https://example.org/p' . $i )['ok'], (string) $i );
		}
		$this->assertSame( ZDZ_Page_Fetch::RUN_FETCHES, ZDZ_Page_Fetch::budget( 'test:1' )['n'] );
		$r = $this->get( 'https://example.org/one-more' );
		$this->assertSame( 'budget', $r['reason'] );
		$this->assertCount( ZDZ_Page_Fetch::RUN_FETCHES, $this->sent );
		$this->assertTrue( $this->get( 'https://example.org/other', array( 'run' => 'test:2' ) )['ok'], 'another run has its own count' );
		ZDZ_Page_Fetch::forget_run( 'test:1' );
		$this->assertSame( 0, ZDZ_Page_Fetch::budget( 'test:1' )['n'] );
	}

	public function test_a_request_that_fails_is_still_counted_and_so_is_each_redirect(): void {
		$this->pages['https://example.org/x'] = new WP_Error( 'http_request_failed', 'timed out' );
		$this->get( 'https://example.org/x' );
		$this->assertSame( 1, ZDZ_Page_Fetch::budget( 'test:1' )['n'] );
		$this->pages['https://example.org/a'] = self::redirect( '/b' );
		$this->pages['https://example.org/b'] = self::redirect( '/c' );
		$this->get( 'https://example.org/a' );
		$this->assertSame( 4, ZDZ_Page_Fetch::budget( 'test:1' )['n'] );
		// an address refused before any request costs nothing
		$this->get( 'http://example.org/' );
		$this->get( 'https://127.0.0.1/' );
		$this->assertSame( 4, ZDZ_Page_Fetch::budget( 'test:1' )['n'] );
	}

	public function test_a_caller_or_a_filter_can_lower_a_limit_and_never_raise_it(): void {
		$this->assertSame( array( 'fetches' => 3, 'bytes' => 1000 ), ZDZ_Page_Fetch::limits( array( 'max_fetches' => 3, 'max_bytes' => 1000 ) ) );
		$this->assertSame( array( 'fetches' => ZDZ_Page_Fetch::RUN_FETCHES, 'bytes' => ZDZ_Page_Fetch::RUN_BYTES ), ZDZ_Page_Fetch::limits( array( 'max_fetches' => 9999, 'max_bytes' => PHP_INT_MAX ) ) );
		$this->assertSame( ZDZ_Page_Fetch::RUN_FETCHES, ZDZ_Page_Fetch::limits( array( 'max_fetches' => -1, 'max_bytes' => 'lots' ) )['fetches'] );
		$GLOBALS['zdz_test_filters']['zdz_page_fetch_run_limits'] = function () { return array( 'fetches' => 100000, 'bytes' => 5 ); };
		$this->assertSame( array( 'fetches' => ZDZ_Page_Fetch::RUN_FETCHES, 'bytes' => 5 ), ZDZ_Page_Fetch::limits() );
		unset( $GLOBALS['zdz_test_filters'] );
		$this->assertTrue( $this->get( 'https://example.org/1', array( 'max_fetches' => 2 ) )['ok'] );
		$this->assertTrue( $this->get( 'https://example.org/2', array( 'max_fetches' => 2 ) )['ok'] );
		$this->assertSame( 'budget', $this->get( 'https://example.org/3', array( 'max_fetches' => 2 ) )['reason'] );
		$this->assertSame( 'budget', $this->get( 'https://example.org/4', array( 'run' => 'none:1', 'max_fetches' => 0 ) )['reason'] );
	}

	public function test_a_run_that_has_read_its_bytes_reads_no_more(): void {
		$this->pages['https://example.org/a'] = self::page( '<p>' . str_repeat( 'x', 400 ) . '</p>' );
		$this->assertTrue( $this->get( 'https://example.org/a', array( 'max_bytes' => 300 ) )['ok'] );
		$this->assertSame( 'budget', $this->get( 'https://example.org/a', array( 'max_bytes' => 300 ) )['reason'] );
	}

	/* ── time ────────────────────────────────────────────────────────────────────────────────────────── */

	public function test_a_fetch_past_its_deadline_is_not_started_and_a_request_never_waits_past_it(): void {
		$r = $this->get( 'https://example.org/', array( 'deadline' => $this->now - 1 ) );
		$this->assertSame( 'time', $r['reason'] );
		$this->assertSame( array(), $this->sent );
		$this->get( 'https://example.org/', array( 'deadline' => $this->now + 3.5 ) );
		$this->assertSame( 3, $this->sent[0][1]['timeout'] );
		$this->get( 'https://example.org/', array( 'timeout' => 600 ) );
		$this->assertSame( ZDZ_Page_Fetch::TIMEOUT_S, $this->sent[1][1]['timeout'], 'a caller cannot lengthen the wait' );
		$this->get( 'https://example.org/', array( 'timeout' => 2 ) );
		$this->assertSame( 2, $this->sent[2][1]['timeout'] );
	}

	public function test_the_clock_runs_across_redirects(): void {
		$this->pages['https://example.org/slow'] = self::redirect( '/slower' );
		ZDZ_Page_Fetch::$transport = function ( $url, $args, $pin ) {
			$this->sent[] = array( $url, $args, $pin );
			$this->now   += 9;   // each request takes nine seconds
			return $this->pages[ $url ] ?? self::page( '<p>late</p>' );
		};
		$r = $this->get( 'https://example.org/slow', array( 'deadline' => $this->now + 8 ) );
		$this->assertSame( 'time', $r['reason'] );
		$this->assertCount( 1, $this->sent );
	}

	/* ── the business's lists ────────────────────────────────────────────────────────────────────────── */

	public function test_the_lists_ship_empty_and_then_any_public_page_is_read(): void {
		$this->assertSame( array( 'allow' => array(), 'deny' => array() ), ZDZ_Page_Fetch::hosts() );
		$this->assertTrue( $this->get( 'https://example.org/' )['ok'] );
		$this->assertTrue( $this->get( 'https://news.example.net/' )['ok'] );
	}

	public function test_a_denied_host_and_everything_under_it_is_refused_but_not_a_look_alike(): void {
		$GLOBALS['zdz_test_options'][ ZDZ_Page_Fetch::HOSTS_OPTION ] = array( 'deny' => array( 'example.org' ) );
		$this->dns['notexample.org'] = array( self::PUBLIC_V4 );
		$this->assertSame( 'denied', $this->get( 'https://example.org/' )['reason'] );
		$this->assertSame( 'denied', $this->get( 'https://www.example.org/' )['reason'] );
		$this->assertSame( array(), $this->sent );
		$this->assertTrue( $this->get( 'https://notexample.org/' )['ok'] );
		$this->assertTrue( $this->get( 'https://news.example.net/' )['ok'] );
	}

	public function test_an_allow_list_means_only_those_hosts_and_deny_still_wins(): void {
		$GLOBALS['zdz_test_options'][ ZDZ_Page_Fetch::HOSTS_OPTION ] = array( 'allow' => array( 'example.org' ), 'deny' => array( 'www.example.org' ) );
		$this->assertTrue( $this->get( 'https://example.org/' )['ok'] );
		$this->assertSame( 'denied', $this->get( 'https://www.example.org/' )['reason'] );
		$this->assertSame( 'not_allowed', $this->get( 'https://news.example.net/' )['reason'] );
		// a redirect off the list is refused too
		$this->pages['https://example.org/out'] = self::redirect( 'https://news.example.net/' );
		$this->assertSame( 'not_allowed', $this->get( 'https://example.org/out' )['reason'] );
	}

	public function test_a_list_never_lifts_an_address_rule(): void {
		$GLOBALS['zdz_test_filters']['zdz_page_fetch_hosts'] = function () {
			return array( 'allow' => array( 'inside.example.net', 'localhost', '127.0.0.1', 'app.example.com', 'db.internal', 'example.org:8443', 'http://example.org' ) );
		};
		$this->dns['inside.example.net'] = array( '10.1.1.1' );
		$this->assertSame( 'address', $this->get( 'https://inside.example.net/' )['reason'] );
		$this->assertSame( 'host', $this->get( 'https://localhost/' )['reason'] );
		$this->assertSame( 'ip_literal', $this->get( 'https://127.0.0.1/' )['reason'] );
		$this->assertSame( 'own_site', $this->get( 'https://app.example.com/' )['reason'] );
		$this->assertSame( 'special_name', $this->get( 'https://db.internal/' )['reason'] );
		$this->assertSame( 'port', $this->get( 'https://example.org:8443/' )['reason'] );
		$this->assertSame( 'scheme', $this->get( 'http://example.org/' )['reason'] );
		$this->assertSame( array(), $this->sent );
	}

	public function test_the_lists_are_cleaned_to_host_names(): void {
		$clean = ZDZ_Page_Fetch::sanitize_hosts( array(
			'allow' => "Example.ORG\n*.news.example.net, https://docs.example.net/path?x=1\n.dot.example.net.",
			'deny'  => array( 'bad host', '10.0.0.1', 'localhost', 'example.org', 'EXAMPLE.org', '<script>', 'a.b', array( 'nested' ), 7 ),
			'other' => array( 'ignored.example.net' ),
		) );
		$this->assertSame( array( 'example.org', 'news.example.net', 'docs.example.net', 'dot.example.net' ), $clean['allow'] );
		$this->assertSame( array( 'example.org', 'a.b' ), $clean['deny'] );
		$this->assertSame( array( 'allow', 'deny' ), array_keys( $clean ) );
		$this->assertSame( array( 'allow' => array(), 'deny' => array() ), ZDZ_Page_Fetch::sanitize_hosts( 'not a list' ) );
		$this->assertTrue( ZDZ_Page_Fetch::host_in( 'a.news.example.net', $clean['allow'] ) );
		$this->assertFalse( ZDZ_Page_Fetch::host_in( 'xnews.example.net', $clean['allow'] ) );
	}

	/* ── where nothing is fetched at all ─────────────────────────────────────────────────────────────── */

	public function test_nothing_is_fetched_on_a_staging_copy(): void {
		$GLOBALS['zdz_test_filters']['zdz_staging_guard_hosts'] = function () { return array( 'app.example.com' ); };
		self::guard_status( null );
		$this->assertTrue( ZDZ_Staging_Guard::is_active(), 'this site is listed as a copy' );
		$this->assertSame( array( 'ok' => false, 'reason' => 'staging' ), ZDZ_Page_Fetch::available() );
		$r = $this->get( 'https://example.org/' );
		$this->assertSame( 'staging', $r['reason'] );
		$this->assertSame( array(), $this->sent );
		$this->assertSame( array(), $this->asked );
		$this->assertSame( 0, ZDZ_Page_Fetch::budget( 'test:1' )['n'] );
	}

	public function test_nothing_is_fetched_through_a_proxy_or_where_a_request_cannot_be_tied_to_an_address(): void {
		ZDZ_Page_Fetch::$proxied = function ( $url ) { return true; };
		$this->assertSame( 'proxy', $this->get( 'https://example.org/' )['reason'] );
		$this->assertSame( array(), $this->sent );
		ZDZ_Page_Fetch::$proxied = null;
		ZDZ_Page_Fetch::$can_pin = function () { return false; };
		$this->assertSame( array( 'ok' => false, 'reason' => 'transport' ), ZDZ_Page_Fetch::available() );
		$this->assertSame( 'transport', $this->get( 'https://example.org/' )['reason'] );
		$this->assertSame( array(), $this->sent );
		$this->assertSame( array(), $this->asked );
	}

	public function test_a_curl_handle_is_tied_to_one_checked_address_and_to_https(): void {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'cURL is not installed here.' );
		}
		$h = curl_init( 'https://example.org/' );
		$this->assertTrue( ZDZ_Page_Fetch::tie( $h, 'example.org', self::PUBLIC_V4 ) );
		$this->assertTrue( ZDZ_Page_Fetch::tie( $h, 'six.example.net', self::PUBLIC_V6 ) );
		$this->assertFalse( ZDZ_Page_Fetch::tie( $h, 'example.org', 'not an address' ), 'only a real address is ever tied' );
		$this->assertFalse( ZDZ_Page_Fetch::tie( $h, 'example.org', 'other.example.net' ) );
		$this->assertFalse( ZDZ_Page_Fetch::tie( $h, '', self::PUBLIC_V4 ) );
		// a tied handle speaks https only: plain http is refused before any connection is made
		$plain = curl_init( 'http://example.org/' );
		curl_setopt( $plain, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $plain, CURLOPT_TIMEOUT, 2 );
		$this->assertTrue( ZDZ_Page_Fetch::tie( $plain, 'example.org', self::PUBLIC_V4 ) );
		$this->assertFalse( curl_exec( $plain ) );
		$this->assertSame( 1, curl_errno( $plain ), 'unsupported protocol' );
	}

	public function test_every_reason_has_words_and_none_of_them_is_the_code_word(): void {
		foreach ( array( 'no_run', 'budget', 'staging', 'transport', 'proxy', 'url', 'scheme', 'credentials', 'port', 'host', 'ip_literal', 'special_name',
			'own_site', 'denied', 'not_allowed', 'dns', 'address', 'time', 'redirect', 'redirects', 'status', 'type', 'empty', 'http', 'something_new' ) as $reason ) {
			$w = ZDZ_Page_Fetch::reason_words( $reason );
			$this->assertNotSame( '', $w );
			$this->assertStringNotContainsString( '_', $w, $reason );
		}
	}

	public function test_a_fetch_never_throws_whatever_it_is_handed(): void {
		foreach ( array( "https://example.org/\u{202e}x", 'https://exämple.org/', 'https://example.org/?' . str_repeat( 'a=b&', 100 ), 'HTTPS://EXAMPLE.ORG/UPPER', 'https://example.org' ) as $url ) {
			$r = ZDZ_Page_Fetch::fetch( $url, array( 'run' => 'odd:1', 'deadline' => 'soon', 'timeout' => array(), 'max_fetches' => 'many' ) );
			$this->assertTrue( is_bool( $r['ok'] ) );
			$this->assertIsString( $r['reason'] );
		}
		$this->assertTrue( ZDZ_Page_Fetch::fetch( 'HTTPS://EXAMPLE.ORG/UPPER', array( 'run' => 'odd:2' ) )['ok'], 'the scheme and host are not case-sensitive' );
		$this->assertSame( 'https://example.org/', ZDZ_Page_Fetch::check_url( 'https://example.org' )['url'] );
	}
}
