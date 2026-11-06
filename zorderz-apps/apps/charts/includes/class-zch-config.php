<?php
/**
 * Where Charts finds its hub, and whether it may use it here.
 *
 * Charts is a thin front end: every figure comes from a Charts hub, a separate service
 * the business runs (the reference hub ships with Zorderz under hub/, and any service
 * that speaks the same sealed-envelope contract will do). Four values connect the two:
 *
 *   hub address     the hub's RPC URL (https://…/v1/rpc), reached only from PHP
 *   hub public key  the hub's public key, base64url; a comma list while rotating, newest first
 *   site secret     this site's secret key, base64url; never shown, never sent to a browser
 *   live hosts      the host name(s) this site answers on when it is the real site
 *
 * They come from wp-config.php constants first (ZCH_HUB_URL, ZCH_HUB_PUBLIC, ZCH_SITE_SECRET,
 * ZCH_LIVE_HOSTS, and optionally ZCH_HUB_HEADERS, a JSON object of extra request headers for
 * a tunnel or access proxy), then from the platform's credentials layer (Zorderz → Credentials,
 * the "Charts hub" entry registered by credentials_service(): the secret and the headers sealed
 * at rest, never shown), then from Charts' own settings page, kept as the fallback for a site
 * that has not moved them yet (its secret sealed too).
 *
 * The live-hosts lock: the keys are used only when this site's own host is on the list. A
 * staging or development copy of the site (or of its database or wp-config.php) therefore
 * never reaches the hub. With no list the keys are not used anywhere: deny by default.
 *
 * With no hub configured Charts runs in local mode and says so: saved charts, offline
 * snapshots and the territory map still open; nothing that needs a figure is guessed.
 *
 * @package Zorderz\Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCH_Config {

	const OPTION = 'zch_hub';

	/** Injected in tests: an array that replaces the stored option. */
	public static $stored = null;

	/** The stored settings-layer values (constants are read separately). */
	public static function stored(): array {
		if ( is_array( self::$stored ) ) {
			return self::$stored;
		}
		$s = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return is_array( $s ) ? $s : array();
	}

	private static function constant_or( string $name, string $fallback ): string {
		if ( defined( $name ) ) {
			$v = constant( $name );
			if ( is_string( $v ) && '' !== trim( $v ) ) {
				return trim( $v );
			}
		}
		return $fallback;
	}

	/** The service id on Zorderz → Credentials. */
	const CRED_SERVICE = 'charts_hub';

	/** This request's values from the credentials layer (flush_creds() forgets them). */
	private static $creds = null;

	/** The credentials layer's rows: field => the key after Core's prefix (zdz_core_). */
	const CRED_KEYS = array(
		'url'         => 'charts_hub_url',
		'hub_public'  => 'charts_hub_public',
		'site_secret' => 'charts_site_secret',
		'headers'     => 'charts_hub_headers',
	);

	/**
	 * The Charts hub on Zorderz → Credentials (filter zdz_core_credentials_services): the hub address, the hub's
	 * public key, this site's secret key and the extra request headers. The secret and the headers are sealed at rest
	 * and never shown; Charts' own settings rows are listed as copies, so "Manage here" adopts what Charts holds.
	 */
	public static function credentials_service( $services ) {
		$services = is_array( $services ) ? $services : array();
		if ( ! class_exists( 'ZDZ_Core_Crypto' ) ) {
			return $services;
		}
		$P = ZDZ_Core_Crypto::PLAIN;
		$F = ZDZ_Core_Crypto::FIXED;
		$o = self::OPTION;
		$l = __( 'Charts settings', 'zorderz' );
		$c = __( 'Zorderz Core: Charts reads it first', 'zorderz' );
		$services[ self::CRED_SERVICE ] = array(
			'kind'      => 'app',
			'label'     => __( 'Charts hub', 'zorderz' ),
			'group'     => __( 'Charts', 'zorderz' ),
			'note'      => __( 'Where Charts reaches its hub, and the keys of the sealed envelope between them. The site secret and the extra headers are kept sealed and never shown; the hub\'s public key and this site\'s public key are not secrets. The keys are used only on the live site named under Charts → Settings.', 'zorderz' ),
			'fields'    => array(
				'url'         => array( __( 'Hub address', 'zorderz' ), false, 'valid' => array( __CLASS__, 'valid_url' ) ),
				'hub_public'  => array( __( 'Hub\'s public key', 'zorderz' ), false, 'valid' => array( __CLASS__, 'valid_public' ) ),
				'site_secret' => array( __( 'This site\'s secret key', 'zorderz' ), true, 'valid' => array( __CLASS__, 'valid_key' ) ),
				'headers'     => array( __( 'Extra request headers (JSON)', 'zorderz' ), true, 'valid' => array( __CLASS__, 'valid_headers' ) ),
			),
			'required'  => array( 'url', 'hub_public', 'site_secret' ),
			'canonical' => array(
				'url'         => 'zdz_core_' . self::CRED_KEYS['url'],
				'hub_public'  => 'zdz_core_' . self::CRED_KEYS['hub_public'],
				'site_secret' => 'zdz_core_' . self::CRED_KEYS['site_secret'],
				'headers'     => 'zdz_core_' . self::CRED_KEYS['headers'],
			),
			// Core's row first (the secrets sealed), then the copy in Charts' own settings, in the format Charts reads.
			'copies'    => array(
				'url'         => array( 'zdz_core_' . self::CRED_KEYS['url'] => array( $P, $c ), $o => array( $P, $l, 'field' => 'url' ) ),
				'hub_public'  => array( 'zdz_core_' . self::CRED_KEYS['hub_public'] => array( $P, $c ), $o => array( $P, $l, 'field' => 'hub_public' ) ),
				'site_secret' => array( 'zdz_core_' . self::CRED_KEYS['site_secret'] => array( $F, $c ), $o => array( $F, $l, 'field' => 'site_secret' ) ),
				'headers'     => array( 'zdz_core_' . self::CRED_KEYS['headers'] => array( $F, $c ), $o => array( $F, $l, 'field' => 'headers' ) ),
			),
		);
		return $services;
	}

	/** An http(s) address with no spaces or quotes. PURE. */
	public static function valid_url( $v ): bool {
		return 1 === preg_match( '#^https?://[^\s"\'<>]+$#i', (string) $v );
	}

	/** One key or a comma list of keys (while the hub rotates), each 43 base64url characters. PURE. */
	public static function valid_public( $v ): bool {
		foreach ( explode( ',', (string) $v ) as $k ) {
			if ( ! self::valid_key( trim( $k ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** One 32-byte key, base64url (43 characters). PURE. */
	public static function valid_key( $v ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', (string) $v );
	}

	/** A JSON object of header name => value. PURE. */
	public static function valid_headers( $v ): bool {
		$h = json_decode( (string) $v, true );
		return is_array( $h ) && ( ! $h || array_keys( $h ) !== range( 0, count( $h ) - 1 ) );
	}

	/** Is the hub connection managed on Zorderz → Credentials? */
	public static function managed(): bool {
		return class_exists( 'ZDZ_Core_Credentials' ) && method_exists( 'ZDZ_Core_Credentials', 'engaged' ) && ZDZ_Core_Credentials::engaged( self::CRED_SERVICE );
	}

	/**
	 * A value from the credentials layer: Core's row for the field, else the copy in Charts' own settings, opened as
	 * each is stored (ZDZ_Core_Credentials::current_values()). '' when neither holds one or the layer is not here.
	 */
	private static function cred( string $field ): string {
		if ( null === self::$creds ) {
			self::$creds = array();
			if ( class_exists( 'ZDZ_Core_Credentials' ) && method_exists( 'ZDZ_Core_Credentials', 'current_values' )
				&& isset( ZDZ_Core_Credentials::services()[ self::CRED_SERVICE ] ) ) {
				try {
					self::$creds = ZDZ_Core_Credentials::current_values( self::CRED_SERVICE );
				} catch ( \Throwable $e ) {
					self::$creds = array();
				}
			}
		}
		return trim( (string) ( self::$creds[ $field ] ?? '' ) );
	}

	/** Forget this request's credential values (after a save; the suites). */
	public static function flush_creds(): void {
		self::$creds = null;
	}

	/** wp-config.php, then the credentials layer, then Charts' own settings. */
	private static function pick( string $constant, string $field, string $own ): string {
		$c = self::cred( $field );
		return self::constant_or( $constant, '' !== $c ? $c : $own );
	}

	/** A stored secret, opened when it was sealed at rest. */
	private static function secret( string $key ): string {
		$v = (string) ( self::stored()[ $key ] ?? '' );
		if ( '' === $v ) {
			return '';
		}
		return class_exists( 'ZDZ_Core_Crypto' ) ? (string) ZDZ_Core_Crypto::plain( $v ) : $v;
	}

	public static function hub_url(): string {
		return self::pick( 'ZCH_HUB_URL', 'url', (string) ( self::stored()['url'] ?? '' ) );
	}

	public static function hub_public(): string {
		return self::pick( 'ZCH_HUB_PUBLIC', 'hub_public', (string) ( self::stored()['hub_public'] ?? '' ) );
	}

	public static function site_secret(): string {
		return self::pick( 'ZCH_SITE_SECRET', 'site_secret', self::secret( 'site_secret' ) );
	}

	/** Extra request headers (a tunnel's or an access proxy's), as name => value. */
	public static function headers(): array {
		$raw = self::pick( 'ZCH_HUB_HEADERS', 'headers', self::secret( 'headers' ) );
		$h   = '' !== $raw ? json_decode( $raw, true ) : array();
		$out = array();
		foreach ( is_array( $h ) ? $h : array() as $k => $v ) {
			if ( is_string( $k ) && preg_match( '/^[A-Za-z0-9-]{1,64}$/', $k ) && is_scalar( $v ) ) {
				$out[ $k ] = str_replace( array( "\r", "\n" ), '', (string) $v );
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			$f   = apply_filters( 'zch_hub_request_headers', $out );
			$out = is_array( $f ) ? $f : $out;
		}
		return $out;
	}

	/** The host names the keys may be used on. */
	public static function live_hosts(): array {
		$raw = self::constant_or( 'ZCH_LIVE_HOSTS', (string) ( self::stored()['live_hosts'] ?? '' ) );
		return array_values( array_filter( array_map( 'trim', explode( ',', strtolower( $raw ) ) ) ) );
	}

	/** This site's own host name. */
	public static function this_host(): string {
		$url = function_exists( 'home_url' ) ? home_url() : '';
		$h   = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_HOST ) : parse_url( $url, PHP_URL_HOST );
		return strtolower( (string) $h );
	}

	/** True only on a host the business named as the live site (and never while the platform's staging guard is on). */
	public static function live_host(): bool {
		if ( class_exists( 'ZDZ_Staging_Guard' ) && method_exists( 'ZDZ_Staging_Guard', 'is_active' ) && ZDZ_Staging_Guard::is_active() ) {
			return false;
		}
		$host = self::this_host();
		return '' !== $host && in_array( $host, self::live_hosts(), true );
	}

	/** Which constants are defined (names only, for the settings page). */
	public static function from_constants(): bool {
		foreach ( array( 'ZCH_HUB_URL', 'ZCH_SITE_SECRET', 'ZCH_HUB_PUBLIC' ) as $c ) {
			if ( defined( $c ) ) {
				return true;
			}
		}
		return false;
	}

	/** connected | not_configured | not_live */
	public static function state(): string {
		if ( '' === self::hub_url() || '' === self::site_secret() || '' === self::hub_public() ) {
			return 'not_configured';
		}
		if ( ! self::live_host() ) {
			return 'not_live';
		}
		return 'connected';
	}

	/** What the business calls the machine the hub runs on, in "may be asleep" messages. */
	public static function hub_label(): string {
		$l = trim( (string) ( self::stored()['label'] ?? '' ) );
		return '' !== $l ? $l : __( 'the hub computer', 'zorderz' );
	}

	/** The plain sentence for a page or an assistant when no hub can be used here. */
	public static function local_message(): string {
		if ( 'not_live' === self::state() ) {
			return __( 'Charts is in local mode: this copy of the site is not the live site, so the hub is not used here. Saved charts and the territory map still open.', 'zorderz' );
		}
		return __( 'Charts is in local mode: no Charts hub is connected to this site yet. Saved charts and the territory map still open; an administrator connects a hub under Charts → Settings.', 'zorderz' );
	}

	/** A secret sealed at rest with the platform's credential format (as it is when that is not available). */
	public static function seal( string $plain ): string {
		return class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'seal_secret' ) ? ZDZ_Core_Settings::seal_secret( $plain ) : $plain;
	}

	/**
	 * Validate and store the settings-layer values (the settings page calls this). While the hub connection is managed
	 * on Zorderz → Credentials, only the live hosts and the label are this page's: the address and the keys are kept.
	 */
	public static function save( array $in ): void {
		$cur = self::stored();
		$out = $cur;
		if ( self::managed() ) {
			// The address and the keys stay as they are: they change on Zorderz → Credentials.
			$in = array_merge( $in, array(
				'url'            => (string) ( $cur['url'] ?? '' ),
				'hub_public'     => (string) ( $cur['hub_public'] ?? '' ),
				'site_secret'    => '',
				'forget_secret'  => false,
				'headers'        => '',
				'forget_headers' => false,
			) );
		}
		$url = trim( (string) ( $in['url'] ?? '' ) );
		$out['url'] = ( '' === $url || preg_match( '#^https?://[^\s"\'<>]+$#i', $url ) ) ? $url : (string) ( $cur['url'] ?? '' );
		$keys = array();
		foreach ( explode( ',', (string) ( $in['hub_public'] ?? '' ) ) as $k ) {
			$k = trim( $k );
			if ( preg_match( '/^[A-Za-z0-9_-]{43}$/', $k ) ) {
				$keys[] = $k;
			}
		}
		$out['hub_public'] = implode( ',', $keys );
		$secret = trim( (string) ( $in['site_secret'] ?? '' ) );
		if ( '' !== $secret && preg_match( '/^[A-Za-z0-9_-]{43}$/', $secret ) ) {
			$out['site_secret'] = self::seal( $secret );
		}
		if ( ! empty( $in['forget_secret'] ) ) {
			$out['site_secret'] = '';
		}
		$headers = trim( (string) ( $in['headers'] ?? '' ) );
		if ( '' !== $headers && is_array( json_decode( $headers, true ) ) ) {
			$out['headers'] = self::seal( $headers );
		} elseif ( ! empty( $in['forget_headers'] ) ) {
			$out['headers'] = '';
		}
		$hosts = array();
		foreach ( explode( ',', strtolower( (string) ( $in['live_hosts'] ?? '' ) ) ) as $h ) {
			$h = trim( $h );
			if ( preg_match( '/^[a-z0-9.-]{1,253}$/', $h ) ) {
				$hosts[] = $h;
			}
		}
		if ( ! $hosts && '' !== $out['url'] ) {
			$hosts = array( self::this_host() );       // a first save names the site it is made on.
		}
		$out['live_hosts'] = implode( ',', array_unique( array_filter( $hosts ) ) );
		$out['label']      = mb_substr( sanitize_text_field( (string) ( $in['label'] ?? '' ) ), 0, 60 );
		update_option( self::OPTION, $out, false );
		self::flush_creds();
	}
}
