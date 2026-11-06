<?php
/**
 * ZDZ_Sealed -- the one vault for gate, lockbox, alarm, door and keypad codes.
 *
 * An access code is never kept or printed in plain text: not on an invoice or an estimate, not on a public page, not in
 * what is sent to a model. A signed-in person the rules allow can open one; everyone else sees "[gate code sealed]".
 *
 *   find( $text )               where the codes are: [ [start, length, kind], ... ] (byte offsets). Never the code,
 *                               nor how long it is.
 *   cut( $text, $scope )        every code in $text becomes "[gate code sealed]" (or lockbox, alarm, door, keypad). With
 *                               a scope (['job', 12], ['estimate', 7], ['address', 3], ['street', 'elm-st|12345']) each
 *                               code is sealed into the vault table first; without one it is only cut out (words for a
 *                               model: a code never reaches a model in any form). cut_ids() also returns the ids.
 *   seal( $kind, $code, $scope ) one code into the vault -> its id (0 when it could not be kept).
 *   open( $id, $uid )           the code, for an allowed person only. Every opening is logged first (no log row, no
 *                               code); refusals are logged once per person, code and reason every 10 minutes.
 *   why_not( $id, $uid )        '' when $uid may open it now, else the reason. Never opens anything.
 *   info( $id, $uid )           kind, scope, date, may_open: what a screen shows beside "Show code". Never the code.
 *   log( $id )                  the openings and refusals (who, when, basis). Admin screens only.
 *
 * Who may open: the admin tier always; the shared device and Demo Mode never; anyone else only when the app that keeps
 * the code says so through the filter zdz_sealed_may_open( false, $row, $uid ) (the row without its sealed text), such
 * as a jobs app answering for the person assigned the work that day. With no app answering, only admins open.
 *
 * The key is derived from the site's SECURE_AUTH salt (wp-config.php, never the database), with its own context
 * ("zdz-sealed-v1|..."). A copied database alone opens nothing. Three things keep that key safe to hold on to:
 *   - fail closed without real salts (salts_real()): no sealing and no opening when AUTH_KEY, AUTH_SALT,
 *     SECURE_AUTH_KEY or SECURE_AUTH_SALT is missing, the placeholder, or under 32 characters (WordPress would then
 *     keep its salts in the database, and a database copy would open the codes);
 *   - a check value (option zdz_sealed_key_check: an HMAC of a fixed label under the key, never the key), stored on
 *     first use. If the key ever differs, the codes are LOCKED: nothing is sealed or opened, admins are told "Gate
 *     codes are locked: the site's keys changed", and the check is never re-stored under the new key by itself;
 *   - a recovery code: Zorderz -> Gate code key shows today's key as a printed code (base32 in groups of four), and
 *     ZDZ_SEALED_KEY in wp-config.php holding that code wins over the derivation. Codes sealed today then keep opening
 *     even if the salts change, and the key itself never changes. The printed code is read with the look-alikes a
 *     person copying it by hand makes (0 as O, 1 as I, 8 as B; spaces and dashes ignored).
 * ready() means codes can be found and cut (apps cut only while it is true, so it never turns false just because the
 * key is locked); can_keep() is the key's state. While can_keep() is false codes are still cut, never kept or opened
 * (fail closed). A lock's last way out, accept_current_key(), is an administrator typing ACCEPT on Gate code key: it
 * records the check under today's key and changes no key.
 *
 * The reader. A code has at least 3 digits and at most 16 characters (so "gate 2 hung", "door 3 of 5" and "Code 4
 * Pools" stay words); a written code of 3 or more digits is taken alone ("gate code 4821 2 hours" seals 4821, never
 * 48212) unless a bare hyphen joins the next group ("555-0142"); "for" / "to" count as digits only beside spoken
 * numbers, so "code is 8 to 5" stays words; "zip code 12345", "building code 2022", "fire code 4904" are never codes;
 * and "Gate 1450 Oak St" is a house number. Look-alike characters are read plainly first (a non-breaking space,
 * full-width digits, an en dash, curly quotes), a code word may be joined to "code" ("gatecode 1234", "codeis4821"), a
 * few words may stand between ("Gate code for the Smith house is 4821"), and every further code right after one is cut
 * too ("4821 or 4822", "4821/4822", "Gate codes: 4821, 9911", "1234 5678"), each as a code of its own. What it reads:
 *   - a kind and a code word, spoken digits, teens and tens ("the gate code is 4 5 6 7, pound", "code for the gate is
 *     2468", "the code is 4567 for the gate", "Gate code # 1984", "lockbox number 1972", "Lockbox: L1984", "buzz 112");
 *   - the words of a change and a speaker's fillers between a code word and its code (NOW): "is now 5050", "changed to
 *     7731", "will be 2290", "has been reset to 4410", "is uh 4567", "is supposedly 4053", "the new one is 9577";
 *   - a kind word alone (BARE, rule 6): "lockbox is 6712", "alarm: #5871", "keypad is now #8159", "disarm is 1158";
 *     weaker words only with a connector (BARE_WEAK: "pool is 4321") or a pressed mark (BARE_MARK: "Front entrance
 *     #5160"); "GC 4821", "LB: 5521" (6b); a kind or code word, any words, then "is" or ":" (6c): "code for 2214
 *     Maple is 4170", "lockbox for residence: 4821" -- unless the words are about the thing ("Gate repair is 450",
 *     "Pool enclosure is 4500"); "changed it from 6199 to 6250" (6d); Spanish (6e); a call box's directory number
 *     (6f: "Directory 117", "call box #45"); a code with its pound key anywhere (6g: "then 2208#");
 *   - the code first (rule 7): "8034 is the gate", "two five oh one for the gate code", "4 3 9 9 -- side gate";
 *   - a verb (rule 8): "enter 4821 at the gate", "punch in 8361#", "got in with 6464", "Dial 0384" after a sentence
 *     about a call box;
 *   - an answer on its own line (rule 10): "gate?" / "7 0 2 9", "Alarm code for the Examples?" / "4 5 6 1 then off";
 *   - more codes after one, through joining words (ALT_JOIN): "4821, or 4822", "try 5810, if not 5801", "2918 ->
 *     2981", "use 7700 instead of 7070", "old was 1919, new one is 6038", "1402# then 0915"; never ";" before words,
 *     a full stop, "the", "is", "to", "from";
 *   - the weighed reading (rule 9, WEIGH / THRESHOLD) for what no rule names: each remaining number is scored on the
 *     words around it, for (gate, lockbox, code, enter, "4-digit", "to get in", "didn't work", a # after it) and against
 *     (a door or product word, price, estimate) -- "Keypad on the left, 1006", "…the gate code on the invoice? It's 6011";
 *   - the same code again in its sentence, or alone in a later line within 300 bytes ("yeah that's what she said, 0007");
 *   - never a code (not_a_code(), every rule): a door size ("patio door #3080", "French door no. 6080", "3068 LH"),
 *     a fraction ("46 1/4"), a unit or money after or before it ("1450 sq ft", "$1,240", "36x80", "220v"), a time
 *     ("meet at the gate 0930", "what time…" / "1015"), "not a code", the unit's own number, and in the weaker readings
 *     a document, unit, model, phone, card, store, year, postcode, material or street ("estimate #6523", "model 1750",
 *     "304 stainless", "1450 balance due", "ending 4417", "for 2214 Maple", "Gate 1450 Elm Grove").
 * The business adds what only its own region and catalog know: its region's code-book words (REGION_WORDS_OPT), its
 * product and model words (MODEL_WORDS_OPT), its trade's words for things (TRADE_WORDS_OPT, the catalog pack's
 * vocabulary.things, plus the zdz_sealed_trade_words filter), its postcode shape (POSTCODE_OPT) and the house-number-first
 * street words of its region (STREET_FIRST_OPT). Core ships only generic building words; without the business's lists
 * a number is cut a little more often, never less.
 * Kinds: a door read without a code word is filed as a keypad, since an app drops a "door" span whose words lack code /
 * pin / combo / key (a door size). Spans never overlap. Text that is not UTF-8 is read as Windows-1252. Every look is
 * bounded to a few hundred bytes around the number and taken spans are kept in one mask (50 KB with 5,000 codes:
 * about 120 ms; a 30 KB e-mail: about 35 ms).
 * might_have_code() is the quick check an app may run before find().
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Sealed {

	const SCHEMA     = 1;
	const SCHEMA_OPT = 'zdz_sealed_schema';

	const KINDS  = array( 'gate', 'lockbox', 'alarm', 'door', 'keypad' );
	const SCOPES = array( 'job', 'estimate', 'address', 'street' );

	/** The words a code is named by, and the kind each one is filed as. */
	const KIND_WORDS = array(
		'gate' => 'gate', 'community' => 'gate', 'pool' => 'gate', 'entry' => 'gate', 'access' => 'gate',
		'callbox' => 'gate', 'call box' => 'gate', 'buzz' => 'gate', 'buzzer' => 'gate',
		'lockbox' => 'lockbox', 'lock box' => 'lockbox', 'keybox' => 'lockbox', 'key box' => 'lockbox', 'combo' => 'lockbox',
		'alarm' => 'alarm', 'disarm' => 'alarm', 'lockbx' => 'lockbox', 'lock bx' => 'lockbox', 'lck box' => 'lockbox',
		'door' => 'door', 'garage' => 'door',
		'keypad' => 'keypad', 'key pad' => 'keypad',
		// more of what a code is called
		'padlock' => 'lockbox', 'key safe' => 'lockbox', 'keysafe' => 'lockbox', 'supra' => 'lockbox', 'combination' => 'lockbox',
		'security' => 'alarm', 'alarm panel' => 'alarm', 'intercom' => 'gate', 'gaet' => 'gate', 'passcode' => 'gate',
		'pass code' => 'gate', 'door lock' => 'keypad', 'smart lock' => 'keypad', 'gated' => 'gate', 'gated comm' => 'gate', 'gated community' => 'gate', 'gated comm.' => 'gate', 'entrance' => 'gate', 'panel' => 'alarm', 'alm' => 'alarm', 'kpad' => 'keypad', 'k pad' => 'keypad',
		'pin pad' => 'keypad', 'pinpad' => 'keypad', 'keyless' => 'keypad', 'keyless entry' => 'keypad', 'smart lock' => 'keypad', 'smartlock' => 'keypad',
		'sm lock' => 'keypad', 'keycode' => 'keypad', 'key code' => 'keypad', 'master lock' => 'lockbox', 'masterlock' => 'lockbox', 'gate lock' => 'gate', 'side lock' => 'gate', 'back lock' => 'gate',
		'front lock' => 'keypad', 'door lock' => 'keypad', 'combo lock' => 'lockbox', 'dial lock' => 'lockbox', 'pad lock' => 'lockbox',
	);

	/** Number words as a code may be said (units, teens, tens and the keys pressed). */
	const UNITS = array( 'zero' => 0, 'oh' => 0, 'o' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9 );
	/** Words that sound like digits ("for", "to"): a digit only between two other number tokens. */
	const SOUNDS = array( 'won' => 1, 'to' => 2, 'too' => 2, 'for' => 4, 'ate' => 8 );
	const TEENS = array( 'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19 );
	const TENS  = array( 'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90 );
	const MARKS = array( 'pound' => '#', 'hash' => '#', 'hashtag' => '#', 'star' => '*', 'asterisk' => '*' );


	public static function t_sealed() { global $wpdb; return $wpdb->prefix . 'zdz_sealed'; }
	public static function t_views() { global $wpdb; return $wpdb->prefix . 'zdz_sealed_views'; }

	/* ------------------------------------------------------------------ the tables (once, flagged) */

	public static function maybe_install() {
		if ( (int) get_option( self::SCHEMA_OPT, 0 ) >= self::SCHEMA ) {
			return;
		}
		global $wpdb;
		$c = $wpdb->get_charset_collate();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::t_sealed() is $wpdb->prefix plus a fixed name
		$a = $wpdb->query( 'CREATE TABLE IF NOT EXISTS `' . self::t_sealed() . "` (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`kind` varchar(10) NOT NULL DEFAULT 'gate',
			`sealed` text NOT NULL,
			`scope_type` varchar(10) NOT NULL DEFAULT '',
			`scope_id` varchar(120) NOT NULL DEFAULT '',
			`created_by` bigint(20) unsigned NOT NULL DEFAULT 0,
			`created_at` datetime NOT NULL,
			PRIMARY KEY (`id`),
			KEY `scope` (`scope_type`, `scope_id`)
		) $c" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $c is $wpdb->get_charset_collate()
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::t_views() is $wpdb->prefix plus a fixed name
		$b = $wpdb->query( 'CREATE TABLE IF NOT EXISTS `' . self::t_views() . "` (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`sealed_id` bigint(20) unsigned NOT NULL DEFAULT 0,
			`who` bigint(20) unsigned NOT NULL DEFAULT 0,
			`at` datetime NOT NULL,
			`basis` varchar(60) NOT NULL DEFAULT '',
			PRIMARY KEY (`id`),
			KEY `sealed` (`sealed_id`)
		) $c" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $c is $wpdb->get_charset_collate()
		if ( false === $a || false === $b ) {
			error_log( 'Zorderz: the sealed-code tables could not be made (' . $wpdb->last_error . '); it will try again.' ); // phpcs:ignore
			return;
		}
		update_option( self::SCHEMA_OPT, self::SCHEMA, true );
	}

	/* ------------------------------------------------------------------ sealing */

	const LOCKED_SAYS = 'Gate codes are locked: the site\'s keys changed. An administrator can unlock them with the printed recovery code (Zorderz -> Gate code key).';
	const CHECK_OPT   = 'zdz_sealed_key_check';    // HMAC( CHECK_LABEL, key ), never the key
	const CHECK_LABEL = 'zdz-sealed key check v1';
	const SAVED_OPT   = 'zdz_sealed_code_saved';   // "I've written it down": 'Y-m-d H:i:s|uid'

	/** @var array|null this request's key state: ['key' => bytes|null, 'why' => '' | no_sodium | no_salts | bad_constant | locked] */
	private static $state = null;

	/**
	 * Can codes be FOUND and CUT here? libsodium and wp_salt() exist. Apps that keep codes cut them out of text only
	 * while this is true, so it must never turn false just because the key is locked. Whether a code can be KEPT or
	 * OPENED is can_keep().
	 */
	public static function ready() {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'wp_salt' );
	}

	/**
	 * Can codes be sealed (kept) and opened right now? libsodium, a key (ZDZ_SEALED_KEY, or real salts), and that
	 * key matching the check value. While it is false codes are still cut out of the words, never kept and never opened:
	 * they fail closed. not_ready_reason() says why not.
	 */
	public static function can_keep() {
		return '' === self::state()['why'];
	}

	/** '' when codes can be kept, else no_sodium | no_salts | bad_constant | locked. */
	public static function not_ready_reason() {
		return self::state()['why'];
	}

	/** Are the codes locked (a key that is not the one the codes were sealed with)? */
	public static function locked() {
		return 'locked' === self::state()['why'];
	}

	/**
	 * Real salts: AUTH_KEY, AUTH_SALT, SECURE_AUTH_KEY and SECURE_AUTH_SALT defined in wp-config.php, 32 characters or
	 * more, and not the placeholder.
	 */
	public static function salts_real() {
		$placeholders = array( 'put your unique phrase here' );
		if ( function_exists( '__' ) ) {
			$placeholders[] = (string) __( 'put your unique phrase here' ); // a translated wp-config.php sample
		}
		foreach ( array( 'AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_KEY', 'SECURE_AUTH_SALT' ) as $c ) {
			if ( ! defined( $c ) || strlen( (string) constant( $c ) ) < 32 || in_array( (string) constant( $c ), $placeholders, true ) ) {
				return false;
			}
		}
		// wp_salt() treats a value repeated across the eight constants as missing and falls back to the database: so do we
		$seen = array();
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $c ) {
			if ( ! defined( $c ) ) {
				continue;
			}
			$v = (string) constant( $c );
			if ( isset( $seen[ $v ] ) ) {
				return false;
			}
			$seen[ $v ] = true;
		}
		return true;
	}

	/**
	 * may $uid manage the codes' key (see the recovery code, accept today's key)? The admin
	 * tier only, never the shared account or Demo Mode.
	 */
	public static function may_manage_key( $uid ) {
		$uid = (int) $uid;
		return $uid > 0 && ! self::is_kiosk( $uid ) && self::is_admin( $uid );
	}

	/**
	 * the way out of a lock when neither the saved salt lines nor ZDZ_SEALED_KEY can be put
	 * back. Records the check value under TODAY's key, so new codes are kept again. It changes no key and never runs on
	 * its own: an administrator presses it and types ACCEPT. Codes sealed before stay unreadable (open() says so) unless
	 * the saved salt lines or ZDZ_SEALED_KEY are put back -- which then locks again until accepted again. True, or false
	 * when there is no key to accept (no salts, a bad ZDZ_SEALED_KEY), when it isn't locked, or $typed is not ACCEPT.
	 */
	public static function accept_current_key( $uid, $typed ) {
		if ( 'ACCEPT' !== trim( (string) $typed ) || ! self::may_manage_key( $uid ) || ! self::locked() ) {
			return false;
		}
		$key = self::constant_key();
		if ( false === $key ) {
			return false;
		}
		if ( null === $key ) {
			$key = self::derived_key();
		}
		if ( null === $key ) {
			return false;
		}
		update_option( self::CHECK_OPT, self::check_of( $key ), false );
		sodium_memzero( $key );
		self::reset_state();
		return self::can_keep();
	}

	/** Forget this request's key state (after the check value is recorded, and for the suites). */
	public static function reset_state() {
		if ( is_array( self::$state ) && is_string( self::$state['key'] ?? null ) && function_exists( 'sodium_memzero' ) ) {
			$k = self::$state['key'];
			sodium_memzero( $k );
		}
		self::$state = null;
		self::$lists = null;
	}

	/** The key from ZDZ_SEALED_KEY (the printed recovery code), or null when it isn't defined. False when it is but is not a key. */
	private static function constant_key() {
		if ( ! defined( 'ZDZ_SEALED_KEY' ) ) {
			return null;
		}
		$raw = self::b32_decode( (string) constant( 'ZDZ_SEALED_KEY' ) );
		return 32 === strlen( $raw ) ? $raw : false;
	}

	/** The key derived from the SECURE_AUTH salt. Null without real salts. */
	private static function derived_key() {
		if ( ! function_exists( 'wp_salt' ) || ! self::salts_real() ) {
			return null;
		}
		return sodium_crypto_generichash( 'zdz-sealed-v1|codes|' . wp_salt( 'secure_auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	private static function check_of( $key ) {
		return hash_hmac( 'sha256', self::CHECK_LABEL, $key );
	}

	private static function state() {
		if ( null !== self::$state ) {
			return self::$state;
		}
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return self::$state = array( 'key' => null, 'why' => 'no_sodium' );
		}
		$key = self::constant_key();
		if ( false === $key ) {
			return self::$state = array( 'key' => null, 'why' => 'bad_constant' );
		}
		if ( null === $key ) {
			$key = self::derived_key();
		}
		if ( null === $key ) {
			return self::$state = array( 'key' => null, 'why' => 'no_salts' );
		}
		$have = function_exists( 'get_option' ) ? (string) get_option( self::CHECK_OPT, '' ) : '';
		if ( '' !== $have ) {
			if ( ! hash_equals( $have, self::check_of( $key ) ) ) {
				sodium_memzero( $key );
				return self::$state = array( 'key' => null, 'why' => 'locked' );
			}
			return self::$state = array( 'key' => $key, 'why' => '' );
		}
		// First use: no check is stored yet. Codes may already be in the vault (copied in, or sealed before the check
		// was kept): the newest one must open with this key, or the key is not theirs and nothing is recorded.
		$newest = self::newest_sealed();
		if ( null !== $newest && null === self::unbox_with( $newest, $key ) ) {
			sodium_memzero( $key );
			return self::$state = array( 'key' => null, 'why' => 'locked' );
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( self::CHECK_OPT, self::check_of( $key ), false );
		}
		return self::$state = array( 'key' => $key, 'why' => '' );
	}

	/** The newest sealed text, or null when there is none (or the table can't be read). */
	private static function newest_sealed() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return null;
		}
		$id = (int) $wpdb->get_var( 'SELECT MAX(id) FROM `' . self::t_sealed() . '`' ); // phpcs:ignore
		if ( $id <= 0 ) {
			return null;
		}
		$row = self::row( $id );
		return $row ? (string) $row['sealed'] : null;
	}

	private static function key() {
		$k = self::state()['key'];
		return is_string( $k ) ? $k : null;
	}

	/**
	 * today's key as the recovery code (RFC 4648 base32, no padding, groups of four: 13 groups), or '' when the
	 * codes are not ready. Administrators only: the caller checks, and every showing is logged by the caller.
	 */
	public static function recovery_code() {
		$k = self::key();
		return null === $k ? '' : trim( chunk_split( self::b32( $k ), 4, ' ' ) );
	}

	/** Does $code (as printed, spaces and look-alikes allowed) open today's codes? Never stores anything. */
	public static function code_matches( $code ) {
		$raw = self::b32_decode( (string) $code );
		if ( 32 !== strlen( $raw ) ) {
			return false;
		}
		$have = function_exists( 'get_option' ) ? (string) get_option( self::CHECK_OPT, '' ) : '';
		return '' !== $have && hash_equals( $have, self::check_of( $raw ) );
	}

	public static function code_saved() {
		return function_exists( 'get_option' ) && '' !== (string) get_option( self::SAVED_OPT, '' );
	}

	public static function mark_code_saved( $uid ) {
		update_option( self::SAVED_OPT, gmdate( 'Y-m-d H:i:s' ) . '|' . (int) $uid, false );
	}

	private static function b32( $bin ) {
		$alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$bits  = '';
		foreach ( str_split( $bin ) as $c ) {
			$bits .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 5 ) as $grp ) {
			$out .= $alpha[ bindec( str_pad( $grp, 5, '0', STR_PAD_RIGHT ) ) ];
		}
		return $out;
	}

	/** A printed code read back: look-alikes first (a printed 0 read as O, 1 as I, 8 as B), then non-alphabet goes. */
	private static function b32_decode( $s ) {
		$alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$s     = strtr( strtoupper( (string) $s ), array( '0' => 'O', '1' => 'I', '8' => 'B' ) );
		$s     = preg_replace( '/[^A-Z2-7]/', '', $s );
		$bits  = '';
		foreach ( str_split( $s ) as $c ) {
			$i = strpos( $alpha, $c );
			if ( false === $i ) {
				return '';
			}
			$bits .= str_pad( decbin( $i ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			if ( 8 === strlen( $byte ) ) {
				$out .= chr( bindec( $byte ) );
			}
		}
		return $out;
	}

	private static function box( $plain ) {
		$key = self::key();
		if ( null === $key ) {
			return null;
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( (string) $plain, $nonce, $key ) );
	}

	private static function unbox( $sealed ) {
		if ( ! self::can_keep() ) {
			return null;
		}
		return self::unbox_with( $sealed, self::key() );
	}

	private static function unbox_with( $sealed, $key ) {
		if ( ! is_string( $key ) || 0 !== strpos( (string) $sealed, 'v1:' ) ) {
			return null;
		}
		$raw = base64_decode( substr( (string) $sealed, 3 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
		return false === $plain ? null : $plain;
	}

	/** ['job', 12] / ['type' => 'job', 'id' => 12] / 'job:12' -> ['job', '12'], or null when it is not a scope. */
	public static function scope( $scope ) {
		if ( is_string( $scope ) && false !== strpos( $scope, ':' ) ) {
			$scope = explode( ':', $scope, 2 );
		}
		if ( ! is_array( $scope ) ) {
			return null;
		}
		$t  = strtolower( (string) ( $scope['type'] ?? $scope[0] ?? '' ) );
		$id = trim( (string) ( $scope['id'] ?? $scope[1] ?? '' ) );
		if ( ! in_array( $t, self::SCOPES, true ) || '' === $id || strlen( $id ) > 120 ) {
			return null;
		}
		return array( $t, $id );
	}

	/** A kind word ("pool", "lock box", "garage") -> one of KINDS; anything else -> 'gate'. */
	public static function kind( $word ) {
		$w = strtolower( trim( preg_replace( '/[\s\-]+/', ' ', (string) $word ) ) );
		if ( in_array( $w, self::KINDS, true ) ) {
			return $w;
		}
		return self::KIND_WORDS[ $w ] ?? ( self::KIND_WORDS[ str_replace( ' ', '', $w ) ] ?? 'gate' );
	}

	public static function label( $kind ) {
		return '[' . self::kind( $kind ) . ' code sealed]';
	}

	/**
	 * One code into the vault. $code is what find() matched or a code a person typed in a code box; it is normalised
	 * the same way (digits, # and *, an optional leading letter). The id, or 0 when nothing could be kept (no libsodium,
	 * not a code, a bad scope, a database error): the caller still writes only the label, never the code.
	 */
	public static function seal( $kind, $code, $scope ) {
		$sc   = self::scope( $scope );
		$norm = self::normalize( (string) $code );
		if ( ! self::can_keep() || null === $sc || null === $norm ) {
			return 0;
		}
		self::maybe_install();
		$boxed = self::box( $norm );
		if ( null === $boxed ) {
			return 0;
		}
		global $wpdb;
		$ok = $wpdb->insert( self::t_sealed(), array(
			'kind'       => self::kind( $kind ),
			'sealed'     => $boxed,
			'scope_type' => $sc[0],
			'scope_id'   => $sc[1],
			'created_by' => (int) get_current_user_id(),
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	private static function row( $id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . self::t_sealed() . '` WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::t_sealed() is $wpdb->prefix plus a fixed name
		return is_array( $r ) ? $r : null;
	}

	/* ------------------------------------------------------------------ who may open one */

	private static function is_kiosk( $uid ) {
		if ( class_exists( 'ZDZ_Kiosk_Demo' ) && method_exists( 'ZDZ_Kiosk_Demo', 'is_active' ) && ZDZ_Kiosk_Demo::is_active() ) {
			return true; // Demo Mode: the device runs as the shared account, whoever started it
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( (int) $uid ) ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) && 'kiosk' === ZDZ_Capabilities::tier_of( (int) $uid ) ) {
			return true;
		}
		$u = get_userdata( (int) $uid );
		return $u ? in_array( 'zdz_general', (array) $u->roles, true ) : false;   // the shared role, whatever else says
	}

	private static function is_admin( $uid ) {
		if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) ) {
			return 'admin' === ZDZ_Capabilities::tier_of( (int) $uid );
		}
		$u = get_userdata( (int) $uid );
		return $u ? ( user_can( (int) $uid, 'manage_options' ) || (bool) array_intersect( (array) $u->roles, array( 'administrator', 'zdz_owner', 'zdz_admin' ) ) ) : false;
	}

	/** [why, basis]: why '' = allowed, with the basis written to the log. */
	private static function decide( $row, $uid ) {
		$uid = (int) $uid;
		if ( null === $row ) {
			return array( 'no_such', '' );
		}
		if ( $uid <= 0 ) {
			return array( 'signed_out', '' );
		}
		if ( self::is_kiosk( $uid ) ) {
			return array( 'kiosk', '' ); // never, whatever an app says
		}
		if ( ! self::can_keep() ) {
			// locked (the site's keys changed; nothing opens until ZDZ_SEALED_KEY is set), no_salts, bad_constant, no_sodium
			return array( self::not_ready_reason(), '' );
		}
		if ( self::is_admin( $uid ) ) {
			return array( '', 'admin' );
		}
		$public = $row;
		unset( $public['sealed'] );
		$ok = apply_filters( 'zdz_sealed_may_open', false, $public, $uid );
		if ( true === $ok || ( is_string( $ok ) && '' !== trim( $ok ) ) ) {
			return array( '', is_string( $ok ) ? substr( sanitize_text_field( $ok ), 0, 50 ) : 'app' );
		}
		return array( 'not_allowed', '' );
	}

	/** '' when $uid may open code $id now, else why not (no_such, signed_out, kiosk, locked, no_salts, bad_constant, no_sodium, not_allowed). */
	public static function why_not( $id, $uid ) {
		return self::decide( self::row( $id ), $uid )[0];
	}

	/**
	 * The code, for an allowed person; null otherwise (refused, no such code, or the site's salts changed). Every
	 * attempt is logged: an opening with its basis ("admin", or what the owning app said), a refusal as "refused: why".
	 */
	public static function open( $id, $uid ) {
		$row = self::row( $id );
		list( $why, $basis ) = self::decide( $row, $uid );
		if ( null === $row ) {
			return null;
		}
		if ( '' !== $why ) {
			self::note_refusal( (int) $id, (int) $uid, $why );
			return null;
		}
		$code = self::unbox( $row['sealed'] );
		// every opening is written down first; a code that cannot be recorded is not shown (fail closed)
		if ( ! self::note( (int) $id, (int) $uid, null === $code ? 'unreadable (salts changed?)' : $basis ) ) {
			error_log( 'Zorderz: a sealed code was not shown because its opening could not be recorded.' ); // phpcs:ignore
			return null;
		}
		return $code;
	}

	/** What a screen shows beside "Show code": never the code, never its length to anyone but an admin. */
	public static function info( $id, $uid ) {
		$row = self::row( $id );
		if ( null === $row ) {
			return null;
		}
		return array(
			'id'         => (int) $row['id'],
			'kind'       => (string) $row['kind'],
			'label'      => self::label( $row['kind'] ),
			'scope_type' => (string) $row['scope_type'],
			'scope_id'   => (string) $row['scope_id'],
			'added'      => substr( (string) $row['created_at'], 0, 10 ),
			'may_open'   => '' === self::decide( $row, $uid )[0],
		);
	}

	private static function note( $id, $uid, $basis ) {
		global $wpdb;
		self::maybe_install();
		return (bool) $wpdb->insert( self::t_views(), array( 'sealed_id' => (int) $id, 'who' => (int) $uid, 'at' => gmdate( 'Y-m-d H:i:s' ), 'basis' => substr( (string) $basis, 0, 60 ) ) );
	}

	/** A refusal, written once per person, code and reason every 10 minutes (a loop of refused calls cannot fill the log). */
	private static function note_refusal( $id, $uid, $why ) {
		$k = 'zdz_sealed_ref_' . md5( $uid . '|' . $id . '|' . $why );
		if ( function_exists( 'get_transient' ) && get_transient( $k ) ) {
			return true;
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $k, 1, 600 );
		}
		return self::note( $id, $uid, 'refused: ' . $why );
	}

	/** Openings and refusals, newest first: [sealed_id, who, at, basis]. For admin screens; never a code. */
	public static function log( $id = 0, $limit = 100 ) {
		global $wpdb;
		$limit = max( 1, min( 1000, (int) $limit ) );
		$sql   = (int) $id > 0
			? $wpdb->prepare( 'SELECT sealed_id, who, at, basis FROM `' . self::t_views() . '` WHERE sealed_id = %d ORDER BY id DESC LIMIT %d', (int) $id, $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::t_views() is $wpdb->prefix plus a fixed name
			: $wpdb->prepare( 'SELECT sealed_id, who, at, basis FROM `' . self::t_views() . '` ORDER BY id DESC LIMIT %d', $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::t_views() is $wpdb->prefix plus a fixed name
		return (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
	}

	/* ------------------------------------------------------------------ finding codes in words */

	/** Look-alike characters read as their plain form before scanning (text pasted from a phone, a billing system or a PDF). */
	const LOOKALIKE = array(
		"\u{00A0}" => ' ', "\u{1680}" => ' ', "\u{2000}" => ' ', "\u{2001}" => ' ', "\u{2002}" => ' ', "\u{2003}" => ' ',
		"\u{2004}" => ' ', "\u{2005}" => ' ', "\u{2006}" => ' ', "\u{2007}" => ' ', "\u{2008}" => ' ', "\u{2009}" => ' ',
		"\u{200A}" => ' ', "\u{202F}" => ' ', "\u{205F}" => ' ', "\u{3000}" => ' ',
		"\u{200B}" => '', "\u{200C}" => '', "\u{200D}" => '', "\u{2060}" => '', "\u{FEFF}" => '', "\u{00AD}" => '',
		"\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-', "\u{2014}" => '-', "\u{2015}" => '-', "\u{2212}" => '-',
		"\u{2018}" => "'", "\u{2019}" => "'", "\u{201B}" => "'", "\u{201C}" => '"', "\u{201D}" => '"',
		"\u{FF03}" => '#', "\u{FF0A}" => '*', "\u{FF1A}" => ':', "\u{FE55}" => ':',
		"\u{FF10}" => '0', "\u{FF11}" => '1', "\u{FF12}" => '2', "\u{FF13}" => '3', "\u{FF14}" => '4',
		"\u{FF15}" => '5', "\u{FF16}" => '6', "\u{FF17}" => '7', "\u{FF18}" => '8', "\u{FF19}" => '9',
	);

	/**
	 * Words that make "code" something other than an access code ("zip code 12345", "building code 2022"). The code
	 * books of the business's own region are added by not_access_re() (option REGION_WORDS_OPT, filter zdz_sealed_region_words).
	 */
	const NOT_ACCESS = '(?:barrier|pool\s+barrier|stain|paint|finish|zip|postal|post|area|country|promo|promotion|promotional|discount|coupon|voucher|referral|invite|invitation|tax|error|status|product|item|sku|part|model|source|qr|bar|dress|color|colour|reference|ref|confirmation|verification|tracking|order|booking|building|fire|electrical|electric|plumbing|mechanical|energy|zoning|municipal|city|county|state|ibc|irc|ifc|nec|ul|ansi|uniform|penal|vehicle|civil|health|safety|labor|labour|morse|cheat|class|auth|authorization|pickup|pick-up|redemption|gift|card|billing|account|item|style|product|tax|ssn)';

	/* ------------------------------------------------------------------ what the business's region and catalog add */

	/** Option: words that name the region's own code books or the region itself ("state building code 2022" style). */
	const REGION_WORDS_OPT = 'zdz_sealed_region_words';

	/** Option: the business's own product or model words, a number after which is a model, not a code. */
	const MODEL_WORDS_OPT = 'zdz_sealed_model_words';

	/**
	 * Option: the trade's words for things ("awning", "skylight"), shared with the ask bar (ZDZ_Ask_Phrases::TRADE_OPT,
	 * written from the catalog pack's vocabulary.things), with the zdz_sealed_trade_words filter on top. A number beside
	 * one of them is a size, a count or a price, as it is beside "door" or "window". Core: none.
	 */
	const TRADE_WORDS_OPT = 'zdz_ask_trade_words';

	/**
	 * Option: street words that come BEFORE the street's name in the business's region ("Rue", "Strada"), on top of
	 * STREET_FIRST, with the zdz_street_first_words filter. Shared with ZDZ_Ask_Context::redact(). Written from the
	 * knowledge pack's addresses.street_first_words.
	 */
	const STREET_FIRST_OPT = 'zdz_street_first_words';

	/** Street words that come before the name and are common across the United States ("1450 Via de la Valle"). */
	const STREET_FIRST = array( 'via', 'vía', 'calle', 'camino', 'paseo', 'avenida', 'plaza' );

	/**
	 * Option: the region's postcode, which a weak reading never takes for a code:
	 *   [ 'digits' => a pattern the whole number matches, 'region' => the words that may stand just before it ].
	 * The digits pattern is always applied to the whole number (anchored here, ^(?:…)$), however it was written, so a
	 * pattern such as \d{5} never lets off a longer number that merely contains five digits.
	 * Core ships none, so no number is let off as a postcode: a postcode cut as a code only hides a postcode, a code
	 * let off as a postcode would show the code.
	 */
	const POSTCODE_OPT = 'zdz_sealed_postcode';

	/** @var array|null this request's lists: ['region' => '|a|b' or '', 'model' => pattern or '', 'postcode' => rule or null] */
	private static $lists = null;

	/** A list of words from an option and a filter, each made safe to stand in a pattern ('|' joins them). */
	private static function word_list( $opt, $filter ) {
		$raw = function_exists( 'get_option' ) ? get_option( $opt, array() ) : array();
		$raw = function_exists( 'apply_filters' ) ? apply_filters( $filter, is_array( $raw ) ? $raw : array() ) : $raw;
		$out = array();
		foreach ( self::clean_words( $raw ) as $w ) {
			$out[] = str_replace( ' ', '\s+', preg_quote( $w, '/' ) );
		}
		return $out;
	}

	/**
	 * The words of a region or catalog list that the reader accepts (option, filter or pack value): lower case,
	 * a letter or digit first, then letters, digits, spaces, dots and hyphens, at most 41 characters. Pure.
	 *
	 * @return string[]
	 */
	public static function clean_words( $raw ) {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $w ) {
			$w = strtolower( trim( is_string( $w ) ? $w : '' ) );
			if ( preg_match( '/^[a-z0-9][a-z0-9 .\-]{0,40}$/', $w ) ) {
				$out[ $w ] = true;
			}
		}
		return array_map( 'strval', array_keys( $out ) ); // a word of digits comes back from array_keys as an integer
	}

	/** A postcode pattern that must match the whole number: ^(?:pattern)$, for use with the D modifier. Pure. */
	public static function whole( $p ) {
		return '' === (string) $p ? '' : '^(?:' . $p . ')$';
	}

	/** A pattern from an option, or '' when it is missing or would not compile. Never with the delimiter in it. */
	public static function safe_pattern( $p ) {
		$p = is_string( $p ) ? trim( $p ) : '';
		if ( '' === $p || strlen( $p ) > 120 || false !== strpos( $p, '/' ) ) {
			return '';
		}
		return false === @preg_match( '/' . $p . '/', '' ) ? '' : $p; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a bad pattern is refused, never thrown
	}

	private static function lists() {
		if ( is_array( self::$lists ) ) {
			return self::$lists;
		}
		$region = self::word_list( self::REGION_WORDS_OPT, 'zdz_sealed_region_words' );
		$model  = self::word_list( self::MODEL_WORDS_OPT, 'zdz_sealed_model_words' );
		$trade  = array();
		foreach ( self::word_list( self::TRADE_WORDS_OPT, 'zdz_sealed_trade_words' ) as $w ) {
			$trade[] = preg_match( '/s$/', $w ) ? $w : $w . '(?:s|es)?';
		}
		$first = array();
		foreach ( self::street_first_words() as $w ) { // capitalised, as a street's name is written ("Via", not "via email")
			$first[] = str_replace( ' ', '\s+', preg_quote( mb_convert_case( $w, MB_CASE_TITLE, 'UTF-8' ), '/' ) );
		}
		$pc     = function_exists( 'get_option' ) ? get_option( self::POSTCODE_OPT, array() ) : array();
		$pc     = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_sealed_postcode', is_array( $pc ) ? $pc : array() ) : $pc;
		$rule   = null;
		if ( is_array( $pc ) && '' !== self::safe_pattern( $pc['digits'] ?? '' ) ) {
			$rule = array( 'digits' => self::whole( self::safe_pattern( $pc['digits'] ) ), 'region' => self::safe_pattern( $pc['region'] ?? '' ) );
		}
		return self::$lists = array(
			'region'   => $region ? '|' . implode( '|', $region ) : '',
			'model'    => $model ? '/\b(?:' . implode( '|', $model ) . ')\b\.?\s*(?:#|no\.?|number)?\s*:?\s*$/i' : '',
			'trade'    => $trade ? '|' . implode( '|', $trade ) : '',
			'first'    => implode( '|', $first ),
			'postcode' => $rule,
		);
	}

	/**
	 * Street words that come before the name: STREET_FIRST, the option STREET_FIRST_OPT and the zdz_street_first_words
	 * filter, each a lower-case word or two (letters, accented letters, spaces). Pure apart from the option and filter.
	 *
	 * @return string[]
	 */
	public static function street_first_words() {
		$extra = function_exists( 'get_option' ) ? get_option( self::STREET_FIRST_OPT, array() ) : array();
		$list  = array_merge( self::STREET_FIRST, is_array( $extra ) ? $extra : array() );
		$list  = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_street_first_words', $list ) : $list;
		$out   = array();
		foreach ( is_array( $list ) ? $list : array() as $w ) {
			$w = is_string( $w ) ? mb_strtolower( trim( preg_replace( '/\s+/u', ' ', $w ) ), 'UTF-8' ) : '';
			if ( preg_match( '/^\p{L}[\p{L} ]{0,23}$/u', $w ) ) {
				$out[ $w ] = true;
			}
		}
		return array_map( 'strval', array_keys( $out ) );
	}

	/** '' or "|word|word": the trade's words for things (each with its plural), joined onto an alternation. */
	private static function trade_alt() {
		return self::lists()['trade'];
	}

	/** Does one of the trade's words follow a number ("2 awnings", "1450 skylight")? Never with no trade words. */
	private static function trade_after( $after ) {
		$t = self::trade_alt();
		return '' !== $t && (bool) preg_match( '/^\s*-?\s*(?:' . substr( $t, 1 ) . ')\b/i', (string) $after );
	}

	/** Read the region and catalog lists again (after their options change; the suites). */
	public static function flush_lists() {
		self::$lists = null;
	}

	/** '' or "|word|word": the region's code-book words, joined onto an alternation. */
	private static function region_alt() {
		return self::lists()['region'];
	}

	/** NOT_ACCESS with the region's own words. */
	private static function not_access_re() {
		$r = self::region_alt();
		return '' === $r ? self::NOT_ACCESS : '(?:' . self::NOT_ACCESS . $r . ')';
	}

	/** The connectors between a code word and the code: "is", "was", ":", "#", "number", "reads", "'s", a full stop. */
	const IS = '[\s,:;#=\-.()">]*(?:(?:is|was|are|it\'s|its|\'s|of|number(?:\s+sign)?|no\.|reads|' . self::NOW . ')(?![a-z])[\s,:;#=\-.()">]*)*';

	/**
	 * The words of a change, and a speaker's fillers, between a code word and its code: "gate code is now
	 * 5050", "changed to 7731", "will be 2290", "has been reset to 4410", "is uh 4567", "is still 0260", "currently 1234".
	 */
	const NOW = 'now|still|currently|uh+|um+|er|ah|like|actually|(?:got\s+|was\s+|been\s+|has\s+been\s+|have\s+been\s+)?(?:changed|updated|reset|set|switched|moved)(?:\s+(?:it\s+)?to)?|(?:will|should|would|must|might|may)(?:\s+now)?\s+be|is\s+supposed\s+to\s+be|became|becomes|goes\s+to|(?:the\s+)?new\s+one(?:\s+is)?|(?:changed|updated|switched|moved)\s+from|supposedly|apparently|probably|basically|just|always|usually|definitely|hopefully|i\s+think|i\s+believe|i\s+guess|they\s+(?:said|say|told\s+(?:me|us))';

	/** "code" or "codes", also written into the next word ("codeis4821"). */
	const CODE = 'codes?(?=(?:is|was)(?![a-z])|[^a-z]|$)';

	/** Code words other than "code" ("lockbox combo 5521", "door pin 4455", "pool passcode 1357"). */
	const CODE_WORD = '(?:codes?|combo|combination|pin|pin\s*#|passcode|pass\s*code|password)(?![a-z])';

	/**
	 * A kind word alone may name its code ("lockbox is 6712", "alarm: #5871", "keypad is now #8159",
	 * "gate eight zero three six pound"). A door alone never does: "door #3080" and "French door no. 6080" are sizes.
	 */
	const BARE = '(gated\s+comm(?:unity|\.)?|(?:gate|door|side|back|front|combo|dial|pad)\s+lock|combo\s+lock|smart\s*-?\s*lock|sm\s+lock|keyless|pin\s*-?\s*pad|key\s*-?\s*code|master\s*lock|k\s*-?\s*pad|gate|lock\s*-?\s*bx|lck\s*box|disarm|lock\s*-?\s*box|key\s*-?\s*box|key\s*-?\s*safe|keysafe|key\s*-?\s*pad|combo|padlock|buzz(?:er)?|call\s*-?\s*box|intercom|alarm|supra|passcode|pass\s*code|pin|gaet)';

	/** Weaker kind words: a code only with a connector ("pool is 4321", "garage: 2580", "security code"), never "pool 1500". */
	const BARE_WEAK = '(pool|garage|community|access|gated|alm)';

	/** Words a code may be named by only with a pressed mark ("Front entrance #5160", "entry 7720#"): "Entry: 3068" is a size. */
	const BARE_MARK = '(entry|entrance|security|panel|keyless\s+entry)';

	/** What a number is when it is not a code: units, money, times right after it. */
	const UNIT_AFTER = '/^(?:\s*(?:x|×|by)\s*\d|\s*["\x{201D}\x{2033}](?![A-Za-z])|\s*\'(?![sd]\b)|\.\d|,\d{3}\b|\s*-?\s*(?:ft|feet|foot|in|inch|inches|mm|cm|sq|sqft|sf|lbs?|gal|gallons|mi|miles|yrs?|years?|am|pm|a\.m|p\.m|hrs?|hours?|mins?|minutes|dollars|bucks|each|ea|total|wide|tall|high|long|deep|watts?|volts?|psi|rpm|sq\.?\s*ft|car|cars|units|pcs|pieces|panels|doors|windows)\b)/iu';

	/**
	 * What a number is when the words just before it say so: money, a document, a unit, a product, a year, a phone.
	 * (A weak reading only: "Gate code 4821" stays a code whatever comes before it.)
	 */
	const NOT_BEFORE = '/(?:\$\s*|\b(?:estimate|est|invoice|inv|po|p\.o|order|job|lead|quote|quoted|ticket|acct|account|claim|permit|ref|case|unit|apt|apartment|suite|ste|space|lot|bldg|building|model|series|part|sku|item|liftmaster|genie|chamberlain|vista|honeywell|kwikset|schlage|qty|quantity|call|text|phone|cell|tel|ph|fax|mobile|ext|extension|room|rm|st|ave|rd|hwy|highway|route|exit|mile|zip|add|added|charge|charged|labor|labour|price|cost|total|deposit|paid|pay|balance|bid|fee|plus|extra|credit|refund|approved|budget|house|address|delivery|meet|met|store|class|auth|authorization|ending|card|visa|mc|amex|pass|tracking|confirmation|conf|policy|member)\b\.?\s*(?:#|no\.?|number)?\s*:?\s*)$/i';

	/** In the weaker readings, what may follow a number that is not a code: materials, series, money due, a start. */
	const UNIT_AFTER_WEAK = '/^(?:\s*-?\s*(?:stainless|series|grit|kit|screws?|bolts?|gauge|ga|alloy|aluminum|aluminium|steel|brass|nylon|vinyl|balance|due|paid|deposit|total|labor|labour|install|installed|installation|start|starts|delivery|job|jobs|season|sq|model|watt|amps?|lbs?)\b|\s+as\s+(?:the\s+)?(?:\w+\s+)?(?:width|height|size|length|qty|quantity|price|total)\b|\s*-\s*\d{3,4}\b)/i';

	private static function kind_re() {
		$w = array_keys( self::KIND_WORDS );
		usort( $w, function ( $a, $b ) { return strlen( $b ) <=> strlen( $a ); } );
		return '(' . implode( '|', array_map( function ( $x ) { return str_replace( ' ', '\s*-?\s*', preg_quote( $x, '/' ) ); }, $w ) ) . ')';
	}

	/**
	 * $text with look-alike characters read plainly, and a map from each byte of the result back to its byte in $text:
	 * [plain, map]. Pure ASCII comes back as it is (map null).
	 */
	private static function plain( $text ) {
		if ( ! preg_match( '/[\x80-\xff]/', $text ) ) {
			return array( $text, null );
		}
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars ) {
			// not UTF-8 -- most often Windows-1252 pasted from an e-mail or a CSV. Each byte from 0x80 up is read as one
			// plain byte (quotes, dashes, a space), so the offsets stay those of the text as given
			$w = array( "\x91" => "'", "\x92" => "'", "\x93" => '"', "\x94" => '"', "\x96" => '-', "\x97" => '-', "\xA0" => ' ' );
			return array( preg_replace_callback( '/[\x80-\xff]/', function ( $c ) use ( $w ) { return $w[ $c[0] ] ?? ' '; }, $text ), null );
		}
		$out = '';
		$map = array();
		$at  = 0;
		foreach ( $chars as $ch ) {
			$rep = self::LOOKALIKE[ $ch ] ?? $ch;
			for ( $i = 0, $n = strlen( $rep ); $i < $n; $i++ ) {
				$map[] = $at;
			}
			$out .= $rep;
			$at  += strlen( $ch );
		}
		$map[] = $at;
		return array( $out, $map );
	}

	private static function is_spoken( $w ) {
		$w = strtolower( (string) $w );
		return isset( self::UNITS[ $w ] ) || isset( self::TEENS[ $w ] ) || isset( self::TENS[ $w ] );
	}

	/**
	 * Read a code from byte $at of $text: [code, end offset] or null. Tokens: digits with # and * (the first may start
	 * with one letter, "L1984"), number words, teens and tens, pound / star. A written code of 3+ digits stands alone
	 * (only # / * / pound / star may follow it, or a group joined by a bare hyphen: "555-0142"); short groups and spoken
	 * words join ("4 5 6 7", "4.5.6.7", "twelve thirty four"). A sound-alike ("for", "to") is a digit only next to
	 * spoken numbers ("for five six seven"), never between written digits ("8 to 5"). Never across a sentence (". ").
	 */
	private static function read_code( $text, $at, $digits_only = false ) {
		// numbers, words and a full stop that ends a sentence; any other mark stays in the gaps between them
		// only the next 240 bytes can hold a code (16 characters, spoken: a few words each), so only they are read --
		// the whole rest of a 30 KB e-mail was read again for every number
		if ( ! preg_match_all( '/[A-Za-z]?[0-9#*][0-9#*]*|[A-Za-z\']+|\.(?=\s|$)/', substr( (string) $text, 0, (int) $at + 240 ), $m, PREG_OFFSET_CAPTURE, (int) $at ) ) {
			return null;
		}
		$toks  = $m[0];
		$out   = '';
		$end   = $at;
		$long  = false;
		$short = false; // the last token was a written group of one or two digits
		$lastd = 0;     // how many digits the last written group had
		$lead  = false; // a key said before the digits ("star, three, three, zero, one")
		$sep   = null;  // the separator between digits written one at a time
		$n     = count( $toks );
		for ( $i = 0; $i < $n; $i++ ) {
			list( $raw, $off ) = $toks[ $i ];
			$w   = strtolower( $raw );
			if ( '.' === $raw && $short && preg_match( '/^\d$/', $toks[ $i + 1 ][0] ?? '' ) && 1 === $lastd ) {
				continue; // "2. 2. 9. 0." -- one dictated digit at a time, not sentences
			}
			$gap = substr( (string) $text, $end, $off - $end );
			if ( '' === $out ) {
				if ( ! preg_match( $lead ? '/^[\s,#:=\-.()"\'>]*$/' : '/^[\s#:=\-.()"\'>]*$/', $gap ) ) { // "star, three, three" 
					break;
				}
			} elseif ( ! preg_match( '/^[\s,\-]*$/', $gap ) && ! ( preg_match( '/^\.\s?$/', $gap ) && $short && 1 === strlen( $w ) ) ) {
				break;
			}
			if ( preg_match( '/^[0-9#*]+$/', $w ) || ( '' === $out && preg_match( '/^[a-z][0-9]{2,}[0-9#*]*$/', $w ) ) ) {
				$d      = strlen( preg_replace( '/[^0-9]/', '', $w ) );
				$joined = '' !== $out && ( '-' === $gap || ( ',' === $gap && 3 === $d && strlen( preg_replace( '/[^0-9]/', '', $out ) ) <= 3 && ! $long ) ); // "555-0142", "12-34", "4,821": one written code
				if ( '.' === trim( $gap ) && $d > 1 ) {
					break;
				}
				if ( $long && $d > 0 && ! $joined ) {
					break; // "gate code 4821 2 hours": the code was 4821
				}
				if ( $d >= 3 && '' !== preg_replace( '/[^0-9]/', '', $out ) && ! $joined ) {
					break; // a second written code is another thing (alternates() reads it)
				}
				// digits one at a time keep one separator; a different one ends the code ("code 5 7 1 9,
				// 3rd house", "alarm code 4 5 4 5 - 2 doors": 5719 and 4545, not 57193 and 45452)
				$g = preg_replace( '/\s+/', ' ', $gap );
				if ( '' !== $out && $d <= 2 && null !== $sep && $g !== $sep && strlen( preg_replace( '/[^0-9]/', '', $out ) ) >= 3 ) {
					break;
				}
				if ( '' !== $out && null === $sep && $d <= 2 && $short ) {
					$sep = $g;
				}
				$out  .= ctype_alpha( $w[0] ) ? strtoupper( $w ) : $w;
				$long  = $long || $d >= 3;
				$short = $d > 0 && $d <= 2;
				$lastd = $d;
				$end   = $off + strlen( $raw );
				continue;
			}
			$short = false;
			if ( '' === $out && ( isset( self::MARKS[ $w ] ) || ( 'sign' === $w && 'number' === strtolower( $toks[ $i - 1 ][0] ?? '' ) ) ) && preg_match( '/^(?:\d|' . self::SPOKEN_RE . ')/i', $toks[ $i + 1 ][0] ?? '' ) ) {
				$end  = $off + strlen( $raw ); // "gate code pound 7 7 3 1", "is star 6608": the key pressed first
				$lead = true;
				continue;
			}
			if ( isset( self::MARKS[ $w ] ) && '' !== $out ) {
				$out .= self::MARKS[ $w ];
				$end  = $off + strlen( $raw );
				continue;
			}
			if ( $digits_only || $long ) {
				break;
			}
			if ( isset( self::TEENS[ $w ] ) ) {
				$out .= (string) self::TEENS[ $w ];
				$end  = $off + strlen( $raw );
				continue;
			}
			if ( isset( self::TENS[ $w ] ) ) {
				$next = strtolower( $toks[ $i + 1 ][0] ?? '' );
				if ( isset( self::UNITS[ $next ] ) && self::UNITS[ $next ] > 0 ) {
					$out .= (string) ( self::TENS[ $w ] + self::UNITS[ $next ] );
					$end  = $toks[ $i + 1 ][1] + strlen( $toks[ $i + 1 ][0] );
					$i++;
				} else {
					$out .= (string) self::TENS[ $w ];
					$end  = $off + strlen( $raw );
				}
				continue;
			}
			if ( isset( self::UNITS[ $w ] ) ) {
				$next = $toks[ $i + 1 ][0] ?? '';
				if ( in_array( $w, array( 'oh', 'o' ), true ) && ! ( preg_match( '/^[0-9]+$/', $next ) || self::is_spoken( $next ) )
					&& ! ( 'oh' === $w && self::is_spoken( $toks[ $i - 1 ][0] ?? '' ) && strlen( $out ) === 3 && ! preg_match( '/[^0-9]/', $out ) ) ) {
					break; // "1234 oh and turf": 1234; but "four four one oh": 4410
				}
				$out .= (string) self::UNITS[ $w ];
				$end  = $off + strlen( $raw );
				continue;
			}
			if ( isset( self::SOUNDS[ $w ] ) && self::is_spoken( $toks[ $i + 1 ][0] ?? '' ) && ( '' === $out || self::is_spoken( $toks[ $i - 1 ][0] ?? '' ) ) ) {
				$out .= (string) self::SOUNDS[ $w ];
				$end  = $off + strlen( $raw );
				continue;
			}
			break;
		}
		$out    = ltrim( $out, '#' ); // "# 0355", "code: #1984": the # reads "number"; one after the digits is pressed
		$digits = strlen( preg_replace( '/[^0-9]/', '', $out ) );
		if ( $digits < 3 || strlen( $out ) > 16 ) {
			return null;
		}
		return array( $out, $end );
	}

	/** A code as typed in a code box: the same reading, from the start. Null when it is not a code. */
	public static function normalize( $s ) {
		list( $p ) = self::plain( ' ' . trim( (string) $s ) );
		$r = self::read_code( $p, 0 );
		return $r ? $r[0] : null;
	}

	/** Does "code" at $start belong to a ZIP, a building code, a promo and so on? */
	private static function not_access( $text, $start ) {
		return (bool) preg_match( '/\b' . self::not_access_re() . '[\s\-]*(?:the\s+)?$/i', substr( (string) $text, max( 0, (int) $start - 40 ), min( 40, (int) $start ) ) );
	}

	/** A written code right after a house number's place ("Gate 1450 Oak St"): an address, not a code. */
	private static function looks_like_address( $text, $end, $street_only = false ) {
		// a street, not any capital word ("Gate: 3005 Door: 3006 Alarm: 3007" are three codes): a capital word after the
		// number is a street or a place ("Gate 1450 Elm Grove", "1450 Via de la Valle") -- except a label with its own
		// colon ("Gate: 3005 Door: 3006") and a few words. The words a street's name may start with are the region's
		// (street_first_words()) and a few articles.
		$tail  = substr( (string) $text, (int) $end, 120 );
		$first = self::lists()['first'];
		if ( preg_match( '/^[ \t]+(?:[NSEW]\.?\s+)?(?:\d+(?:st|nd|rd|th)\b|(?:' . ( '' !== $first ? $first . '|' : '' ) . 'Del|El|La|Las|Los|Mount|Mt\.?)\s+(?:(?:de|del|de\s+la|de\s+las|de\s+los)\s+)?[A-Za-z]|(?:[A-Z][A-Za-z\'\-]*\s+){1,3}' . self::STREET . ')/', $tail ) ) {
			return true; // "1357 Gate View Dr", "1450 Via de la Valle"
		}
		return ! $street_only && (bool) preg_match( '/^[ \t]+(?:[NSEW]\.?\s+)?(?:(?!(?:Door|Gate|Alarm|Lock\w*|Key\w*|Pool|Garage|Codes?|Pin|PIN|Combo|Call|Then|And|Or|Thanks|Thx|Please|Pls|Pound|Star|Hash|OFF|ON|Enter|Press|Use|Front|Back|Side|Alm|GC|LB|Wifi|WiFi|Garage|Note|Also|Use|Just|If|The|For|At|On|In|To|Is|It|Its|Not|NOT|New|Old|Was|Thank|Text|Ring|Park|Turf|Re|Bring|Dog|Dogs|Beware|Leave|See|Ask|Customer|Cust|HOA|Gated|Gave|Got|I|We|She|He|They|Ok|OK|Okay|Yes|No|Lockbox|Keypad|Alarm|Gate)\b)[A-Z][A-Za-z\'\-]*\b(?!\s*[:=]))/', $tail );
	}

	/** The words a street name ends with. */
	const STREET = '(?:St|Street|Ave|Av|Avenue|Rd|Road|Dr|Drive|Ln|Lane|Way|Ct|Court|Blvd|Boulevard|Pl|Place|Cir|Circle|Ter|Terrace|Pkwy|Parkway|Hwy|Highway|Trl|Trail|Loop|Row|Sq|Square|Glen|Cv|Cove|Pt|Point|Run|Path|Pass|Ridge|View|Crest|Heights|Hts|Hill|Hills|Knoll|Bend|Walk|Grove|Gln|Pkwy)\b\.?';

	/**
	 * More codes right after one: "4821 or 4822", "4821/4822", "4821 (alt 4822)", "Gate codes: 4821, 9911", "1234 5678".
	 * Each is read as a code of the same kind, so no digits of it are left in the words.
	 */
	private static function alternates( $text, $end, $kind, callable $add ) {
		for ( $k = 0; $k < 64; $k++ ) { // a run of alternates is read here, not one by one in rule 9 (speed)
			// several joining words ("4821, or 4822", "4821 or try 4822", "4821, if that fails 4822", "was
			// 4821 now 4822", "from 4821 to 4822", "4821 (backup 4822)"); never across a sentence; never a size or a price
			if ( preg_match( '/^((?:\s*(?:' . self::ALT_JOIN . '))+\s*)(?=(?:zero|oh|one|two|three|four|five|six|seven|eight|nine)\b)/i', substr( (string) $text, $end, 120 ), $sm ) ) {
				$r = self::read_code( $text, $end + strlen( $sm[1] ) );
				if ( $r && preg_match( '/\d{3}/', preg_replace( '/[^0-9]/', '', $r[0] ) ) ) {
					$add( $end + strlen( $sm[1] ), $r[1], $kind, $r[0] );
					$end = $r[1];
					continue;
				}
			}
			$then = preg_match( '/[#*]$/', substr( (string) $text, max( 0, $end - 1 ), 1 ) ) ? '|then' : ''; // "1402# then 0915"
			if ( ! preg_match( '/^((?:\s*(?:' . self::ALT_JOIN . $then . '))*\s*(?:(?:front|back|side|rear|main|pool|community|garage|gate|alarm|alm|lock\s*-?\s*box|key\s*-?\s*pad|keypad|call\s*-?\s*box|padlock|combo|door)\b(?:\s+(?:gate|code|combo|pin))?\s*(?:is\s+)?)?[:#]?\s*)([A-Za-z]?\d{3,}[0-9#*]*|(?:\d[\s.\-]){3,7}\d(?!\d))\)?/i', substr( (string) $text, $end, 160 ), $m ) || strlen( $m[1] ) > 48 ) {
				return;
			}
			if ( preg_match( '/(pool|community|garage|gate|alarm|lock\s*-?\s*box|key\s*-?\s*pad|keypad|call\s*-?\s*box|padlock|combo)\b\W*(?:(?:gate|code|combo|pin)\b\W*)?(?:is\W*)?$/i', $m[1], $km ) ) {
				$kind = $km[1];
			}
			$from = $end + strlen( $m[1] );
			$r    = self::read_code( $text, $from, ! preg_match( '/^\d[\s.\-]\d/', $m[2] ) );
			if ( ! $r || self::looks_like_address( $text, $r[1] ) || self::not_a_code( $text, $from, $r[1], $r[0], 'alt' ) ) {
				return; // the next number may be a street, a time, a job or money ("4821 - 1450 balance due")
			}
			// an old code after the new one ("it was", "used to be", "previously", "formerly",
			// "before") is read only as the same length as the code before it, and only when nothing says money, a time or
			// a year, and the number ends its clause (or a hedge follows: "though", "I think", "per HOA", "until May").
			// "it was 1450 for the install", "used to be 300?" and "previously 1999, paid in full" stay words.
			if ( preg_match( self::OLD_JOIN, $m[1] ) && ! self::old_code_ok( $text, $end, $r ) ) {
				return;
			}
			$add( $from, $r[1], $kind, $r[0] );
			$end = $r[1];
		}
	}

	/** The joining words that name an older code (alternates()); read with old_code_ok(). */
	const OLD_JOIN = '/\b(?:it\s+was|used\s+to\s+be|previously|formerly|before)\b/i';

	/** May the number $r ([code, end]) after an "it was" / "used to be" / "before" be the older code of the one ending at $end? */
	private static function old_code_ok( $text, $end, array $r ) {
		$text = (string) $text;
		$prev = preg_match( '/(\d(?:[\d#*]|[\s.\-](?=\d))*)[#*]?\)?$/', substr( $text, max( 0, $end - 40 ), $end - max( 0, $end - 40 ) ), $pm ) ? strlen( preg_replace( '/\D/', '', $pm[1] ) ) : 0;
		if ( $prev !== strlen( preg_replace( '/\D/', '', $r[0] ) ) ) {
			return false; // "4821 ... it was 300", "5521 ... used to be 19999"
		}
		$tail = substr( $text, $r[1], 60 );
		$near = substr( $text, max( 0, $end - 60 ), $r[1] - max( 0, $end - 60 ) ) . ' ' . (string) preg_replace( '/[.!?\n].*$/s', '', $tail );
		if ( preg_match( '/\$|\b(?:paid|pay|balance|deposit|due|total|price|priced|cost|costs|charge|charged|invoice|quote|quoted|dollars?|bucks|am|pm|a\.m|p\.m|o\'?clock|noon|hours?|minutes?|years?|ago|last\s+(?:year|month|week|time)|install(?:ed|ation)?\s+(?:date|time))\b/i', $near ) ) {
			return false; // money, a time or a year is being talked about
		}
		return (bool) preg_match( '/^[#*]?\s*(?:$|[;,)!?\n]|\.(?!\d)|(?:though|tho|i\s+think|i\s+believe|maybe|probably|per\b|until|till|anymore|any\s+more|now\b|lol)\b)/i', $tail );
	}

	/**
	 * What may join one code to the next (alternates()): a comma, a slash, an arrow, "or", "or try", "if not", "backup",
	 * "actually no it's", "was … now"; "(" and "-" only before "or" / "alt" / "backup"; "and" only right before the next
	 * code or its kind. Never ";", a full stop, "the", "is", "to", "from", "then" ("Gate code 4821 and the job is 1450",
	 * "works 0800-1700").
	 * An old code after the new one -- "(it was 2486)", "it used to be 1284", "(previously 1155)", "before it was
	 * 2727" -- is read with old_code_ok() in alternates(): the same length as the code before it, nothing about money, a
	 * time or a year near it, and the number ends its clause or a hedge follows ("it was 1450 for the install", "used to
	 * be 2000 a pair" and "before 4505 they moved" stay words).
	 */
	const ALT_JOIN = 'instead\s+of|rather\s+than|\bthe\s+(?=(?:old|new|other|backup|second)\b)|,|;\s*(?=[#*]?\d{3,8}[#*]?\s*(?:$|[;,)]))|\/|&|->|=>|\xE2\x86\x92|\(\s*(?=(?:alt|alternate|backup|or|also|old|new|was|not|till|until|prev|previous|previously|formerly|if|it\s+(?:was|is|used)|used\s+to|before)\b)|-\s*(?=(?:or|alt|backup)\b)|\band\b(?=\s*(?:[#*]?\d|(?:front|back|side|pool|garage|gate|alarm|lock\s*-?\s*box|keypad|call\s*box|padlock|combo|door)\b))|\b(?:or|else|maybe|possibly|i\s+think|try|if|not|actually|no|wait|sorry|correction|alt|alternate|also|backup|back-up|(?:old|new|other|backup|second)(?:\s+one)?(?:\'s|\s+is|\s+was)|second|other|old|new|one|ones|(?:till|until)\s+then|now|was|it\s+was|(?:it\s+)?used\s+to\s+be|previously|formerly|before|it\'?s|it\s+is|that|fails?|failed|(?:doesn\'?t|don\'?t|does\s+not)\s+work|anymore|any\s+more|use)\b\.?';


	/**
	 * Is the number at [$start, $end) something other than a code? Money, a unit or a size after it, a
	 * time, a document or unit number, a product, a phone number, a year, a door size. $weak: only for readings that did
	 * not have a code word right before the number (a kind alone, reverse order, a verb, the weighed reading).
	 */
	private static function not_a_code( $text, $start, $end, $code, $weak ) {
		$alt  = 'alt' === $weak; // a code right after another: the weak checks, less "a number before it is a phone's"
		$weak = (bool) $weak;
		$before = substr( (string) $text, max( 0, $start - 40 ), $start - max( 0, $start - 40 ) );
		$after  = substr( (string) $text, $end, 24 );
		$digits = preg_replace( '/[^0-9]/', '', (string) $code );
		$sb     = self::sent_before( $text, $start );
		$quoted = (bool) preg_match( '/["\'\x{201C}\x{2018}]\s*$/u', $before ) // "4821" is quoted, not 48 inches
			|| 1 === preg_match_all( '/(?<![\w\'])\'(?=\w)/', $sb ) % 2 || 1 === preg_match_all( '/(?<!\w)["\x{201C}](?=\w)/u', $sb ) % 2; // 'its 7 7 3 1'
		if ( preg_match( '/\$\s*$/', $before ) || ( ! $quoted && ( preg_match( self::UNIT_AFTER, $after ) || self::trade_after( $after ) ) ) || preg_match( '/^\s*:\d\d/', $after ) ) {
			return true; // "$1450", "1450 sq ft", "36x80", "96.5", "1,240", "1700 hours", "7:30", "2 awnings" (a trade word)
		}
		if ( preg_match( '/^[A-Za-z]/', $after ) && preg_match( '/\d$/', substr( (string) $text, $start, $end - $start ) ) ) {
			return true; // "220v", "4821abc": the number is part of a word
		}
		if ( preg_match( '/\(\s*$/', $before ) && preg_match( '/^\s*\)\s*\d{3}[-.\s]\d{4}/', $after ) ) {
			return true; // "(555) 555-1234": an area code
		}
		if ( preg_match( '/\bgarage\s*(?:door\s*)?(?:size\s*)?[:#]?\s*$/i', $before ) && preg_match( '/^(?:0[89]|1[0-8])(?:0[78]|10|12)$/', $digits ) && ! preg_match( '/\b(?:code|pin|keypad|key\s*pad|combo)\b/i', self::sentence( $text, $start, $end ) ) ) {
			return true; // "garage 1607": a garage door 16' x 7'
		}
		// a door's size: "patio door #3080", "French door no. 6080", "(1) 3068 patio door", "door 3068 LH" -- a width of
		// 2'0"-9'11" then a height of 6'0"-9'11" -- unless a code word stands between the door and the number
		if ( preg_match( '/^[2-9][0-9][6-9][0-9]$/', $digits ) && self::door_size_words( self::sent_before( $text, $start ), $after ) ) {
			return true;
		}
		if ( preg_match( '/^\s*\/\s*\d{1,2}(?!\d)/', $after ) && preg_match( '/(?:^|\D)\d{1,2}$/', substr( (string) $text, $start, $end - $start ) ) ) {
			return true; // "46 1/4": a measurement
		}
		if ( preg_match( '/\b(?:sections?|sec\.?|chapter|table|\xC2\xA7|ibc|irc|ifc|nec|ul|astm|ansi|nfpa|title|r' . self::region_alt() . ')\s*$/i', $before ) || preg_match( '/^[A-Z]?\.\d/', $after ) ) {
			return true; // "code section R337", "pool barrier code CBC 3109", "706A.2": a code book, not an access code
		}
		if ( preg_match( '/\b(?:gate|lock\s*box|pool|entry)\s+' . self::STREET . '\s*$/i', $before ) ) {
			return true; // "Golden Gate Dr 1452": a street with Gate in its name
		}
		if ( ! preg_match( '/\b(?:gate|lock\s*-?\s*box|keypad|key\s*pad|alarm|garage|pool|call\s*-?\s*box|combo|padlock|pin|passcode)\b/i', $before ) && preg_match( '/^\s+(?:walnut|bronze|white|black|almond|tan|sandstone|beige|gr[ae]y|brown|ivory|clay|adobe|desert|silver|champagne|cream)\b/i', $after ) ) {
			return true; // "code 1440 walnut": a colour
		}
		if ( preg_match( '/\bnot\s+(?:a|the|an?\s+access)\s+(?:gate\s+)?code\W*$/i', $before ) || preg_match( '/^[\s\-,]*(?:is\s+)?(?:not|isn\'?t)\s+(?:a|the)\s+(?:gate\s+)?code\b/i', $after ) ) {
			return true; // "Gate 2490 - not a code, that's the total", "the address, not a code: 1250 Oak St"
		}
		if ( preg_match( '/^(?:[01]\d|2[0-3])[0-5]\d$/', $digits ) && ( preg_match( '/\b(?:meet|met|meeting|arrive|arriving|delivery|deliver|appointment|appt|eta|start|starts|starting|between|works|open|opens|close|closes|hours)\b[^.;,]{0,30}$/i', $before ) || preg_match( '/\b(?:what\s+time|when|eta)\b[^\n]*\n[^\n]*$/i', substr( (string) $text, max( 0, $start - 80 ), min( 80, $start ) ) ) || preg_match( '/^\s*(?:-|to|until|till)\s*(?:[01]\d|2[0-3])[0-5]\d\b|^\s*(?:o\'?clock|hrs|h)\b/i', $after ) ) ) {
			return true; // "Meet customer 1430 at the gate", "Delivery 0800 at the gate", "works 0800-1700"
		}
		if ( isset( self::$public[ $digits ] ) ) {
			return true; // the same digits are the unit's number elsewhere in the words ("unit 1402 ... 1402#")
		}
		if ( ! $weak ) {
			return false;
		}
		if ( ! $quoted && preg_match( self::UNIT_AFTER_WEAK, $after ) ) {
			return true; // "304 stainless", "1800 series", "1450 balance due", "1430 start", "3080 as the door width"
		}
		if ( preg_match( '/^(?:19|20)\d\d$/', $digits ) && preg_match( '/\b(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\.?\s*$/i', $before ) ) {
			return true; // "Oct 2026"
		}
		if ( false !== strpos( (string) $code, ',' ) ) {
			return true; // "2,480" said with no code word: an amount
		}
		$pc = self::lists()['postcode'];
		if ( null !== $pc && preg_match( '/' . $pc['digits'] . '/D', $digits ) && preg_match( '/(?:^|[.;\n]\s*|' . ( '' !== $pc['region'] ? '\b(?:' . $pc['region'] . ')\W*|' : '' ) . ',\s*|\b' . self::STREET . '\W*)$/', $before ) ) {
			return true; // "12345 - gate code 1066", "Springfield, ST 12345": the region's postcode (POSTCODE_OPT)
		}
		if ( preg_match( '/\b(?:unit|apt|apartment|suite|space|lot|room|model|size|width|height|price|cost|total|invoice|estimate|po|house|address|job|repair|labor|labour|install|quote|deposit|balance)\s*(?:#\s*|no\.?\s*|number\s+)?(?:is|was|=|:)\s*$/i', $before ) ) {
			return true; // "unit is 1204", "model is 5800"
		}
		$model = self::lists()['model'];
		if ( preg_match( self::NOT_BEFORE, $before ) || ( '' !== $model && preg_match( $model, $before ) ) || ( ! $alt && preg_match( '/\d[-. \t]$/', $before ) ) ) {
			return true; // "estimate #6523", "unit 1204", "model 1750", "call 555-0142", "555-555-0142", "Add 350", "price 1450"
		}
		if ( preg_match( '/^[ \t]+(?!(?:Door|Gate|Alarm|Lock\w*|Key\w*|Pool|Garage|Codes?|Pin|PIN|Combo|Call|Then|The|And|Or|Thanks|Thx|Please|Pls|Pound|Star|Hash|OFF|ON|Enter|Press|Use)\b)[A-Z][a-z]{2,}\b(?!\s*[:=])/', $after ) ) {
			return true; // "for 2214 Maple", "4821 Elm Grove": a street or a place
		}
		if ( preg_match( '/^(?:19|20)\d\d$/', $digits ) && preg_match( '/\b(?:since|built|in|year|from|circa|until|by|of|installed|install|bought|purchased|replaced|done|model|class)\s*$/i', $before ) ) {
			return true; // "built 1998"
		}
		if ( preg_match( '/^(?:[01]\d|2[0-3])[0-5]\d$/', $digits ) && preg_match( '/\b(?:at|after|before|until|till|by|from|opens?|closes?|closed|locks|locked|arrive|arrival|starts?|ends?|around|about)\s*$/i', $before ) ) {
			return true; // "gate locked after 1700", "arrive 0800"
		}
		if ( preg_match( '/^\d{3}-\d{4}$|^\d{7,}$/', (string) $code ) || preg_match( '/^[-.\s]\d{4}\b/', $after ) ) {
			return true; // a phone number read as one written code ("555-0142"), or the start of one
		}
		return false;
	}

	/**
	 * Door or opening words (or the trade's own words for things) beside a number, with no code word between the door
	 * word and the number.
	 */
	private static function door_size_words( $before, $after ) {
		$t  = self::trade_alt();
		$tw = '' === $t ? '' : '(?:' . substr( $t, 1 ) . ')';
		if ( preg_match( '/^\s*(?:-\s*)?(?:[LR]H\b|(?:french|sliding|patio|entry|security|storm' . $t . ')?\s*doors?\b|' . ( '' !== $tw ? $tw . '\b|' : '' ) . 'opening\b|and\s+#?[23][0-9][6-9][0-9]\b)/i', $after ) ) {
			return true;
		}
		$sent = preg_split( self::SPLIT, $before );
		$sent = (string) end( $sent );
		if ( ! preg_match_all( '/\b(?:doors?|french|sliding|patio|storm|opening|size|rough|entry|entrance|panels?|combination|security|trailer|corners?' . $t . ')\b/i', $sent, $m, PREG_OFFSET_CAPTURE ) ) {
			return false;
		}
		$last = end( $m[0] );
		$tail = substr( $sent, $last[1] + strlen( $last[0] ) );
		if ( preg_match( '/^(?:entry|entrance|panels?|combination|security)$/i', $last[0] ) && ( preg_match( '/^\s*#\s*$/', $tail ) || preg_match( '/\b(?:alarm|alm|disarm|arm|codes?|keypad|key\s*pad|gate|community|hoa|guard)\b/i', $sent . ' ' . $after ) ) ) {
			return false; // "Front entrance #5160", "the panel: 2299 (alarm)": a code; "entry 3068" stays a size
		}
		$code = '\b(?:codes?|pin|combo|combination|key\s*-?\s*pad|keypad|lock\s*-?\s*box|lockbox|lock|alarm|gate|passcode|call\s*-?\s*box|garage)\b';
		if ( preg_match( '/' . $code . '[^.;]*\b(?:at|on|by|near|for|of|to|beside|inside|outside)\s+(?:the\s+)?(?:\w+\s+){0,3}(?:doors?|patio' . $t . ')\b\s*(?:is|was|=|:|-|,)\s*$/i', $sent ) ) {
			return false; // "the lockbox code at the side door is 4089"
		}
		return strlen( $tail ) <= 14 && ! preg_match( '/' . $code . '|digit/i', $tail );
	}

	/** The kind a code word names, or the nearest one in the same sentence before $at (else after it), else gate. */
	private static function near_kind( $text, $at, $end ) {
		$K = self::kind_re();
		if ( preg_match( '/\b(?:dis)?arm(?:ed|s)?\b/i', self::sentence( $text, $at, $end ) ) && ! preg_match( '/\b' . $K . '(?![a-z])/i', self::sent_before( $text, $at ) ) ) {
			return 'alarm'; // "disarm with 6020"
		}
		$l = self::sent_before( $text, $at );
		if ( preg_match_all( '/\b' . $K . '(?![a-z])/i', $l, $m ) ) {
			$door = (bool) preg_match( '/door\s*-?\s*(?:code|pin|combo|key)/i', $l );
			foreach ( array_reverse( $m[1] ) as $w ) { // the nearest that is no door ("Lockbox on the patio door 4821": a lockbox)
				if ( 'door' !== self::kind( $w ) || $door ) {
					return self::kind( $w );
				}
			}
		}
		if ( preg_match_all( '/\b' . $K . '(?![a-z])/i', self::sent_after( $text, $end ), $m ) ) {
			foreach ( $m[1] as $w ) { // "got in with 8086, the garage keypad": the keypad
				if ( 'door' !== self::kind( $w ) ) {
					return self::kind( $w );
				}
			}
		}
		// none in the sentence -- the sentence before ("Garage keypad 4410? No - she corrected it, it's 4401")
		$pe = $at - strlen( $l );
		if ( $pe > 0 && preg_match_all( '/\b' . $K . '(?![a-z])/i', self::sent_before( $text, max( 0, $pe - 1 ) ), $m ) ) {
			foreach ( array_reverse( $m[1] ) as $w ) {
				if ( 'door' !== self::kind( $w ) ) {
					return self::kind( $w );
				}
			}
		}
		return 'gate';
	}

	/**
	 * Numbers the words show as a unit's ("unit 1402", "Dr #305"), for not_a_code(): a call box's directory number that is
	 * the unit's own is on the label anyway. Deliberately not a house's number: "the gate code is 2772, the address is 2772
	 * Oak St" still hides the code, since which number opens the gate is the secret.
	 */
	private static $public = array();

	private static function public_numbers( $text ) {
		$out = array();
		if ( preg_match_all( '/\b(?:unit|apt|apartment|suite|ste)\s*#?\s*(\d{2,6})\b|\b' . self::STREET . '\s*#\s*(\d{2,6})\b/i', $text, $mm, PREG_SET_ORDER ) ) {
			foreach ( $mm as $m ) {
				$out[ '' !== $m[1] ? $m[1] : $m[2] ] = true;
			}
		}
		return $out;
	}

	/** Every code in $text: [[start, end, kind, code], ...] in order, never overlapping (offsets in $text). Holds codes. */
	private static function scan( $text ) {
		$orig = (string) $text;
		if ( '' === $orig ) {
			return array();
		}
		list( $text, $map ) = self::plain( $orig );
		$text  = self::unscore( $text ); // "gate_code: 4821" (a record's field), same length
		self::$public = self::public_numbers( $text );
		if ( ! self::has_number( $text ) ) {
			return array(); // the gate might_have_code() shares: no number, written or spoken, no code
		}
		$K    = self::kind_re();
		$C    = self::CODE;
		$IS   = self::IS;
		$hits = array();
		$add  = function ( $start, $end, $kind, $code ) use ( &$hits ) { $hits[] = array( $start, $end, self::kind( $kind ), $code ); };
		$take = function ( $start, $from, $kind, $digits_only = false, $tail = null ) use ( $text, $add ) {
			$r = self::read_code( $text, $from, $digits_only );
			if ( ! $r && $digits_only ) {
				// spoken digits after a bare "code" ("Gated. Code eight five four four"): four or more of them
				$r = self::read_code( $text, $from );
				$r = $r && strlen( preg_replace( '/[^0-9]/', '', $r[0] ) ) >= 4 ? $r : null;
			}
			if ( ! $r || self::not_a_code( $text, $from, $r[1], $r[0], false ) ) {
				return;
			}
			$end = $r[1];
			if ( null !== $tail ) {
				if ( ! preg_match( $tail, substr( $text, $end, 80 ), $t ) ) {
					$add( $start, $end, 'gate', $r[0] );
					self::alternates( $text, $end, 'gate', $add );
					return;
				}
				$kind = $t[1];
				$end += strlen( $t[0] );
			}
			$add( $start, $end, $kind, $r[0] );
			self::alternates( $text, $r[1], $kind, $add );
		};

		// 1. a kind and a code word, apart or joined: "gate code is 4567", "gatecode 1234", "lockbox # 5521",
		//    "lockbox number 1972", "alarm pin 1234", "Gate codes: 4821, 9911"
		if ( preg_match_all( '/\b' . $K . '\s*-?\s*(?:' . $C . '|cod(?![a-z])|cd(?![a-z])|pw(?![a-z])|pwd(?![a-z])|password(?![a-z])|#|number(?![a-z])|no\.|combo(?![a-z])|combination(?![a-z])|pin(?![a-z])|passcode(?![a-z]))' . $IS . '/i', $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $mm as $m ) {
				$take( $m[0][1], $m[0][1] + strlen( $m[0][0] ), $m[1][0] );
			}
		}
		// 2. "the code for the gate is 2468"
		if ( preg_match_all( '/\b(?:the\s+)?' . $C . '\s+(?:at|for|to|on|of)\s+(?:the\s+)?' . $K . '(?![a-z])' . $IS . '/i', $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $mm as $m ) {
				$take( $m[0][1], $m[0][1] + strlen( $m[0][0] ), $m[1][0] );
			}
		}
		// 3. a kind and a code word, a few words, then the code: "Gate code for the Smith house is
		//    4821", "the lockbox code at the side door is 5521"
		if ( preg_match_all( '/\b' . $K . '\s*-?\s*' . $C . '((?:[ \t]+[A-Za-z][A-Za-z\'\-]*){1,6}?)[ \t]*(?:is|was|=|:)(?![a-z])' . $IS . '/i', $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $mm as $m ) {
				$take( $m[0][1], $m[0][1] + strlen( $m[0][0] ), $m[1][0] );
			}
		}
		// 4. "the code is 4567 for the gate" (the kind after), and a bare "code is 4821" / "code: #1984" / "code 1234" (a gate);
		//    never "zip code 12345", "building code 2022"
		if ( preg_match_all( '/\b(?:the\s+)?' . $C . '(' . $IS . ')/i', $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $mm as $m ) {
				if ( self::not_access( $text, $m[0][1] ) ) {
					continue;
				}
				$spoken_ok = (bool) preg_match( '/\b(?:is|was|are|it\'s|its)\b/i', $m[1][0] );
				$take( $m[0][1], $m[0][1] + strlen( $m[0][0] ), 'gate', ! $spoken_ok, '/^\s+(?:at|for|to|on)\s+(?:the\s+)?' . $K . '(?![a-z])/i' );
			}
		}
		// 5. a code word and written digits, no "code": "Gate 1234", "Lockbox: L1984", "buzz 112", "keypad #4455"
		//    (never a house number: "Gate 1450 Oak St")
		if ( preg_match_all( '/\b(gate|lock\s*-?\s*box|key\s*-?\s*box|key\s*-?\s*pad|combo|buzz(?:er)?|call\s*-?\s*box)[ \t]*[:#]?[ \t]*/i', $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $mm as $m ) {
				$r = self::read_code( $text, $m[0][1] + strlen( $m[0][0] ), true );
				if ( $r && false !== strpos( substr( $text, $m[0][1] + strlen( $m[0][0] ), $r[1] - $m[0][1] - strlen( $m[0][0] ) ), "\n" ) ) {
					continue; // on the next line: rule 10 reads an answer there ("what time are you at the gate" / "1015")
				}
				if ( $r && preg_match( '/^[A-Z]?\d{3,}/', $r[0] ) && ! self::looks_like_address( $text, $r[1] ) && ! self::not_a_code( $text, $m[0][1] + strlen( $m[0][0] ), $r[1], $r[0], false ) ) {
					$add( $m[0][1], $r[1], $m[1][0], $r[0] );
					self::alternates( $text, $r[1], $m[1][0], $add );
				}
			}
		}
		// 6. a kind word alone, then a connector or the code: "lockbox is 6712", "alarm: #5871", "keypad is
		//    now #8159", "gate eight zero three six pound", "callbox is 488"; weaker words need a connector ("pool is 4321",
		//    "garage: 2580"). Never a size, a price, an address or a time ("pool 1500 gallons", "garage is 2 car").
		$ISN = str_replace( '\-.()', '\-()', self::IS ); // a kind word never reads across a full stop ("Fixed the gate. 1450 paid")
		foreach ( array( array( self::BARE, false ), array( self::BARE_WEAK, true ), array( self::BARE_MARK, 'mark' ), array( '(door|front\s+door|side\s+door|back\s+door)(?=\s*[:=\-])', true ) ) as $pair ) {
			if ( ! preg_match_all( '/\b' . $pair[0] . '(?:\'s|s|es)?(?![a-z])(' . $ISN . ')/i', $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $mm as $m ) {
				$said = (bool) preg_match( '/[:#=\-]|\'s|\b(?:is|was|are|' . self::NOW . ')\b/i', $m[0][0] );
				$from = $m[0][1] + strlen( $m[0][0] );
				$r    = self::read_code( $text, $from );
				$hash = $r && ( preg_match( '/[#*]$/', $r[0] ) || false !== strpos( $m[2][0], '#' ) );
				$ctx = 'mark' === $pair[1] && $said && preg_match( '/\b(?:alarm|alm|disarm|arm|security\s+(?:panel|system)|gate|codes?|keypad|community|hoa|guard)\b/i', self::sentence( $text, $m[0][1], $from ) . ' ' . substr( $text, $from, 40 ) );
				if ( ( 'mark' === $pair[1] && ! $hash && ! $ctx ) || ( true === $pair[1] && ! $said && ! $hash ) ) {
					continue; // "pool 1500 gallons", "Entry: 3068" -- but "entry 7720#", "Front entrance #5160" are pressed
				}
				$dir = (bool) preg_match( '/^(?:call|intercom|buzz)/i', $m[1][0] );
				if ( ! $r || self::looks_like_address( $text, $r[1], $dir ) || self::not_a_code( $text, $from, $r[1], $r[0], $dir ? false : true ) ) {
					continue;
				}
				$add( $m[0][1], $r[1], $m[1][0], $r[0] );
				self::alternates( $text, $r[1], $m[1][0], $add );
			}
		}
		// 6b. "GC 4821", "G/C: 4821", "LB 5521", "L/B #5521" (written so, in capitals: "50 lb" is a weight)
		if ( preg_match_all( '/(?<![A-Za-z])(GC|G\/C|G\.C\.|LB|L\/B|LBX|gc(?=\s*[:#]|\s+\d{3,8}\b)|lb(?=\s*[:#]))(?![A-Za-z])(?i:' . $ISN . ')/', $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $mm as $m ) {
				$from = $m[0][1] + strlen( $m[0][0] );
				$r    = self::read_code( $text, $from );
				if ( $r && ! self::not_a_code( $text, $from, $r[1], $r[0], true ) && ! self::looks_like_address( $text, $r[1] ) ) {
					$add( $m[0][1], $r[1], 'l' === strtolower( $m[1][0][0] ) ? 'lockbox' : 'gate', $r[0] );
				}
			}
		}
		// 6c. a kind word alone, a few words, then the code: "Lockbox for tomorrow is #6152", "keypad they gave us is 5770",
		//     "PIN I think is 6510", "Gate as of today is one two one zero star". Not when the words are about the thing
		//     itself ("gate opener model is 8500", "keypad size is 3068", "gate price was 1450").
		if ( preg_match_all( '/\b(' . substr( self::BARE, 1, -1 ) . '|' . substr( self::BARE_WEAK, 1, -1 ) . '|codes?|combo|pin)(?:\'s|s)?((?:[ \t,()\-\'"]+(?!(?:codes?|combo|pin|passcode)\b)(?:[A-Za-z][A-Za-z\'\-]*\b|\d{1,2}\b|\d{3,6}(?=(?-i)\s+[A-Z][a-z]))){1,14}?)[ \t,)]*(?:(?<![A-Za-z])(?:is|was|' . self::NOW . ')(?![a-z])|[=:])' . $ISN . '/i', $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			$tr = self::trade_alt();
			foreach ( $mm as $m ) {
				if ( preg_match( '/\b(?:is|was|are|were|model|size|price|cost|width|height|year|qty|opener|motor' . $tr . '|warranty|serial|part|color|colour|stain|hours?|time|opens?|closes?|number\s+of|zip|area|promo|error|section|chapter|table|install|installation|repair|labor|labour|hardware|quote|deposit|kit|total|balance|paid|due|charge|phone|account|acct|member|membership|policy|license|licence|tracking|confirmation|ticket|claim|routing|(?:house|street|job|lead|order|invoice|estimate|po|unit|lot|model|part|serial)\s*(?:#|no\.?|number))\b/i', $m[2][0] ) ) {
					continue; // the clause about the code ended, or the words are about something else
				}
				$box = (bool) preg_match( '/^(?:call|intercom|buzz)/i', $m[1][0] ); // "the callbox number for her unit is 214"
				if ( ! $box && ! preg_match( '/^(?:codes?|combo|pin)$/i', $m[1][0] ) && preg_match( '/,\s*and\b|\b(?:fence|enclosure|latch|closer|panels?|house|address|street|unit|lot|apt|suite|trailer|corner|posts?|hinges?|motor|arm|track|wheel|roller|spring|post|sign|light|lights' . $tr . ')\b/i', $m[2][0] ) ) {
					continue; // "Pool enclosure is 4500", "Gate hinges are 1450": the words are about the thing, not its code
				}
				if ( preg_match( '/\b(?:' . substr( self::BARE, 1, -1 ) . '|' . substr( self::BARE_WEAK, 1, -1 ) . '|door)\W*$/i', $m[2][0] ) && preg_match( '/^[ \t]*[:#=\-]/', substr( $text, $m[2][1] + strlen( $m[2][0] ), 4 ) ) ) {
					continue; // "Codes - Gate: 3005": the gate names it (rule 6)
				}
				if ( preg_match( '/^codes?$/i', $m[1][0] ) && self::not_access( $text, $m[0][1] ) ) {
					continue;
				}
				$from  = $m[0][1] + strlen( $m[0][0] );
				$r     = self::read_code( $text, $from );
				$place = (bool) preg_match( '/\b(?:job|house|place|stop|home|residence|property)\W*$/i', $m[2][0] );
				if ( ! $r || self::looks_like_address( $text, $r[1] ) || self::not_a_code( $text, $from, $r[1], $r[0], ! $place ) ) {
					continue;
				}
				$k  = preg_match( '/^(?:codes?|combo|pin)$/i', $m[1][0] ) ? self::near_kind( $text, $m[0][1] + strlen( $m[1][0] ), $from ) : $m[1][0];
				$at = $m[0][1];
				if ( preg_match( '/\b' . $K . '\s*-?\s*$/i', substr( $text, max( 0, $at - 30 ), min( 30, $at ) ), $km ) ) {
					$at -= strlen( $km[0] ); // "Gate code for the Smith house ... is 4821": the whole phrase, from "Gate"
					$k   = $km[1];
				}
				$add( preg_match( '/\d/', $m[2][0] ) ? $from : $at, $r[1], $k, $r[0] );
				self::alternates( $text, $r[1], $k, $add );
			}
		}
		// 6f. a call box's directory number, two to four digits: "Directory 117", "Call box #45", "Gate box dir 31", "buzz #08",
		//     "hit 07 on the call box". A directory number lets a stranger ring the house, so it is sealed as the gate's.
		if ( preg_match_all( '/\b(?:(?:call\s*-?\s*box|callbox|gate\s+box|intercom|buzz(?:er)?)\s*(?:dir(?:ectory)?\.?\s*)?(?:#|no\.?\s*|number\s*)?|directory\s*(?:#|no\.?\s*|number\s*)?|dir\.?\s*#?\s*)[:#]?\s*(\d{2,4})(?![\d\w\/:.,]\d|[\w\/])/i', $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $mm as $m ) {
				$a = $m[1][1];
				$b = $a + strlen( $m[1][0] );
				if ( preg_match( '/(?:\b(?:unit|apt|apartment|suite|ste)\s*#?\s*|#)' . $m[1][0] . '\b/i', substr( $text, 0, $m[0][1] ) . ' ' . substr( $text, $b ) ) ) {
					continue; // "8836 Example Dr #305 - lobby call box 305": the unit number, on the label anyway
				}
				if ( preg_match( '/^dir/i', $m[0][0] ) && ! preg_match( '/\b(?:call\s*-?\s*box|callbox|box|gate|buzz\w*|intercom|lobby|unit|apt|code|dial|ring|press|hit|then)\b/i', self::sentence( $text, $m[0][1], $b ) ) ) {
					continue; // "Price list directory 2024 edition"
				}
				if ( ! preg_match( '/^\s*(?:x|by|ft|feet|in|inch|mm|lbs?|min|mins|minutes|hours?|days?|weeks?|years?|yrs?|am|pm|%|v|volts?|amps?|watts?|ohms?|gauge|ga|pack|pcs|pieces|each|ea|units?|ft\.?)\b/i', substr( $text, $b, 10 ) ) && ! preg_match( '/\$\s*$/', substr( $text, max( 0, $a - 3 ), min( 3, $a ) ) ) && ! self::looks_like_address( $text, $b, true ) ) {
					$add( $a, $b, 'gate', $m[1][0] );
				}
			}
		}
		if ( preg_match_all( '/\b(?:hit|press|dial|punch|enter)\s+#?(\d{2,4})#?\s+(?:on|at)\s+the\s+(?:call\s*-?\s*)?box\b/i', $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $mm as $m ) {
				$add( $m[1][1], $m[1][1] + strlen( $m[1][0] ), 'gate', $m[1][0] );
			}
		}
		// 6g. a code with its pound key: "then 2208#", "she just texted it's 4040#" -- pressed, so a code wherever it stands
		if ( preg_match_all( '/(?<![\w#*$.,\/-])(\d{3,8})\s?#(?![\w#])/', $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $mm as $m ) {
				$b = $m[0][1] + strlen( $m[0][0] );
				if ( ! self::not_a_code( $text, $m[1][1], $m[1][1] + strlen( $m[1][0] ), $m[1][0], true ) ) {
					$k = self::near_kind( $text, $m[0][1], $b );
					$add( $m[0][1], $b, $k, $m[0][0] );
					self::alternates( $text, $b, $k, $add ); // "5521# then 0915"
				}
			}
		}
		// 6e. Spanish: "el código es 4821", "Código del portón: 4821", "clave de la puerta 4821", "el portón: 5 8 2 0",
		//     "la del portón es 1358", "marque 1203 en el intercomunicador"
		if ( preg_match_all( '/\b(?:(?:la|el)\s+(?:del?\s+(?:la\s+)?)?)?(port(?:o|\xC3\xB3)n|caja|candado|alarma|intercomunicador)\s*(?:es|:|#|=)?\s*(?=\d)|\b(?:marque|marca|marcar|presione|presiona|ingrese|ingresa|teclee|teclea|pon|ponga)\s+(?:el\s+)?/i', $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $mm as $m ) {
				$from = $m[0][1] + strlen( $m[0][0] );
				$r    = self::read_code( $text, $from, true );
				if ( ! $r || self::not_a_code( $text, $from, $r[1], $r[0], true ) ) {
					continue;
				}
				$sk = strtolower( $m[1][0] ?? '' );
				if ( '' === $sk && ! preg_match( '/port(?:o|\xC3\xB3)n|puerta|intercomunicador|caja|clave|c(?:o|\xC3\xB3)digo|alarma|entrar/i', self::sentence( $text, $m[0][1], $r[1] ) ) ) {
					continue; // a verb alone needs a gate, a door or a box in its sentence
				}
				$k = in_array( $sk, array( 'caja', 'candado' ), true ) ? 'lockbox' : ( 'alarma' === $sk ? 'alarm' : 'gate' );
				$add( '' === $sk ? $from : $m[0][1], $r[1], $k, $r[0] );
			}
		}
		if ( preg_match_all( '/\b(?:el\s+|la\s+)?(?:c(?:o|\xC3\xB3)digo|clave)(?:\s+(?:del?|de\s+la)\s+(port(?:o|\xC3\xB3)n|puerta|garaje|alarma|caja|candado))?\s*(?:es|son|:|#|=)?\s*/i', $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $mm as $m ) {
				$from = $m[0][1] + strlen( $m[0][0] );
				$r    = self::read_code( $text, $from, true );
				if ( $r && ! self::not_a_code( $text, $from, $r[1], $r[0], true ) ) {
					$sk = strtolower( $m[1][0] ?? '' );
					$k  = 0 === strpos( $sk, 'port' ) || '' === $sk ? 'gate' : ( 'alarma' === $sk ? 'alarm' : ( in_array( $sk, array( 'caja', 'candado' ), true ) ? 'lockbox' : 'keypad' ) );
					$add( $m[0][1] + ( preg_match( '/^(?:el|la)\s+/i', $m[0][0], $am ) ? strlen( $am[0] ) : 0 ), $r[1], $k, $r[0] );
				}
			}
		}
		// 6d. "they changed the code to 7731", "HOA reset the gate code to 4410", "updated their combo to 5521"
		if ( preg_match_all( '/\b(?:changed|updated|reset|set|switched|moved)\s+(?:the|their|our|his|her|its|it|a)?\s*(?:(?:new|old)\s+)?(?:' . $K . '\s*-?\s*)?(?:codes?|combo|pin|passcode)?\s*(?:over\s+)?(?P<dir>to|from)(?![a-z])' . $ISN . '/i', $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $mm as $m ) {
				if ( ! preg_match( '/\b(?:codes?|combo|pin|passcode|' . substr( self::BARE, 1, -1 ) . ')\b/i', self::sentence( $text, $m[0][1], $m[0][1] + strlen( $m[0][0] ) ) ) ) {
					continue;
				}
				$from = $m[0][1] + strlen( $m[0][0] );
				$r    = self::read_code( $text, $from );
				if ( $r && ! self::not_a_code( $text, $from, $r[1], $r[0], true ) && ! self::looks_like_address( $text, $r[1] ) ) {
					$k = self::near_kind( $text, $from, $r[1] );
					$add( $from, $r[1], $k, $r[0] );
					if ( 'from' === strtolower( $m['dir'][0] ) && preg_match( '/^\s+to\s+/i', substr( $text, $r[1], 12 ), $tm ) ) {
						$r2 = self::read_code( $text, $r[1] + strlen( $tm[0] ) ); // "changed it from 6199 to 6250"
						if ( $r2 && ! self::not_a_code( $text, $r[1] + strlen( $tm[0] ), $r2[1], $r2[0], 'alt' ) ) {
							$add( $r[1] + strlen( $tm[0] ), $r2[1], $k, $r2[0] );
						}
					}
					self::alternates( $text, $r[1], $k, $add );
				}
			}
		}
		// 7. the code first: "8034 is the gate", "2147 for the front gate", "1852# is the gate code", "7586# for the keypad",
		//    "4821 is the code"; only the number is cut ("[gate code sealed] for the front gate"). Never "1450 for the gate
		//    hinges" (a price), "3068 for the front door" (a size), nor a number before one of the trade's words.
		$KR = '(?:gate|entrance|lock\s*-?\s*box|key\s*-?\s*box|key\s*-?\s*safe|key\s*-?\s*pad|keypad|alarm|call\s*-?\s*box|buzzer|intercom|padlock|combo|garage(?:\s+(?:code|keypad|door\s+code))?|door\s+(?:code|keypad|pin)|codes?|pin|passcode)';
		$NUM = '(?<![\w$.,])((?:[A-Za-z]?\d{3,}[0-9#*]*)|(?:(?:\d{1,2}|' . self::SPOKEN_RE . ')(?:[\s.,\-]+(?:\d{1,2}|' . self::SPOKEN_RE . ')){2,15}(?![\s.,\-]+(?:\d|' . self::SPOKEN_RE . '))(?:[\s,]+(?:pound|star|hash))?))';
		if ( preg_match_all( '/' . $NUM . '\s*(?:is\s+(?:the\s+|our\s+|their\s+|for\s+the\s+)?|=\s*(?:the\s+)?|-+\s*|for\s+(?:the\s+)?|at\s+(?:the\s+)?|on\s+(?:the\s+)?|to\s+(?:open\s+)?(?:the\s+)?|opens\s+(?:the\s+)?|works\s+(?:for|on|at)\s+(?:the\s+)?|gets\s+(?:you|us|them)\s+(?:in(?:to)?|through|thru)\s+(?:the\s+)?|then\s+(?:#|\*|pound|star)\s+(?:at|on|for)\s+(?:the\s+)?)(?:(?:front|back|side|main|rear|community|hoa|pool|outer|inner|walk|pedestrian|vehicle|car|new|old|first|second|other)\s+)*' . $KR . '(?:\s+code)?(?![a-z])(?!\s*(?:doors?|install|installation|repair|opener|motor|hardware|latch|hinges?|posts?|panels?|kit|price|quote|estimate|work|job|side' . self::trade_alt() . '))/i', $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $mm as $m ) {
				$r = self::read_code( $text, $m[1][1], (bool) preg_match( '/^[A-Za-z]?\d{3,}/', $m[1][0] ) );
				if ( $r && ! self::not_a_code( $text, $m[1][1], $r[1], $r[0], true ) ) {
					$add( $m[1][1], $r[1], self::near_kind( $text, $r[1], $r[1] ), $r[0] );
				}
			}
		}
		// 8. a verb, then the code: "enter 4821 at the gate", "punch in 1234#", "dial 0142 at the call box", "use *2580 on the
		//    keypad", "disarm with 6020", "type 4821 then pound". Only when the same sentence names a gate, box, keypad, alarm
		//    or code, or says it gets you in ("use 4821 to get in"); only the number is cut.
		if ( preg_match_all( '/\b(?:(?:press|hit|push)\s+(?:call|the\s+call\s+button|#|\*)\s+(?:then|and)|enter|entering|punch(?:\s+in)?|dial|use|using|hit|key\s+in|type(?:\s+in)?|press|push|input|try|disarm(?:\s+(?:it\s+)?with)?|arm\s+with|open\s+(?:it\s+|the\s+(?:\w+\s+){0,2}\w+\s+)?with|(?:get|got|gets|getting)\s+in\s+with)\s+(?:the\s+)?(?:(?:code|combo|pin|passcode)\s*)?[:#]?\s*/i', $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $mm as $m ) {
				$from = $m[0][1] + strlen( $m[0][0] );
				$r    = self::read_code( $text, $from, ! preg_match( '/code|combo|pin|disarm|passcode|punch|enter|dial|key\s+in|type|press/i', $m[0][0] ) );
				if ( ! $r || self::not_a_code( $text, $from, $r[1], $r[0], true ) || self::looks_like_address( $text, $r[1] ) ) {
					continue;
				}
				$sent = self::sentence( $text, $m[0][1], $r[1] );
				if ( preg_match( '/^(?:dial|punch|key\s+in|enter)\b/i', $m[0][0] ) ) {
					$pe   = max( 0, $m[0][1] - strlen( self::sent_before( $text, $m[0][1] ) ) );
					$sent = substr( $text, max( 0, $pe - 120 ), $pe - max( 0, $pe - 120 ) ) . ' ' . $sent; // and the sentence before
				}
				$keyed = preg_match( '/\b(?:enter|punch|key\s+in|dial|type)\b/i', $m[0][0] ) && preg_match( '/^\s+(?:at|on)\s+the\s+(?:\w+\s+)?door\b/i', substr( $text, $r[1], 40 ) );
				if ( $keyed || preg_match( '/[#*]$/', $r[0] ) || preg_match( '/\bcall\b|#|\*/i', $m[0][0] ) || preg_match( '/\b(?:' . self::BARE . '|' . self::BARE_WEAK . '|' . self::BARE_MARK . '|codes?|disarm|arm|get\s+(?:you\s+|us\s+)?in|let\s+(?:you|us|them|yourself)\s+in|to\s+open|opens?|buzz(?:es|ed)?\s+(?:you|us)?\s*in|then\s+(?:#|\*|pound|star|hash))(?![a-z#*])/i', $sent ) ) {
					$add( $from, $r[1], self::near_kind( $text, $from, $r[1] ), $r[0] );
				}
			}
		}
		// 10. an answer on its own line in a chat or a thread -- "whats the gate at the Example job?" /
		//     "#5531 I think"; "Alarm code for the Examples?" / "4 5 6 1 then off"; "el código cambió" / "a cuál?" / "3 6 0 3".
		//     The line holds the code and little else, and one of the four lines before asked about a code (not a time, a
		//     price, an address or a phone).
		if ( false !== strpos( $text, "\n" ) && preg_match_all( '/^[ \t>]*(?:[A-Z][\w.\'-]*(?:[ \t][A-Z][\w.\'-]*)?[ \t]*:[ \t]*)?(?:(?:yes|ya|yeah|yep|ok|okay|sorry|oh|so|um|uh|it\'?s|its|it\s+is|i\s+think|gate|code)[\s,!.]*)*(?=[#*]?\d|(?:zero|oh|one|two|three|four|five|six|seven|eight|nine)\b)/im', $text, $mm, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $mm[0] as $m ) {
				$from = $m[1] + strlen( $m[0] );
				$r    = self::read_code( $text, $from );
				if ( ! $r ) {
					continue;
				}
				$eol  = strpos( $text, "\n", $r[1] );
				$rest = substr( $text, $r[1], ( false === $eol ? strlen( $text ) : $eol ) - $r[1] );
				if ( ! preg_match( '/^\s*(?:#|\*|pound|star)?\s*(?:i\s+think|prob(?:ably)?|too|then\b.*|and\s+then\b.*|\(.*\)|ya|yes|thx|thanks.*|[?!.\s]*)\s*$/i', $rest ) ) {
					continue;
				}
				$lines = preg_split( '/\n/', substr( $text, max( 0, $m[1] - 400 ), $m[1] - max( 0, $m[1] - 400 ) ) );
				array_pop( $lines ); // this line's own start
				$asked = '';
				$seen  = 0;
				for ( $j = count( $lines ) - 1; $j >= 0 && $seen < 4; $j-- ) {
					if ( '' === trim( $lines[ $j ], " \t.>" ) ) {
						continue;
					}
					$seen++;
					if ( preg_match( '/\b(?:cod|codes?|combo|combination|gate|gaet|lock\s*-?\s*b(?:o)?x|lockbx|lockbox|key\s*-?\s*box|keypad|key\s*pad|alarm|pin|passcode|padlock|clave|c(?:o|\xC3\xB3)digo|port(?:o|\xC3\xB3)n|to\s+what|which\s+one|cu(?:a|\xC3\xA1)l)\b/i', $lines[ $j ] ) ) {
						$asked = $lines[ $j ];
						break;
					}
				}
				if ( '' === $asked || preg_match( '/\b(?:what\s+time|when|eta|price|cost|how\s+much|total|address|phone|cell|zip|estimate|invoice|deposit|how\s+many|size|width|height)\b/i', $asked ) ) {
					continue;
				}
				if ( self::not_a_code( $text, $from, $r[1], $r[0], true ) || self::looks_like_address( $text, $r[1] ) ) {
					continue;
				}
				$K = self::kind_re();
				$k = preg_match_all( '/\b' . $K . '(?![a-z])/i', $asked, $km ) ? self::kind( end( $km[1] ) ) : 'gate';
				$add( $from, $r[1], 'door' === $k ? 'keypad' : $k, $r[0] );
			}
		}
		// earliest first, then the longest; drop anything overlapping a span already taken
		usort( $hits, function ( $a, $b ) { return $a[0] === $b[0] ? ( $b[1] <=> $a[1] ) : ( $a[0] <=> $b[0] ); } );
		$sel  = array();
		$last = -1;
		foreach ( $hits as $h ) {
			if ( $h[0] >= $last ) {
				$last  = $h[1];
				$sel[] = $h;
			}
		}
		// one byte per byte of the text: "\1" where a code is already taken, so overlap checks cost the span, not the hit count
		//
		$occ  = str_repeat( "\0", strlen( $text ) + 1 );
		$free = function ( $a, $b ) use ( &$occ ) { return $b > $a && false === strpos( substr( $occ, $a, $b - $a ), "\1" ); };
		$mark = function ( $a, $b ) use ( &$occ ) {
			for ( $i = $a; $i < $b; $i++ ) {
				$occ[ $i ] = "\1"; // in place: a copy of the whole text per code would cost its square
			}
		};
		foreach ( $sel as $h ) {
			$mark( $h[0], $h[1] );
		}
		// 9. the weighed reading. Every number still in the words is scored on the words around it (WEIGH);
		//    at THRESHOLD or more it is a code: "Gate's locked, 4821 gets you through", "HOA gave us 4821 for the front",
		//    "4821 no longer works". Only the number is cut.
		$keep = function ( $a, $b, $k, $c ) use ( &$sel, $free, $mark ) {
			if ( $free( $a, $b ) ) {
				$sel[] = array( $a, $b, self::kind( $k ), $c );
				$mark( $a, $b );
			}
		};
		self::weighed( $text, $occ, function ( $h ) use ( $text, $free, $keep ) {
			if ( $free( $h[0], $h[1] ) ) {
				$keep( $h[0], $h[1], $h[2], $h[3] );
				self::alternates( $text, $h[1], $h[2], $keep ); // taken at once, so rule 9 never weighs them one by one
			}
		} );
		// a code found once is a code wherever its digits appear again in the same sentence ("gate code is 8251 (her
		// text said 8251#)"); never in another sentence, which may be another job's
		// A worklist: each code found here is looked for again around itself, and each stretch of text is read once per code
		// ($cov), so a long run of one code costs the run, not its square.
		$cov = array();
		for ( $qi = 0; $qi < count( $sel ); $qi++ ) {
			$h = $sel[ $qi ];
			$d = preg_replace( '/[^0-9]/', '', (string) $h[3] );
			if ( strlen( $d ) < 3 ) {
				continue;
			}
			$wide  = strlen( $d ) >= 4;
			$sa    = $wide ? max( 0, $h[0] - 300 ) : $h[0] - strlen( self::sent_before( $text, $h[0] ) );
			$sb    = $wide ? min( strlen( $text ), $h[1] + 300 ) : $h[1] + strlen( self::sent_after( $text, $h[1] ) );
			$parts = array( array( $sa, $sb ) );
			$merged = false;
			foreach ( $cov[ $d ] ?? array() as $ci => $c ) {
				if ( $sa < $c[1] && $sb > $c[0] ) { // read only what this stretch adds (12 bytes over, for a code at the seam)
					$parts = array();
					if ( $sa < $c[0] ) {
						$parts[] = array( $sa, min( $sb, $c[0] + 12 ) );
					}
					if ( $sb > $c[1] ) {
						$parts[] = array( max( $sa, $c[1] - 12 ), $sb );
					}
					$cov[ $d ][ $ci ] = array( min( $sa, $c[0] ), max( $sb, $c[1] ) );
					$merged           = true;
					break;
				}
			}
			if ( ! $merged ) {
				$cov[ $d ][] = array( $sa, $sb );
			}
			foreach ( $parts as $pt ) {
				$a0 = max( 0, $pt[0] - 1 );
				if ( ! preg_match_all( '/(?<![\w$.,\/-])[#*]?' . $d . '[#*]?(?![\w\/]|[.,]\d)/', substr( $text, $a0, $pt[1] - $a0 ), $rm, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}
				foreach ( $rm[0] as $x ) {
					$a = $a0 + $x[1];
					$b = $a + strlen( $x[0] );
					$own = $a >= $h[0] - strlen( self::sent_before( $text, $h[0] ) ) && $b <= $h[1] + strlen( self::sent_after( $text, $h[1] ) );
					if ( ! $own && preg_match( '/\d/', self::sent_before( $text, $a ) . ' ' . self::sent_after( $text, $b ) ) ) {
						continue; // in another sentence only when it stands alone there ("yeah that's what she said, 0007"), never
						          // among other numbers ("Sam: installed 2014, 1450 Oak St")
					}
					if ( $a >= $pt[0] && $free( $a, $b ) && ! self::not_a_code( $text, $a, $b, $x[0], $own ? 'alt' : true ) ) {
						$keep( $a, $b, $h[2], ltrim( $x[0], '#' ) );
					}
				}
			}
		}
		usort( $sel, function ( $a, $b ) { return $a[0] === $b[0] ? ( $b[1] <=> $a[1] ) : ( $a[0] <=> $b[0] ); } );
		$out  = array();
		$last = -1;
		foreach ( $sel as $h ) {
			if ( $h[0] < $last ) {
				continue; // never two spans over the same bytes
			}
			// a closing quote of a quoted code goes with it ('Gate code: "4821"')
			$span = substr( $text, $h[0], $h[1] - $h[0] );
			if ( 1 === substr_count( $span, '"' ) % 2 && '"' === substr( $text, $h[1], 1 ) ) {
				$h[1]++;
			}
			// an app drops a "door" span whose words lack code / pin / combo / key (a door size): a code read
			// without those words ("Door: 3006", "garage is 4821") is filed as a keypad, so it is never dropped
			if ( 'door' === $h[2] && ! preg_match( '/code|pin|combo|key/i', substr( $text, $h[0], $h[1] - $h[0] ) ) ) {
				$h[2] = 'keypad';
			}
			$last  = $h[1];
			$out[] = null === $map ? $h : array( $map[ $h[0] ], $map[ $h[1] ], $h[2], $h[3] );
		}
		return $out;
	}

	/** Where a sentence ends: ". ", "!", "?", ";", a new line -- never the full stop of "no.", "St.", "approx.", "e.g.". */
	const SPLIT = '/(?<!\bno)(?<!\bNo)(?<!\bNO)(?<!\bst)(?<!\bSt)(?<!\bapprox)(?<!\be\.g)(?<!\bi\.e)(?<!\bvs)[.!?;\n](?=\s|$)/';

	/** The sentence around [$a, $b): from the last ". " (or ";", "!", "?", a new line) before $a to the next after $b. */
	private static function sentence( $text, $a, $b ) {
		return self::sent_before( $text, $a ) . substr( (string) $text, $a, $b - $a ) . self::sent_after( $text, $b );
	}

	private static function sent_before( $text, $a ) {
		$from = max( 0, $a - 300 ); // a sentence's last 300 bytes are as far back as any rule looks
		$l    = preg_split( self::SPLIT, substr( (string) $text, $from, $a - $from ) );
		return (string) end( $l );
	}

	private static function sent_after( $text, $b ) {
		$r = preg_split( self::SPLIT, substr( (string) $text, $b, 300 ), 2 );
		return (string) $r[0];
	}

	/**
	 * The weights of the weighed reading. Each is [pattern, weight, side] where side is 'L' (the words
	 * before the number, same sentence, nearer counts more), 'R' (after it, same sentence), or 'S' (anywhere in the
	 * sentence). Tuned on a corpus of phrasings weighted by how often each occurs and what a mistake costs. A number
	 * scoring THRESHOLD or more is a code. %T% stands where the trade's own words for things join a list (weigh()).
	 */
	const WEIGH = array(
		array( '/\b(?:lock\s*-?\s*box|key\s*-?\s*(?:box|safe|pad)|keypad|k\s*-?\s*pad|pin\s*-?\s*pad|keyless|smart\s*-?\s*lock|padlock|combo|combination|passcode|supra)\b/i', 3.0, 'L' ),
		array( '/\b(?:gate|call\s*-?\s*box|callbox|buzz(?:er)?|intercom|alarm)\b/i', 2.5, 'L' ),
		array( '/\bcodes?\b|\bpin\b/i', 2.5, 'L' ),
		array( '/\b(?:pool|garage|community|entry|access|security|hoa)\b/i', 1.0, 'L' ),
		array( '/\b(?:enter|punch|dial|use|hit|key\s+in|type|press|input|try|disarm|with)\s*(?:in\s*)?$/i', 1.5, 'L' ),
		array( '/\b(?:it\'?s|it\s+is|ours\s+is|theirs\s+is|new\s+one\s+is)\s*$/i', 1.0, 'L' ),
		array( '/\b(?:(?:she|he|they|customer|cust|owner|hoa|manager|tenant|wife|husband)\s+(?:just\s+)?(?:said|says|texted)|told\s+(?:me|us)|gave\s+(?:me|us)|corrected\s+it)\s*(?:it\'?s|it\s+is)?\s*,?\s*$/i', 1.0, 'L' ),
		array( '/\b(?:[3-8]|three|four|five|six|seven|eight)\s*-?\s*digits?\b/i', 3.0, 'L' ),
		array( '/\b(?:lock\s*-?\s*box|key\s*-?\s*(?:box|safe)|padlock|keypad|key\s*pad|alarm\s+panel)\b[^.;\d]{0,70}[,:\-]\s*$/i', 1.0, 'L' ), // "the lockbox … is on the pantry door, 3690"
		array( '/\b(?:now|changed(?:\s+it)?|new|updated|reset|old|backup|alt(?:ernate)?|or|was)\s*[:#,]?\s*$/i', 1.0, 'L' ),
		array( '/^\s*now\b/i', 1.0, 'R' ),
		array( '/^\s*(?:#|\*|pound|star)/i', 1.5, 'R' ),
		array( '/^[^.;]{0,12}?\b(?:is\s+)?(?:for|at|on|to|opens|gets|works|is)\s+(?:the\s+)?(?:\w+\s+){0,2}(?:gate|lock\s*box|lockbox|lock|keypad|key\s*pad|alarm|call\s*box|code|box|panel|entrance)\b/i', 3.0, 'R' ),
		array( '/^\W{0,3}(?:,\s*)?(?:or|not|if\s+not|if\s+that\s+fails|backup|\(backup|\(alt)\W{0,3}[#*]?\d{3,}/i', 2.0, 'R' ),
		array( '/^\s*(?:garage|gate|lock\s*-?\s*box|keypad|alarm|call\s*box)\b(?!\s+(?:doors?|install|opener|motor|price|dr|drive|rd|road|st|street|way|ln|ct|blvd%T%)\b)/i', 3.0, 'R' ),
		array( '/\b(?:no\s+code|code\s+not\s+needed|no\s+gate\s+code|code\s+(?:is\s+)?on\s+file|will\s+(?:text|send|call)|code\s+tbd|code\s+to\s+follow)\b/i', -3.0, 'S' ),
		array( '/\b(?:get(?:s)?\s+(?:you\s+|us\s+)?(?:in|through)|let(?:s)?\s+(?:you|us|them)\s+in|to\s+open)\b/i', 2.0, 'S' ),
		array( '/^\W{0,3}(?:to\s+get\s+in|gets?\s+(?:you|us)\s+(?:in|through)|to\s+open\s+(?:it|the))\b/i', 1.5, 'R' ),
		array( '/^\W{0,3}(?:no\s+longer\s+works|doesn\'?t\s+work|does\s+not\s+work|didn\'?t\s+work|won\'?t\s+work|isn\'?t\s+working|not\s+working|stopped\s+working|is\s+(?:the\s+)?old\s+(?:one|code))\b/i', 3.0, 'R' ),
		array( '/^[^.;]{0,20}\b(?:gate|lock\s*-?\s*box|keypad|key\s*pad|alarm|codes?|call\s*-?\s*box)\b/i', 1.0, 'R' ),
		array( '/\b(?:french|sliding|patio|install(?:ed)?%T%)\b/i', -2.0, 'S' ),
		array( '/\b(?:price|total|deposit|paid|balance|cost|estimate|invoice|qty|width|height|measure)\b/i', -2.0, 'S' ),
	);
	const THRESHOLD = 3.0;

	/** WEIGH with the trade's words for things joined in where %T% stands. */
	private static function weigh() {
		$t   = self::trade_alt();
		$out = array();
		foreach ( self::WEIGH as $w ) {
			$w[0]  = str_replace( '%T%', $t, $w[0] );
			$out[] = $w;
		}
		return $out;
	}

	/** A spoken digit or number word (rule 7's "two five oh one for the gate code"). */
	const SPOKEN_RE = '(?:zero|oh|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety)\b';

	/** The weighed reading (rule 9): each number not yet taken ($occ) that scores THRESHOLD or more goes to $hit as [start, end, kind, code]. */
	private static function weighed( $text, &$occ, callable $hit ) {
		$out = array();
		if ( ! preg_match_all( '/(?<![\w$#*.,\/:-])(?:[#*]?[A-Za-z]?\d{3,8}[#*]?|(?:\d[\s.,\-]{1,2}){2,7}\d(?![\d\/])|(?:\b(?:zero|oh|one|two|three|four|five|six|seven|eight|nine)\b[\s,\-]*){3,16}(?![a-z]))/i', (string) $text, $mm, PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}
		$weigh = self::weigh();
		foreach ( array_slice( $mm[0], 0, 4000 ) as $m ) {
			$s = $m[1];
			$r = self::read_code( $text, $s );
			if ( ! $r || false !== strpos( substr( $occ, $s, $r[1] - $s ), "\1" ) ) {
				continue;
			}
			if ( self::not_a_code( $text, $s, $r[1], $r[0], true ) || self::looks_like_address( $text, $r[1] ) ) {
				continue;
			}
			$sent  = self::sentence( $text, $s, $r[1] );
			$left  = substr( self::sent_before( $text, $s ), -100 );
			$lo    = strrpos( substr( $occ, $s - strlen( $left ), strlen( $left ) ), "\1" );
			if ( false !== $lo ) { // the words before a code already taken were that code's
				$left = substr( $left, $lo + 1 );
			}
			if ( preg_match( '/,\s*(?:the\s+)?([a-z]+)\s+(?:is|was|=|:)\s*$/i', $left, $cm ) && ! preg_match( '/^(?:codes?|gate|lockbox|keypad|alarm|combo|pin|it|one|box|lock|that|this)$/i', $cm[1] ) ) {
				continue; // "Gate code is on the fridge, house is 1450": the clause is about the house
			}
			$right = substr( self::sent_after( $text, $r[1] ), 0, 60 );
			$nar   = self::not_access_re();
			$left  = preg_replace( '/\b' . $nar . '[\s\-]*codes?\b/i', ' ', (string) $left ); // "fire code 4904" is no access code
			$sent  = preg_replace( '/\b' . $nar . '[\s\-]*codes?\b/i', ' ', (string) $sent );
			$score = 0.0;
			// the sentence before named a code and this one is short ("…the gate code on the invoice? It's 6011")
			$pe   = max( 0, $s - strlen( self::sent_before( $text, $s ) ) );
			$prev = preg_split( self::SPLIT, substr( (string) $text, max( 0, $pe - 300 ), $pe - max( 0, $pe - 300 ) ) );
			$pv   = count( $prev ) > 1 ? (string) $prev[ count( $prev ) - 2 ] : '';
			if ( '' !== $pv && ( strlen( self::sent_before( $text, $s ) ) < 16 || ( strlen( self::sent_before( $text, $s ) ) < 48 && preg_match( '/\b(?:corrected|actually|sorry|no\b|wait|changed|now|it\'?s|it\s+is)/i', self::sent_before( $text, $s ) ) ) ) && preg_match( '/\b(?:codes?|combo|pin|lock\s*-?\s*box|keypad|passcode)\b/i', $pv ) ) {
				$score += 3.0;
			}
			$near = (bool) preg_match( '/\b(?:lock\s*-?\s*box|key\s*-?\s*(?:box|safe)|keysafe|key\s*-?\s*pad|keypad|padlock|combo|pin\s*-?\s*pad|smart\s*-?\s*lock|alarm|call\s*-?\s*box)\b[^.;]{0,70}$/i', (string) $left );
			foreach ( $weigh as $w ) {
				if ( $w[1] < 0 && 'S' === $w[2] && $near && ! preg_match( '/price|total|deposit|paid|balance|cost|estimate|invoice|qty|width|height|measure/i', $w[0] ) ) {
					continue; // "Lockbox on the patio door 4821": the door is where the box is
				}
				if ( 'L' === $w[2] ) {
					if ( preg_match_all( $w[0], (string) $left, $hm, PREG_OFFSET_CAPTURE ) ) {
						$last = end( $hm[0] );
						$dist = strlen( (string) $left ) - $last[1] - strlen( $last[0] );
						$score += $w[1] * ( $dist <= 30 ? 1 : ( $dist <= 60 ? 0.8 : 0.5 ) );
					}
				} elseif ( 'R' === $w[2] ) {
					if ( preg_match( $w[0], (string) $right ) ) {
						$score += $w[1];
					}
				} elseif ( preg_match( $w[0], $sent ) ) {
					$score += $w[1];
				}
			}
			if ( $score >= self::THRESHOLD ) {
				$hit( array( $s, $r[1], self::near_kind( $text, $s, $r[1] ), $r[0] ) ); // marks $occ (scan()'s own)
			}
		}
		return $out;
	}

	/** Where the codes are: [[start, length, kind], ...] (byte offsets in $text). Never the code, nor its length. */
	public static function find( $text ) {
		return array_map( function ( $h ) {
			return array( $h[0], $h[1] - $h[0], $h[2] );
		}, self::scan( $text ) );
	}

	public static function has_code( $text ) {
		return (bool) self::scan( $text );
	}

	/**
	 * Could $text hold a code? A quick check an app may run before find(). False means look no further; true means call
	 * find(). It is exactly the first test scan() makes, on the same plain text, so it can never say no where find()
	 * finds a code: is there a number in it, written ("4821", "7 7 1 9", full-width or look-alike digits) or spoken (any
	 * word in UNITS, TEENS or TENS: "o", "twelve", "forty")? scan() returns nothing without one, and both call the same
	 * has_number(). A word list in front of it would always be narrower somewhere ("HOA 4821 or 4822", "Ingrese 4821
	 * para entrar", "Which one?\n4821", "note_GC 4821" are all codes); the cost is that more text reaches find(), and
	 * find() is bounded (see the class docblock).
	 * ASCII text is read as it is (plain() would change nothing); other text goes through one strtr() over LOOKALIKE,
	 * the table plain() uses (valid UTF-8; plain() itself is slower on a large text), or plain() when it is not.
	 */
	public static function might_have_code( $text ) {
		$text = (string) $text;
		if ( preg_match( '/[0-9]/', $text ) ) {
			return true; // a digit in the raw text is a digit in the plain text too
		}
		if ( preg_match( '/[\x80-\xff]/', $text ) ) {
			$text = function_exists( 'mb_check_encoding' ) && mb_check_encoding( $text, 'UTF-8' ) ? strtr( $text, self::LOOKALIKE ) : self::plain( $text )[0];
		}
		return self::has_number( self::unscore( $text ) );
	}

	/** scan()'s first test, shared with might_have_code(): a digit, or a number word as a code may be said. */
	private static function has_number( $text ) {
		static $re = null;
		if ( null === $re ) {
			$re = '/\d|\b(?:' . implode( '|', array_merge( array_keys( self::UNITS ), array_keys( self::TEENS ), array_keys( self::TENS ) ) ) . ')\b/i';
		}
		return (bool) preg_match( $re, (string) $text );
	}

	/** "gate_code: 4821", "four_five_six_seven": a record's field name; "_" between letters reads as a space (same length). */
	private static function unscore( $text ) {
		return (string) preg_replace( '/(?<=[A-Za-z])_(?=[A-Za-z])/', ' ', (string) $text );
	}


	/**
	 * $text with every code cut out: ['text' => …, 'ids' => [id, …], 'kept' => n, 'lost' => n]. With a scope each code
	 * is sealed first (ids in order; 0 where it could not be kept, counted as lost). Without one, nothing is stored.
	 * Either way the words keep only the label: a code that could not be kept is never left in the text.
	 */
	public static function cut_ids( $text, $scope = null ) {
		$text = (string) $text;
		$hits = self::scan( $text );
		$ids  = array();
		$kept = 0;
		$lost = 0;
		$sc   = null === $scope ? null : self::scope( $scope );
		foreach ( array_reverse( $hits ) as $h ) {
			if ( null !== $sc ) {
				$id = self::seal( $h[2], $h[3], $sc );
				array_unshift( $ids, $id );
				$id ? $kept++ : $lost++;
			}
			$text = substr( $text, 0, $h[0] ) . self::label( $h[2] ) . substr( $text, $h[1] );
		}
		return array( 'text' => $text, 'ids' => $ids, 'kept' => $kept, 'lost' => $lost + ( null !== $scope && null === $sc ? count( $hits ) : 0 ) );
	}

	public static function cut( $text, $scope = null ) {
		return self::cut_ids( $text, $scope )['text'];
	}

	/* ------------------------------------------------------------------ "Show code": the one way out */

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 12 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ), 12 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/** POST /zorderz/v1/sealed/<id>/open: open() as the signed-in person. The apps' "Show code" buttons call this. */
	public static function routes() {
		register_rest_route( ZDZ_REST_NS, '/sealed/(?P<id>\d+)/open', array(
			'methods'             => 'POST',
			'permission_callback' => function () { return is_user_logged_in(); },
			'callback'            => array( __CLASS__, 'rest_open' ),
		) );
	}

	public static function rest_open( $req ) {
		$id   = (int) $req['id'];
		$uid  = (int) get_current_user_id();
		$code = self::open( $id, $uid );
		if ( null === $code ) {
			$why = self::why_not( $id, $uid );
			if ( 'no_such' === $why && ! self::is_admin( $uid ) ) {
				$why = 'not_allowed'; // whether an id exists is not told to someone who may not open it
			}
			$says = array(
				'no_such'     => 'There is no such code.',
				'kiosk'       => 'Codes never open on the shared account.',
				'not_allowed' => 'Only an administrator, or someone the app that keeps this code allows (such as the person assigned the work that day), can open this code.',
				'locked'      => self::LOCKED_SAYS,
				'no_salts'    => 'Codes can\'t be opened on this site: its secret keys are not set in wp-config.php. Tell an administrator.',
				'bad_constant' => 'Codes can\'t be opened on this site: ZDZ_SEALED_KEY in wp-config.php is not a recovery code. Tell an administrator.',
				'no_sodium'   => 'Codes can\'t be opened on this server (no libsodium). Tell an administrator.',
				''            => 'This code could not be opened here (the site\'s keys changed, or the opening could not be recorded). Try again, or ask for the code again.',
			);
			return new WP_Error( 'zdz_sealed_refused', $says[ $why ] ?? 'Not allowed.', array( 'status' => 'no_such' === $why ? 404 : 403 ) );
		}
		$res = rest_ensure_response( array( 'code' => $code ) );
		if ( is_object( $res ) && method_exists( $res, 'header' ) ) {
			$res->header( 'Cache-Control', 'no-store, private' );
		}
		return $res;
	}
}
