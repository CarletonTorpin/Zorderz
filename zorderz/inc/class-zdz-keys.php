<?php
/**
 * ZDZ_Keys -- the app's own key service.
 *
 * Why: whatever is sealed or signed with a key made from the WordPress salts breaks the moment the salts change, and
 * a host can change them without asking (a credential rotation that rewrites wp-config.php, a restore, a copy between
 * environments). Sealed under a key of the app's own, a change to the salts only signs people out.
 *
 * What it is: ONE master key, ZDZ_APP_KEY, a printed code (32 random bytes in base32: 52 characters in 13 groups of
 * four), kept in zdz-secrets.php beside wp-config.php and made on the operator's own computer (bin/make-app-keys.php),
 * never on the server. Every purpose gets its own subkey (libsodium KDF, context 'zdzkeys1', one fixed id per
 * purpose). A stored value is
 *
 *     zdzk1:<kid>:<base64url( nonce[24] || XChaCha20-Poly1305 ciphertext+tag )>
 *     kid = substr( hash_hmac( 'sha256', 'zdzk1 key id', master ), 0, 8 )      -- what every screen shows, never a key
 *     AD  = 'zdzk1|' . purpose . '|' . kid . '|' . bind                        -- a value moved to another row,
 *                                                                                 purpose or key id does not open
 * The rules:
 *   - the server never makes a key; a key that is missing, not a printed code, not the recorded one, or another
 *     site's (ZDZ_APP_KEY_SITE) LOCKS: seal() stores nothing (null, never plaintext), open() reads nothing (null, never
 *     ''), nothing is generated, nothing heals itself;
 *   - the key id is recorded (option zdz_keys_state: kid, prev_kid, site, at, by, how -- never a key) by itself only
 *     on true first use: no key id was ever recorded on this site, and no zdzk1 value is stored anywhere the scan
 *     looks. After that only an administrator records one: after a lost record by typing the printed key, after a
 *     move to a new address, or after a finished key change (record_key);
 *   - a deliberate key change: ZDZ_APP_KEY = new, ZDZ_APP_KEY_PREVIOUS = old; read with either, write with the new one;
 *     the App key page re-encrypts (ZDZ_Keys_Migrate) and then records the new key id.
 *
 * Nothing calls it yet: stored credentials (ZDZ_Core_Crypto), gate codes (ZDZ_Sealed, with its own ZDZ_SEALED_KEY) and
 * the salt watch work exactly as they did. Until zdz-secrets.php exists this class does nothing at all: no notice, no
 * option written, nothing on any page but Zorderz -> App key.
 *
 * Nothing here logs, returns or stores a key, a subkey or a salt.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Keys {

	const FORMAT    = 'zdzk1';
	const STATE_OPT = 'zdz_keys_state';    // [ kid, prev_kid, site, at, by, how ] -- never a key
	const EVER_OPT  = 'zdz_keys_ever';     // 1 once a key id was ever recorded here: written with every record, never deleted by code
	const CTX       = 'zdzkeys1';          // the KDF context: 8 bytes, never changed
	const KID_LABEL = 'zdzk1 key id';

	/**
	 * One fixed id per purpose. An id is never reused or renumbered; an unknown purpose is refused, never mapped to a
	 * default.
	 */
	const PURPOSES = array(
		'credentials'      => 1,    // stored provider keys and secrets
		'inbox-tokens'     => 2,    // mailbox sign-in tokens
		'inbox-content'    => 3,    // kept mail bodies and their summaries
		'scheduler-tokens' => 4,    // calendar sign-in tokens
		'vault-kek'        => 5,    // wraps the document vault's private data key
		'agreements-wrap'  => 6,    // wraps the agreements key
		'sealed-codes'     => 7,    // gate, lockbox, alarm, door and keypad codes
		'rp-codes'         => 8,    // the route planner's own sealed codes
		'rp-audio'         => 9,    // the route planner's recordings
		'hours-private'    => 10,   // private notes kept with hours
		'sign'             => 11,   // stateless signatures: sign-in state, photo grants, opaque ids
		'links'            => 12,   // public links that are signed rather than random
		'privacy-view'     => 13,   // Privacy View's codes
		'private'          => 14,   // the customer-detail coder
	);

	/** A job without a 'kids' reader is scanned item by item only up to this many items (else: fail closed). */
	const SCAN_LIMIT = 2000;

	/** Option rows the scan reads per query; it reads every matching row, a batch at a time. */
	const SCAN_BATCH = 100;

	/** This request's reading of the constants and the record (keys kept only in this array, never written). */
	private static $mem = null;

	/** This request's scan of stored key ids; forgotten after any write by the runner or a record. */
	private static $scan = false;

	/* ================================================================== the master key */

	/** Forget this request's reading (after record_key, and for tests). */
	public static function reset() {
		self::$mem  = null;
		self::$scan = false;
	}

	/** Forget the scan (the runner calls this after it writes). */
	public static function forget_scan() {
		self::$scan = false;
	}

	/** The fixed subkey table. */
	public static function purposes() {
		return self::PURPOSES;
	}

	/**
	 * A printed code -> its 32 bytes, or null. Read as a person copies one by hand: spaces and dashes ignored, 0, 1 and
	 * 8 read as O, I and B, case ignored; exactly 52 base32 characters.
	 */
	public static function parse( $code ) {
		if ( ! is_string( $code ) ) {
			return null;
		}
		$s = strtr( strtoupper( $code ), array( '0' => 'O', '1' => 'I', '8' => 'B' ) );
		$s = (string) preg_replace( '/[\s\-]/', '', $s );
		if ( 52 !== strlen( $s ) || ! preg_match( '/^[A-Z2-7]+$/D', $s ) ) {
			return null;
		}
		$alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$bits  = '';
		foreach ( str_split( $s ) as $c ) {
			$bits .= str_pad( decbin( strpos( $alpha, $c ) ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( substr( $bits, 0, 256 ), 8 ) as $b ) {
			$out .= chr( bindec( $b ) );
		}
		return 32 === strlen( $out ) ? $out : null;
	}

	private static function kid_of( $key ) {
		return substr( hash_hmac( 'sha256', self::KID_LABEL, $key ), 0, 8 );
	}

	/** A host as compared here: lower case, no scheme, path or port, no leading "www.". */
	public static function norm_host( $s ) {
		$s = strtolower( trim( (string) $s ) );
		$s = (string) preg_replace( '#^[a-z][a-z0-9+.\-]*://#', '', $s );
		$s = (string) preg_replace( '#[/?\#].*$#', '', $s );
		$s = (string) preg_replace( '#:\d+$#', '', $s );
		return (string) preg_replace( '#^www\.#', '', $s );
	}

	/** This site's host, as ZDZ_APP_KEY_SITE must name it. */
	public static function host() {
		$u = function_exists( 'home_url' ) ? (string) home_url() : '';
		$h = function_exists( 'wp_parse_url' ) ? wp_parse_url( $u, PHP_URL_HOST ) : parse_url( $u, PHP_URL_HOST );
		return self::norm_host( (string) $h );
	}

	/** The recorded state ([] when nothing is recorded). Never a key. */
	public static function recorded() {
		$r = get_option( self::STATE_OPT, array() );
		return is_array( $r ) ? $r : array();
	}

	/**
	 * The sodium EXTENSION. WordPress ships a pure-PHP stand-in for it, so the functions can exist without the
	 * extension, and that stand-in's sodium_memzero() throws. Without the extension: no_sodium, locked.
	 */
	private static function sodium_ok() {
		return extension_loaded( 'sodium' )
			&& function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
			&& function_exists( 'sodium_crypto_kdf_derive_from_key' )
			&& defined( 'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES' );
	}

	private static function lock( array $m, $state ) {
		$m['state'] = $state;
		return self::$mem = $m;
	}

	/** Read the constants and the record once per request. */
	private static function load() {
		if ( null !== self::$mem ) {
			return self::$mem;
		}
		$m = array(
			'state'      => '',
			'cur'        => null,
			'kid'        => '',
			'prev'       => null,
			'pkid'       => '',
			'prev_bad'   => false,
			'rotating'   => false,
			'unrecorded' => false,
			'site_why'   => '',
		);
		if ( ! self::sodium_ok() ) {
			return self::lock( $m, 'no_sodium' );
		}
		if ( ! defined( 'ZDZ_APP_KEY' ) ) {
			return self::lock( $m, 'missing' );
		}
		$cur = self::parse( constant( 'ZDZ_APP_KEY' ) );
		if ( null === $cur ) {
			return self::lock( $m, 'bad_constant' );
		}
		$m['cur'] = $cur;
		$m['kid'] = self::kid_of( $cur );
		if ( defined( 'ZDZ_APP_KEY_PREVIOUS' ) && '' !== trim( (string) constant( 'ZDZ_APP_KEY_PREVIOUS' ) ) ) {
			$p = self::parse( constant( 'ZDZ_APP_KEY_PREVIOUS' ) );
			if ( null === $p ) {
				$m['prev_bad'] = true;          // shown on the page; a bad previous key opens nothing
			} elseif ( ! hash_equals( $m['kid'], self::kid_of( $p ) ) ) {
				$m['prev'] = $p;
				$m['pkid'] = self::kid_of( $p );
			}
		}
		if ( defined( 'ZDZ_APP_KEY_SITE' ) && '' !== trim( (string) constant( 'ZDZ_APP_KEY_SITE' ) )
			&& self::norm_host( constant( 'ZDZ_APP_KEY_SITE' ) ) !== self::host() ) {
			$m['site_why'] = 'file';
			return self::lock( $m, 'other_site' );
		}
		$rec  = self::recorded();
		$rkid = (string) ( $rec['kid'] ?? '' );
		// A record made on another host means this database was copied here: locked, whatever key the file holds, so a
		// copied hand-written file without a site line can't read the copy either.
		if ( '' !== $rkid && '' !== (string) ( $rec['site'] ?? '' ) && self::norm_host( $rec['site'] ) !== self::host() ) {
			$m['site_why'] = 'record';
			return self::lock( $m, 'other_site' );
		}
		if ( '' === $rkid ) {
			// Locked while the scan runs, so a job callback that asks this class gets "locked", not an endless loop.
			self::lock( $m, 'mismatch' );
			// Nothing recorded. Recorded by itself ONLY on true first use: no key id ever recorded on this site, and no
			// zdzk1 value found anywhere the scan can look. Anything else (a scan can't see every place a value may
			// sit) waits for an administrator's Record on the App key page, which shows what the scan found.
			$found = self::sealed_kids();
			if ( ! get_option( self::EVER_OPT, false ) && array() === $found ) {
				$rkid = $m['kid'];
				self::write_record( $rkid, '', 0, 'first' );
			} else {
				$m['unrecorded'] = true;
				return self::lock( $m, 'mismatch' );
			}
		}
		if ( hash_equals( $rkid, $m['kid'] ) ) {
			return self::$mem = $m;
		}
		if ( '' !== $m['pkid'] && hash_equals( $rkid, $m['pkid'] ) ) {
			$m['rotating'] = true;
			return self::$mem = $m;
		}
		return self::lock( $m, 'mismatch' );   // never recorded here: a changed key does not heal itself
	}

	private static function write_record( $kid, $prev_kid, $by, $how ) {
		update_option(
			self::STATE_OPT,
			array(
				'kid'      => (string) $kid,
				'prev_kid' => (string) $prev_kid,
				'site'     => self::host(),
				'at'       => gmdate( 'Y-m-d H:i:s' ),
				'by'       => (int) $by,
				'how'      => (string) $how,
			),
			false
		);
		update_option( self::EVER_OPT, 1, false );
		self::$scan = false;
	}

	/**
	 * The distinct key ids found on stored zdzk1 values: anywhere in an option's value (a whole value or inside a
	 * serialized array), and every registered migration job (its 'kids' reader, else its items one by one). null when
	 * a job is too large to scan and has no reader. Values kept elsewhere (meta, an app's own table) are seen only
	 * through that component's job: every component that seals with this class registers one.
	 */
	public static function sealed_kids() {
		if ( false !== self::$scan ) {
			return self::$scan;
		}
		return self::$scan = self::scan_kids();
	}

	private static function scan_kids() {
		global $wpdb;
		$found = array();
		if ( is_object( $wpdb ) ) {
			// Every option row that holds a sealed value, a batch at a time: no row is left unread.
			$like = '%' . $wpdb->esc_like( self::FORMAT . ':' ) . '%';
			$last = 0;
			do {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_value FROM {$wpdb->options} WHERE option_id > %d AND option_value LIKE %s ORDER BY option_id ASC LIMIT %d", $last, $like, self::SCAN_BATCH ) );
				$rows = is_array( $rows ) ? $rows : array();
				foreach ( $rows as $row ) {
					$last = max( $last, (int) ( $row->option_id ?? 0 ) );
					if ( preg_match_all( '/' . self::FORMAT . ':([0-9a-f]{8}):/', (string) ( $row->option_value ?? '' ), $mm ) ) {
						foreach ( $mm[1] as $k ) {
							$found[] = $k;
						}
					}
				}
			} while ( count( $rows ) >= self::SCAN_BATCH && $last > 0 );
		}
		if ( class_exists( 'ZDZ_Keys_Migrate' ) ) {
			foreach ( ZDZ_Keys_Migrate::jobs() as $job ) {
				$kids = ZDZ_Keys_Migrate::kids_in( $job, self::SCAN_LIMIT );
				if ( null === $kids ) {
					return null;
				}
				$found = array_merge( $found, $kids );
			}
		}
		$found = array_values(
			array_unique(
				array_filter(
					$found,
					function ( $k ) {
						return 1 === preg_match( '/^[0-9a-f]{8}$/D', (string) $k );
					}
				)
			)
		);
		sort( $found );
		return $found;
	}

	/** ok · missing · bad_constant · mismatch · other_site · no_sodium. */
	public static function state() {
		$s = self::load()['state'];
		return '' === $s ? 'ok' : $s;
	}

	public static function ready() {
		return 'ok' === self::state();
	}

	/** A deliberate key change is under way: the recorded key is ZDZ_APP_KEY_PREVIOUS. */
	public static function rotating() {
		return self::ready() && self::load()['rotating'];
	}

	/** Today's key id (8 hex), '' when not ready. */
	public static function key_id() {
		return self::ready() ? self::load()['kid'] : '';
	}

	/**
	 * Has the key been set up on this site (a ZDZ_APP_KEY line, or a key id recorded now or ever)? Until it has, no
	 * notice is shown anywhere.
	 */
	public static function set_up() {
		return defined( 'ZDZ_APP_KEY' ) || '' !== (string) ( self::recorded()['kid'] ?? '' ) || (bool) get_option( self::EVER_OPT, false );
	}

	/**
	 * For Zorderz -> App key and the notices: what the constants and the record say. Key ids only (an 8-hex HMAC of a
	 * key is not the key); never a key, never a subkey.
	 */
	public static function info() {
		$m   = self::load();
		$rec = self::recorded();
		return array(
			'state'         => self::state(),
			'set_up'        => self::set_up(),
			'kid'           => (string) $m['kid'],                 // the key in the file, even when locked
			'prev_kid'      => (string) $m['pkid'],
			'prev_bad'      => (bool) $m['prev_bad'],
			'rotating'      => (bool) $m['rotating'] && '' === $m['state'],
			'unrecorded'    => (bool) $m['unrecorded'],
			'site_why'      => (string) $m['site_why'],          // other_site: 'file' (ZDZ_APP_KEY_SITE) or 'record'
			'secrets_load'  => defined( 'ZDZ_SECRETS_LOAD' ),
			'recorded_kid'  => (string) ( $rec['kid'] ?? '' ),
			'recorded_site' => (string) ( $rec['site'] ?? '' ),
			'recorded_at'   => (string) ( $rec['at'] ?? '' ),
			'recorded_by'   => (int) ( $rec['by'] ?? 0 ),
			'recorded_how'  => (string) ( $rec['how'] ?? '' ),
			'site_const'    => defined( 'ZDZ_APP_KEY_SITE' ) ? self::norm_host( constant( 'ZDZ_APP_KEY_SITE' ) ) : '',
			'host'          => self::host(),
			'wp_secrets'    => defined( 'WP_SECRETS_KEY' ) && '' !== (string) constant( 'WP_SECRETS_KEY' ),
		);
	}

	/**
	 * This class's own bookkeeping belongs to one install and never travels in a data export or import: the record
	 * says which key THIS site's sealed values are under and on which address, and sealed values do not travel.
	 * Filter zdz_export_non_portable_option_patterns.
	 */
	public static function export_non_portable( $patterns ) {
		$patterns   = is_array( $patterns ) ? $patterns : array();
		$patterns[] = '/^zdz_keys_(state|ever)$/';
		$patterns[] = '/^zdz_keys_migrate_/';
		return $patterns;
	}

	/* ================================================================== sealing */

	private static function subkey( $purpose, $key ) {
		if ( ! is_string( $purpose ) || ! isset( self::PURPOSES[ $purpose ] ) || ! is_string( $key ) || 32 !== strlen( $key ) ) {
			return null;
		}
		return sodium_crypto_kdf_derive_from_key( 32, self::PURPOSES[ $purpose ], self::CTX, $key );
	}

	private static function ad( $purpose, $kid, $bind ) {
		return self::FORMAT . '|' . $purpose . '|' . $kid . '|' . $bind;
	}

	private static function b64u( $b ) {
		return rtrim( strtr( base64_encode( $b ), '+/', '-_' ), '=' );
	}

	private static function unb64u( $s ) {
		$s = strtr( (string) $s, '-_', '+/' );
		$p = strlen( $s ) % 4;
		return base64_decode( $p ? $s . str_repeat( '=', 4 - $p ) : $s, true );
	}

	/**
	 * Text as this class takes it: a string, or a number as written ('' for nothing at all). null for anything else: an
	 * array or an object is never sealed, bound or signed as the word PHP would make of it.
	 */
	private static function text( $v ) {
		if ( null === $v || false === $v ) {
			return '';
		}
		return is_scalar( $v ) ? (string) $v : null;
	}

	/** Is $s a stored value in this format (header and payload, nothing before or after)? */
	public static function is_zdzk1( $s ) {
		return is_string( $s ) && 1 === preg_match( '/^' . self::FORMAT . ':[0-9a-f]{8}:[A-Za-z0-9_-]+$/D', $s );
	}

	/** The key id a stored value's header names ('' when it isn't zdzk1). */
	public static function header_key_id( $s ) {
		return self::is_zdzk1( $s ) ? substr( $s, strlen( self::FORMAT ) + 1, 8 ) : '';
	}

	/**
	 * Seal $plain for $purpose, bound to $bind (the option name, or "<table>:<id>:<column>"). '' for ''. null when the
	 * key isn't ready or the purpose is unknown: the caller then stores NOTHING (never the plaintext).
	 */
	public static function seal( $purpose, $plain, $bind = '' ) {
		$plain = self::text( $plain );
		$bind  = self::text( $bind );
		if ( null === $plain || null === $bind ) {
			return null;
		}
		if ( '' === $plain ) {
			return '';
		}
		if ( ! self::ready() ) {
			return null;
		}
		$m  = self::load();
		$sk = self::subkey( $purpose, $m['cur'] );
		if ( null === $sk ) {
			return null;
		}
		$n  = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$ct = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plain, self::ad( $purpose, $m['kid'], $bind ), $n, $sk );
		sodium_memzero( $sk );
		return self::FORMAT . ':' . $m['kid'] . ':' . self::b64u( $n . $ct );
	}

	/** [ plaintext|null, why ]: why '' = read. */
	private static function open_why( $purpose, $stored, $bind ) {
		$stored = self::text( $stored );
		$bind   = self::text( $bind );
		if ( '' === $stored ) {
			return array( '', '' );
		}
		if ( null === $stored || ! self::is_zdzk1( $stored ) ) {
			return array( null, 'not_zdzk1' );
		}
		if ( ! self::ready() ) {
			return array( null, self::state() );
		}
		$m   = self::load();
		$kid = self::header_key_id( $stored );
		if ( hash_equals( $m['kid'], $kid ) ) {
			$key = $m['cur'];
		} elseif ( '' !== $m['pkid'] && hash_equals( $m['pkid'], $kid ) ) {
			$key = $m['prev'];
		} else {
			return array( null, 'unknown_key' );
		}
		if ( ! is_string( $purpose ) || ! isset( self::PURPOSES[ $purpose ] ) ) {
			return array( null, 'unknown_purpose' );
		}
		$raw = self::unb64u( substr( $stored, strlen( self::FORMAT ) + 10 ) );   // after "zdzk1:", the 8 hex and ":"
		$nl  = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
		if ( false === $raw || strlen( $raw ) < $nl + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES ) {
			return array( null, 'tampered' );
		}
		if ( null === $bind ) {
			return array( null, 'tampered' );   // bound to something that is not text: no value was ever sealed to that
		}
		$sk = self::subkey( $purpose, $key );
		$pt = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( substr( $raw, $nl ), self::ad( $purpose, $kid, $bind ), substr( $raw, 0, $nl ), $sk );
		sodium_memzero( $sk );
		return false === $pt ? array( null, 'tampered' ) : array( $pt, '' );
	}

	/** The plaintext, or null when it can't be read (never '' for a sealed value). '' for ''. */
	public static function open( $purpose, $stored, $bind = '' ) {
		return self::open_why( $purpose, $stored, $bind )[0];
	}

	/** Why open() said null: not_zdzk1 · unknown_key · unknown_purpose · tampered · or the state. '' = it reads. */
	public static function why( $stored, $purpose = 'credentials', $bind = '' ) {
		return self::open_why( $purpose, $stored, $bind )[1];
	}

	/** Open with either key and seal with the current one; the same string when already current; null when unreadable. */
	public static function reseal( $purpose, $stored, $bind = '' ) {
		if ( self::ready() && self::is_zdzk1( $stored ) && self::header_key_id( $stored ) === self::key_id() ) {
			return $stored;
		}
		$p = self::open( $purpose, $stored, $bind );
		return ( null === $p || '' === $p ) ? null : self::seal( $purpose, $p, $bind );
	}

	/** HMAC-SHA256 hex of $msg under $purpose's subkey (signing: links, sign-in state, grants, opaque ids). null when not ready. */
	public static function mac( $purpose, $msg ) {
		$msg = self::text( $msg );
		if ( null === $msg || ! self::ready() ) {
			return null;
		}
		$sk = self::subkey( $purpose, self::load()['cur'] );
		if ( null === $sk ) {
			return null;
		}
		$h = hash_hmac( 'sha256', $purpose . '|' . $msg, $sk );
		sodium_memzero( $sk );
		return $h;
	}

	/** Does $mac sign $msg for $purpose, under the current or the previous key? */
	public static function verify_mac( $purpose, $msg, $mac ) {
		$msg = self::text( $msg );
		if ( null === $msg || ! self::ready() || ! is_string( $mac ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $mac ) ) {
			return false;
		}
		$m = self::load();
		foreach ( array( $m['cur'], $m['prev'] ) as $k ) {
			$sk = null === $k ? null : self::subkey( $purpose, $k );
			if ( null === $sk ) {
				continue;
			}
			$ok = hash_equals( hash_hmac( 'sha256', $purpose . '|' . $msg, $sk ), $mac );
			sodium_memzero( $sk );
			if ( $ok ) {
				return true;
			}
		}
		return false;
	}

	/* ================================================================== who may record, and recording */

	private static function is_kiosk( $uid ) {
		$uid = (int) $uid;
		if ( class_exists( 'ZDZ_Kiosk_Demo' ) && method_exists( 'ZDZ_Kiosk_Demo', 'is_active' ) && ZDZ_Kiosk_Demo::is_active() ) {
			return true;   // Demo Mode: the device runs as the shared account, whoever started it
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
			return true;   // the shared-device trait, whatever the role is called
		}
		if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) && 'kiosk' === ZDZ_Capabilities::tier_of( $uid ) ) {
			return true;
		}
		$u = get_userdata( $uid );
		if ( ! $u ) {
			return true;   // nobody to ask about: refused
		}
		$shared = ( class_exists( 'ZDZ_User_Roles' ) && defined( 'ZDZ_User_Roles::SHARED_DEVICE_ROLE' ) ) ? ZDZ_User_Roles::SHARED_DEVICE_ROLE : 'zdz_general';
		return in_array( $shared, (array) $u->roles, true );
	}

	private static function is_admin_tier( $uid ) {
		if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) ) {
			return 'admin' === ZDZ_Capabilities::tier_of( (int) $uid );
		}
		return function_exists( 'user_can' ) && user_can( (int) $uid, 'manage_options' );
	}

	/**
	 * May $uid manage the app's key? An administrator, signed in as themselves at this device: never the shared device,
	 * Demo Mode, View As, or an account in Privacy View (which can hold an administrator's role).
	 */
	public static function may_manage( $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 || (int) get_current_user_id() !== $uid || ! get_userdata( $uid ) ) {
			return false;
		}
		if ( self::is_kiosk( $uid ) || ! self::is_admin_tier( $uid ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Privacy_View' ) && method_exists( 'ZDZ_Privacy_View', 'is_on' ) && ZDZ_Privacy_View::is_on( $uid ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_View_As' ) ) {
			if ( ( method_exists( 'ZDZ_View_As', 'is_emulating' ) && ZDZ_View_As::is_emulating() )
				|| ( method_exists( 'ZDZ_View_As', 'get_emulated_user_id' ) && ZDZ_View_As::get_emulated_user_id() ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * What an administrator's Record would record now: [ key id, how ], or null when Record is not allowed.
	 *   - the site moved to a new address: the record names another host, but the file names THIS host and holds the
	 *     recorded key, and no stored value is under another key ('moved'). A copy between environments never
	 *     qualifies: the copied file names the other host, and a file that names no host can't say which it is for;
	 *   - nothing recorded, the key parses, it is this site's, and every zdzk1 value found carries this key's id or the
	 *     previous key's (the previous one is then recorded: a key change under way). The administrator types the
	 *     printed key; nothing found is not proof, so it is theirs to say;
	 *   - a key change whose re-encryption has finished: the new key.
	 * Never otherwise: a key the stored values contradict is never recorded, by anyone.
	 */
	public static function recordable() {
		$m = self::load();
		if ( '' === $m['kid'] ) {
			return null;
		}
		if ( 'record' === $m['site_why'] && defined( 'ZDZ_APP_KEY_SITE' ) && self::norm_host( constant( 'ZDZ_APP_KEY_SITE' ) ) === self::host()
			&& hash_equals( (string) ( self::recorded()['kid'] ?? '' ), $m['kid'] ) ) {
			$found = self::sealed_kids();
			if ( null !== $found && ! array_diff( $found, array( $m['kid'] ) ) ) {
				return array( $m['kid'], 'moved' );
			}
			return null;
		}
		if ( $m['unrecorded'] ) {
			$found = self::sealed_kids();
			if ( null === $found || array_diff( $found, array_filter( array( $m['kid'], $m['pkid'] ) ) ) ) {
				return null;
			}
			return ( '' !== $m['pkid'] && in_array( $m['pkid'], $found, true ) ) ? array( $m['pkid'], 'found' ) : array( $m['kid'], 'recorded' );
		}
		if ( self::rotating() && class_exists( 'ZDZ_Keys_Migrate' ) && ZDZ_Keys_Migrate::all_done() ) {
			return array( $m['kid'], 'rotated' );
		}
		return null;
	}

	/**
	 * Record a key id as THE key: an administrator as themselves, only what recordable() allows. After a lost record
	 * the printed key itself must be typed in ($typed: compared by key id, never kept). A changed key never records
	 * itself.
	 */
	public static function record_key( $uid, $typed = null ) {
		$uid = (int) $uid;
		if ( ! self::may_manage( $uid ) ) {
			return false;
		}
		$r = self::recordable();
		if ( null === $r ) {
			return false;
		}
		if ( self::load()['unrecorded'] && ( ! is_string( $typed ) || ! hash_equals( $r[0], self::code_kid( $typed ) ) ) ) {
			return false;
		}
		$prev = (string) ( self::recorded()['kid'] ?? '' );
		self::write_record( $r[0], $prev, $uid, $r[1] );
		self::reset();
		return true;
	}

	/** The key id of a typed printed code ('' when it isn't one, or isn't text). The code is never kept. */
	public static function code_kid( $typed ) {
		$k = self::parse( $typed );
		if ( null === $k ) {
			return '';
		}
		$kid = self::kid_of( $k );
		if ( function_exists( 'sodium_memzero' ) && extension_loaded( 'sodium' ) ) {
			sodium_memzero( $k );
		}
		return $kid;
	}

	/** Does a typed printed code match today's key? (Counts and words are the page's; the code is never kept.) */
	public static function code_matches( $typed ) {
		return 'this' === self::code_reads_as( $typed ) && self::ready();
	}

	/**
	 * Which key a typed printed code is: 'this' (the key in zdz-secrets.php), 'recorded' (the key the data was sealed
	 * with, when that differs), 'previous' (ZDZ_APP_KEY_PREVIOUS), 'none', or 'not_a_code'. Compared by key id only.
	 */
	public static function code_reads_as( $typed ) {
		$kid = self::code_kid( $typed );
		if ( '' === $kid ) {
			return 'not_a_code';
		}
		$m = self::load();
		if ( '' !== $m['kid'] && hash_equals( $m['kid'], $kid ) ) {
			return 'this';
		}
		$rkid = (string) ( self::recorded()['kid'] ?? '' );
		if ( '' !== $rkid && hash_equals( $rkid, $kid ) ) {
			return 'recorded';
		}
		if ( '' !== $m['pkid'] && hash_equals( $m['pkid'], $kid ) ) {
			return 'previous';
		}
		return 'none';
	}
}
