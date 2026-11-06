<?php
/**
 * ZRP_Codes -- sealed gate codes for gated communities.
 *
 * A code known for an area is stored securely and user-gated inside the app: never printed in plain text on a
 * document or a public page. Who may see one: the admin tier, and the person assigned a Doorhangers route through
 * that area, on that route's day.
 *
 *   Sealed     into the theme's vault (ZDZ_Sealed) under its street, ['street', '<street key>|<ZIP>'] (a code with no
 *              street or ZIP under 'nowhere', which only administrators open); this table keeps its place, label and
 *              source with vault_id and `sealed` left empty. Opening goes through ZDZ_Sealed::open(), which logs it;
 *              who may is this class's rule, given to the vault as a zdz_sealed_may_open filter (may_open()). "Today"
 *              is the business's own day.
 *   Without    the vault, a code is sealed in this app's own box (libsodium secretbox; the key is derived from the
 *   the vault  site's SECURE_AUTH salt in wp-config.php, not the database, so a copied database alone cannot open
 *              it). Without libsodium it is not kept at all, and the words say so. Nothing is ever kept in plain text:
 *              what the vault's wider reader would have found is cut by Doorhangers' fallback reader instead.
 *   Captured   from what a driver says ("the gate code is 4 5 6 7, pound") before the note's words are kept: the code
 *              is sealed and the note keeps "[gate code sealed]". Or typed on the Review list.
 *   Area       the street segment it was said on (street key + ZIP) and its point; a route through that street that
 *              day is what lets its walker open it.
 *   Opened     only by POST route-planner/codes/<id>/reveal, one at a time, each opening logged (who, when, on what basis).
 *              Never in a list, a note, a verb, an export or a print.
 * Codes kept in this app's own box move into the vault from Zorderz -> Route Planner (Gate codes): the screen counts
 * them first, then a press moves them, each through ZDZ_Sealed::seal() (move()). Drive notes still holding a code the
 * app's own reader missed are found and sealed the same way (count_notes(), seal_notes()). Nothing moves by itself.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZRP_Codes {

	const RADIUS_M = 300;
	const KINDS    = array( 'gate', 'access', 'entry', 'lockbox', 'keypad', 'community', 'pool', 'garage', 'door', 'key box', 'keybox', 'lock box', 'callbox', 'call box' );

	public static function t_codes() { global $wpdb; return $wpdb->prefix . 'zrp_codes'; }
	public static function t_views() { global $wpdb; return $wpdb->prefix . 'zrp_code_views'; }

	public static function create_tables() {
		global $wpdb;
		$c = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::t_codes() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			label varchar(80) NOT NULL DEFAULT '',
			kind varchar(20) NOT NULL DEFAULT 'gate',
			street varchar(120) NOT NULL DEFAULT '',
			street_key varchar(120) NOT NULL DEFAULT '',
			zip varchar(5) NOT NULL DEFAULT '',
			lat decimal(10,7) NULL,
			lng decimal(10,7) NULL,
			sealed text NOT NULL,
			digits tinyint unsigned NOT NULL DEFAULT 0,
			source varchar(10) NOT NULL DEFAULT 'typed',
			note_id bigint(20) unsigned NULL,
			vault_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NULL,
			PRIMARY KEY  (id),
			KEY street (street_key, zip)
		) $c;" );
		dbDelta( 'CREATE TABLE ' . self::t_views() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			basis varchar(60) NOT NULL DEFAULT '',
			at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY code (code_id)
		) $c;" );
	}

	/* ------------------------------------------------------------------ sealing */

	public static function ready() {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'wp_salt' );
	}

	private static function key() {
		return sodium_crypto_generichash( 'zrp-codes-v1|' . wp_salt( 'secure_auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	public static function seal( $plain ) {
		if ( ! self::ready() ) {
			return null;
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$key   = self::key();
		$out   = 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( (string) $plain, $nonce, $key ) );
		sodium_memzero( $key );
		return $out;
	}

	/** The code, or null when it cannot be opened (no sodium, a damaged row, or the site's salts changed). */
	public static function open( $sealed ) {
		if ( ! self::ready() || 0 !== strpos( (string) $sealed, 'v1:' ) ) {
			return null;
		}
		$raw = base64_decode( substr( (string) $sealed, 3 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$key   = self::key();
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
		sodium_memzero( $key );
		return false === $plain ? null : $plain;
	}

	/* ------------------------------------------------------------------ hearing a code */

	/** Number words as a code may be said ("four five six seven", "twelve thirty four", "oh three five five"). */
	const UNITS = array( 'zero' => 0, 'oh' => 0, 'o' => 0, 'one' => 1, 'won' => 1, 'two' => 2, 'to' => 2, 'too' => 2, 'three' => 3, 'four' => 4, 'for' => 4,
		'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'ate' => 8, 'nine' => 9 );
	const TEENS = array( 'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19 );
	const TENS  = array( 'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90 );
	const MARKS = array( 'pound' => '#', 'hash' => '#', 'hashtag' => '#', 'number' => '', 'star' => '*', 'asterisk' => '*' );

	/** The words that may make up a code, for the patterns below. */
	public static function token_re() {
		$w = array_merge( array_keys( self::UNITS ), array_keys( self::TEENS ), array_keys( self::TENS ), array( 'pound', 'hash', 'hashtag', 'star', 'asterisk' ) );
		return '(?:[0-9#*]+|' . implode( '|', $w ) . ')(?![a-z0-9])';
	}

	/**
	 * A code as it may be said: digits, spoken digits, teens and tens ("twelve thirty four" = 1234), # / pound,
	 * * / star. no stray letters ("4321 a nice area" is 4321), and "oh" / "o" count as 0 only between
	 * numbers ("1234 oh and a pool" is 1234). Null if under 3 digits.
	 */
	public static function normalize_code( $s ) {
		$toks = array_values( array_filter( preg_split( '/[\s,.-]+/', strtolower( trim( (string) $s ) ) ), 'strlen' ) );
		$out  = '';
		$n    = count( $toks );
		for ( $i = 0; $i < $n; $i++ ) {
			$w = $toks[ $i ];
			if ( preg_match( '/^[0-9#*]+$/', $w ) ) { $out .= $w; continue; }
			if ( isset( self::MARKS[ $w ] ) ) { if ( '' === $out ) { if ( '' === self::MARKS[ $w ] ) { continue; } } $out .= self::MARKS[ $w ]; continue; }
			if ( isset( self::TEENS[ $w ] ) ) { $out .= (string) self::TEENS[ $w ]; continue; }
			if ( isset( self::TENS[ $w ] ) ) {
				$next = $toks[ $i + 1 ] ?? '';
				if ( isset( self::UNITS[ $next ] ) && self::UNITS[ $next ] > 0 && ! in_array( $next, array( 'to', 'too', 'for', 'won', 'ate' ), true ) ) { $out .= (string) ( self::TENS[ $w ] + self::UNITS[ $next ] ); $i++; }
				else { $out .= (string) self::TENS[ $w ]; }
				continue;
			}
			if ( isset( self::UNITS[ $w ] ) ) {
				if ( in_array( $w, array( 'oh', 'o' ), true ) ) {
					$next = $toks[ $i + 1 ] ?? '';
					if ( ! ( preg_match( '/^[0-9]+$/', $next ) || isset( self::UNITS[ $next ] ) || isset( self::TEENS[ $next ] ) || isset( self::TENS[ $next ] ) ) ) { break; } // "oh three five five": a leading 0
				}
				$out .= (string) self::UNITS[ $w ];
				continue;
			}
			break; // the code ended
		}
		$out = rtrim( $out, ' ' );
		return ( strlen( preg_replace( '/[^0-9]/', '', $out ) ) >= 3 && strlen( $out ) <= 16 ) ? $out : null;
	}

	/** The patterns a code is said in; group 1 the kind (or ''), group 2 the code. */
	private static function patterns() {
		$k = '(' . implode( '|', array_map( function ( $x ) { return preg_quote( $x, '/' ); }, self::KINDS ) ) . ')';
		$t = self::token_re();
		$run = '(' . $t . '(?:[\s,.-]+' . $t . ')*)';
		$is  = '[\s,:;.-]*(?:(?:is|was|it\'s|its|=|:|of|number|reads)[\s,:;.-]*)*';
		return array(
			'/\b' . $k . '\s+code' . $is . $run . '/i',                                         // gate code is 4567 / gate code, 4567
			'/\b(?:the\s+)?code\s+(?:at|for|to|on|of)\s+(?:the\s+)?' . $k . $is . $run . '/i',  // code for the gate is 2468
			'/\bcode' . $is . $run . '\s+(?:at|for|to|on)\s+(?:the\s+)?' . $k . '\b/i',       // the code is 4567 for the gate
		);
	}

	/**
	 * Every code in what was said: [[kind, code], ...] and the words without them, or null when there is none.
	 * "gate code is 4567", "the lockbox code was one two three four", "access code: 1234 pound", "code for the gate is
	 * 2468", "the code is 4567 for the gate", "gate code 1234 and the pool code is 5678" (both).
	 */
	public static function extract_all( $said ) {
		$text  = (string) $said;
		$found = array();
		for ( $guard = 0; $guard < 6; $guard++ ) {
			$hit = null;
			foreach ( self::patterns() as $pi => $re ) {
				if ( preg_match( $re, $text, $m, PREG_OFFSET_CAPTURE ) ) {
					$kg = 2 === $pi ? 2 : 1; $cg = 2 === $pi ? 1 : 2;
					$code = self::normalize_code( $m[ $cg ][0] );
					if ( null === $code ) { continue; }
					if ( null === $hit || $m[0][1] < $hit[0] ) { $hit = array( $m[0][1], strlen( $m[0][0] ), strtolower( $m[ $kg ][0] ), $code ); }
				}
			}
			if ( null === $hit ) { break; }
			$kind = preg_replace( '/\s+/', '', $hit[2] );
			$found[] = array( substr( $kind, 0, 20 ), $hit[3] );
			$text = substr( $text, 0, $hit[0] ) . '[' . $hit[2] . ' code sealed]' . substr( $text, $hit[0] + $hit[1] );
		}
		return $found ? array( $found, trim( $text ) ) : null;
	}

	/** The first code: [kind, code, the words without ANY code] or null. */
	public static function extract( $said ) {
		$r = self::extract_all( $said );
		return $r ? array( $r[0][0][0], $r[0][0][1], $r[1] ) : null;
	}

	/** Words that END by leading into a code ("... the gate code is"), so the next sentence carries the digits. */
	public static function leads_in( $said ) {
		$k = implode( '|', array_map( function ( $x ) { return preg_quote( $x, '/' ); }, self::KINDS ) );
		return (bool) preg_match( '/\b(?:(?:' . $k . ')\s+code|code\s+(?:at|for|to|on)\s+(?:the\s+)?(?:' . $k . '))[\s,:;.-]*(?:(?:is|was|it\'s|its|=|:)[\s,:;.-]*)*$/i', trim( (string) $said ) );
	}

	/* ------------------------------------------------------------------ storing */

	/* ------------------------------------------------------------------ the theme's vault */

	public static function vault_on() {
		if ( function_exists( 'apply_filters' ) && false === apply_filters( 'zdh_vault_on', true ) ) {
			return false; // the site turned the vault off for canvassing: codes are cut, never kept in the vault
		}
		return class_exists( 'ZDZ_Sealed' ) && method_exists( 'ZDZ_Sealed', 'seal' ) && ZDZ_Sealed::ready();
	}

	private static function on() {
		return self::vault_on();
	}

	const INVISIBLE = '/[\x{00AD}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u';

	/** Strings worth scanning (quick()): every rule of the vault's detector needs one of these. */
	const WORDS = '/code|gate|lock|key|combo|buzz|call\s*-?\s*box|alarm|door|garage|pool|community|entry|access|pin/i';

	/** A digit, a spoken digit or a full-width digit: without one there is no code to find. */
	const DIGITS = '/\d|\xEF\xBC[\x90-\x99]|\b(?:zero|oh|o|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety)\b/i';

	/** The old quick check (WORDS, zero-width marks read through). */
	private static function words( string $text ): bool {
		$plain = preg_replace( self::INVISIBLE, '', $text );
		return (bool) preg_match( self::WORDS, null === $plain ? $text : $plain );
	}

	/**
	 * Could $text hold a code? The word list, OR the vault's own quick check (ZDZ_Sealed::might_have_code()) for text
	 * with a digit in it. Either is enough: nothing the old list reached is skipped ("Pinpad 4821",
	 * "Pins: 4821"), and the theme adds the words its newer rules read ("GC 4821", "el porton: 5820",
	 * "then 2208#", "disarm is 1158"). The theme's check runs only on text with a digit and none of the old words, so
	 * long text without one costs what it did. find() still decides what is a code.
	 */
	private static function quick( string $text ): bool {
		if ( self::words( $text ) ) {
			return true;
		}
		$plain = preg_replace( self::INVISIBLE, '', $text ); // "s\u200Bix s\u200Bix ...": the theme reads through the mark; so must this
		if ( ! preg_match( self::DIGITS, null === $plain ? $text : $plain ) ) {
			return false;
		}
		return class_exists( 'ZDZ_Sealed' ) && method_exists( 'ZDZ_Sealed', 'might_have_code' ) && (bool) ZDZ_Sealed::might_have_code( $text );
	}

	/**
	 * The codes the theme's reader finds, less door sizes ("screen door #3080", "French door no. 6080" are a door's size,
	 * not a code): a "door" match counts only when its words say code, pin, combo or key.
	 * Zero-width marks are read through before the quick word check, as the theme's reader does. [[start, len, kind, words]]
	 */
	public static function spans( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return array();
		}
		if ( ! self::on() ) {
			// fail closed: without the vault, the conservative reader cuts what looks like a code (nothing is kept)
			return class_exists( 'ZDH_Sealed' ) && method_exists( 'ZDH_Sealed', 'fallback_spans' ) ? ZDH_Sealed::fallback_spans( $text ) : array();
		}
		if ( ! self::quick( $text ) ) {
			return array();
		}
		$out = array();
		foreach ( ZDZ_Sealed::find( $text ) as $h ) {
			$w = substr( $text, (int) $h[0], (int) $h[1] );
			if ( 'door' === $h[2] && ! preg_match( '/code|pin|combo|key/i', $w ) ) {
				continue;
			}
			$out[] = array( (int) $h[0], (int) $h[1], (string) $h[2], $w );
		}
		return $out;
	}

	public static function relabel( $text, array $spans ) {
		$kept = self::on();
		foreach ( array_reverse( $spans ) as $sp ) {
			$label = $kept ? ZDZ_Sealed::label( $sp[2] ) : ( class_exists( 'ZDH_Sealed' ) ? ZDH_Sealed::label( $sp[2], false ) : '[code removed]' );
			$text  = substr( $text, 0, $sp[0] ) . $label . substr( $text, $sp[0] + $sp[1] );
		}
		return $text;
	}

	/** One code into the vault: a bare code ("4822") by itself, else the words around it. Its id, or 0. */
	public static function keep_id( array $sp, array $scope ) {
		$id = (int) ZDZ_Sealed::seal( $sp[2], $sp[3], $scope );
		if ( $id > 0 ) {
			return $id;
		}
		$r = ZDZ_Sealed::cut_ids( $sp[3], $scope );
		return (int) ( $r['ids'][0] ?? 0 );
	}

	/** Words with every code cut (nothing stored): words taken back, answers that leave. */
	public static function cut( $text ) {
		$sp = self::spans( $text );
		return $sp ? self::relabel( (string) $text, $sp ) : $text;
	}


	/** The vault scope of a code's place: its street and ZIP, or 'nowhere' (only administrators open those). */
	public static function vault_scope( $street_key, $zip ) {
		$street_key = (string) $street_key;
		$zip        = preg_replace( '/[^0-9]/', '', (string) $zip );
		return ( '' !== $street_key && 5 === strlen( $zip ) && strlen( $street_key ) <= 110 ) ? array( 'street', $street_key . '|' . $zip ) : array( 'street', 'nowhere' );
	}

	/** The vault kind for one of this app's kind words ("community", "key box"). */
	private static function vault_kind( $kind ) {
		return class_exists( 'ZDZ_Sealed' ) ? ZDZ_Sealed::kind( (string) $kind ) : 'gate';
	}

	/**
	 * zdz_sealed_may_open for a street's code: the walker of a Doorhangers route through that street today. The admin
	 * tier and the kiosk are decided by the theme first.
	 */
	public static function may_open( $ok, $row, $uid ) {
		if ( true === $ok || ( is_string( $ok ) && '' !== trim( $ok ) ) ) {
			return $ok;
		}
		if ( ! is_array( $row ) || 'street' !== (string) ( $row['scope_type'] ?? '' ) || ! preg_match( '/^(.+)\|(\d{5})$/', (string) ( $row['scope_id'] ?? '' ), $m ) ) {
			return $ok;
		}
		$b = self::assigned_route( array( 'street_key' => $m[1], 'zip' => $m[2] ), (int) $uid );
		return '' !== $b ? $b : $ok;
	}

	/** Codes sealed in this app's own box that are not in the vault yet. */
	public static function count_unmoved() {
		global $wpdb;
		if ( ! self::has_vault_column() ) {
			return array( 'rows' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t_codes() . " WHERE sealed <> ''" ), 'codes' => 0, 'partial' => false ); // phpcs:ignore
		}
		$n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t_codes() . " WHERE vault_id = 0 AND sealed <> ''" ); // phpcs:ignore
		return array( 'rows' => $n, 'codes' => $n, 'partial' => false );
	}

	/**
	 * Move this app's own sealed codes into the vault (Zorderz -> Route Planner, after the count): open each with this
	 * app's key, seal it into ZDZ_Sealed under its street, then keep vault_id and empty `sealed`. A code that will not
	 * open (the site's salts changed) is left as it was and counted as not kept. At most 500 a press, and only until
	 * $until (microtime); `partial` says another press carries on.
	 */
	public static function move( $until ) {
		global $wpdb;
		$out = array( 'rows' => 0, 'kept' => 0, 'lost' => 0, 'skipped' => 0, 'partial' => false );
		if ( ! self::vault_on() || ! self::ready() || ! self::has_vault_column() ) {
			return $out;
		}
		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . self::t_codes() . " WHERE vault_id = 0 AND sealed <> '' ORDER BY id LIMIT 500", ARRAY_A ); // phpcs:ignore
		$out['partial'] = 500 === count( $rows );   // more than one batch: the next press carries on
		foreach ( $rows as $c ) {
			if ( microtime( true ) > (float) $until ) {
				$out['partial'] = true;
				break;
			}
			$plain = self::open( (string) $c['sealed'] );
			if ( null === $plain ) {
				$out['lost']++;
				continue;
			}
			$vid = ZDZ_Sealed::seal( self::vault_kind( $c['kind'] ), $plain, self::vault_scope( $c['street_key'], $c['zip'] ) );
			if ( ! $vid ) {
				$out['lost']++;
				continue;
			}
			$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t_codes() . " SET vault_id = %d, sealed = '', updated_at = %s WHERE id = %d AND vault_id = 0", $vid, gmdate( 'Y-m-d H:i:s' ), (int) $c['id'] ) ); // phpcs:ignore
			if ( 1 === (int) $n ) {
				$out['rows']++;
				$out['kept']++;
			} else {
				$out['skipped']++;
			}
		}
		return $out;
	}

	public static function has_vault_column() {
		static $has = null;
		if ( null === $has ) {
			global $wpdb;
			$has = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . self::t_codes() . ' LIKE %s', 'vault_id' ) ); // phpcs:ignore
		}
		return $has;
	}

	/** A drive note's words still holding a code this app's own reader missed: count them (dry run). */
	public static function count_notes() {
		global $wpdb;
		$rows  = 0;
		$codes = 0;
		foreach ( (array) $wpdb->get_results( 'SELECT id, said FROM ' . ZRP_DB::t_notes() . " WHERE said REGEXP 'code|gate|lock|key|combo|buzz|box|alarm|door|garage|pool|community|entry|access|pin' ORDER BY id LIMIT 5000", ARRAY_A ) as $n ) { // phpcs:ignore
			$c = count( self::spans( (string) $n['said'] ) );
			if ( $c ) {
				$rows++;
				$codes += $c;
			}
		}
		return array( 'rows' => $rows, 'codes' => $codes, 'partial' => false );
	}

	/**
	 * Seal them: the note keeps "[gate code sealed]" (written only if it still says what was read), then each code goes
	 * into the vault under the note's street, with a row on the Gate codes screen; a note taken back is only cut.
	 */
	public static function seal_notes( $until ) {
		global $wpdb;
		$out = array( 'rows' => 0, 'kept' => 0, 'lost' => 0, 'skipped' => 0, 'partial' => false );
		if ( ! self::vault_on() || ! self::has_vault_column() ) {
			return $out;
		}
		$t = ZRP_DB::t_notes();
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$t} WHERE said REGEXP 'code|gate|lock|key|combo|buzz|box|alarm|door|garage|pool|community|entry|access|pin' ORDER BY id LIMIT 5000", ARRAY_A ) as $n ) { // phpcs:ignore
			if ( microtime( true ) > (float) $until ) {
				$out['partial'] = true;
				break;
			}
			$sp = self::spans( (string) $n['said'] );
			if ( ! $sp ) {
				continue;
			}
			$new = self::relabel( (string) $n['said'], $sp );
			if ( 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET said = %s WHERE id = %d AND said = %s", $new, (int) $n['id'], (string) $n['said'] ) ) ) { // phpcs:ignore
				$out['skipped']++;
				continue;
			}
			$out['rows']++;
			if ( 'retracted' === (string) ( $n['status'] ?? '' ) ) {
				continue;   // words taken back: never kept
			}
			foreach ( $sp as $one ) {
				$vid = self::keep_id( $one, self::vault_scope( $n['street_key'] ?? '', $n['zip'] ?? '' ) );
				$inf = $vid ? ZDZ_Sealed::info( $vid, (int) ( $n['user_id'] ?? 0 ) ) : null;
				if ( ! $inf ) {
					$out['lost']++;
					continue;
				}
				$out['kept']++;
				$wpdb->insert( self::t_codes(), array( 'label' => mb_substr( (string) ( $n['street'] ?? '' ), 0, 80 ), 'kind' => (string) $inf['kind'], 'street' => (string) ( $n['street'] ?? '' ),
					'street_key' => (string) ( $n['street_key'] ?? '' ), 'zip' => (string) ( $n['zip'] ?? '' ), 'lat' => $n['lat'] ?? null, 'lng' => $n['lng'] ?? null, 'sealed' => '', 'digits' => 0,
					'source' => 'voice', 'note_id' => (int) $n['id'], 'vault_id' => (int) $vid, 'created_by' => (int) ( $n['user_id'] ?? 0 ), 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
			}
		}
		return $out;
	}

	/**
	 * One press on Zorderz -> Route Planner (Gate codes): 'move' (own-box codes into the vault) or 'notes' (codes left in
	 * drive notes, sealed under their street). Unknown: nothing runs.
	 *
	 * @return array{rows:int,kept:int,lost:int,skipped:int,partial:bool}
	 */
	public static function admin_run( $what, $until ) {
		if ( 'move' === $what ) {
			return self::move( $until );
		}
		if ( 'notes' === $what ) {
			return self::seal_notes( $until );
		}
		return array( 'rows' => 0, 'kept' => 0, 'lost' => 0, 'skipped' => 0, 'partial' => false );
	}

	/** PURE: what one press did, in plain words. */
	public static function result_words( array $out, $what ) {
		$kept    = (int) ( $out['kept'] ?? 0 );
		$lost    = (int) ( $out['lost'] ?? 0 );
		$skipped = (int) ( $out['skipped'] ?? 0 );
		$parts   = array();
		$parts[] = 'move' === $what
			/* translators: %d: codes moved into the vault */
			? sprintf( _n( '%d code moved into the vault.', '%d codes moved into the vault.', $kept, 'zorderz' ), $kept )
			/* translators: %d: codes sealed from drive notes */
			: sprintf( _n( '%d code from drive notes sealed.', '%d codes from drive notes sealed.', $kept, 'zorderz' ), $kept );
		if ( $lost > 0 ) {
			$parts[] = 'move' === $what
				/* translators: %d: codes that could not be opened or sealed */
				? sprintf( _n( '%d could not be opened with this site\'s keys and stays where it was.', '%d could not be opened with this site\'s keys and stay where they were.', $lost, 'zorderz' ), $lost )
				/* translators: %d: codes the vault did not keep */
				: sprintf( _n( '%d was cut from its note but the vault did not keep it.', '%d were cut from their notes but the vault did not keep them.', $lost, 'zorderz' ), $lost );
		}
		if ( $skipped > 0 ) {
			/* translators: %d: rows someone changed meanwhile */
			$parts[] = sprintf( _n( '%d changed meanwhile and was left alone.', '%d changed meanwhile and were left alone.', $skipped, 'zorderz' ), $skipped );
		}
		if ( ! empty( $out['partial'] ) ) {
			$parts[] = __( 'There are more: press again to carry on.', 'zorderz' );
		}
		return implode( ' ', $parts );
	}

	public static function boot() {
		add_filter( 'zdz_sealed_may_open', array( __CLASS__, 'may_open' ), 10, 3 );
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'guard_verbs' ), 1000 );
	}

	/** Every string in an answer, any depth, with its codes cut (the vault's reader). */
	public static function deep( $v ) {
		if ( is_string( $v ) ) {
			return self::cut( $v );
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				if ( is_string( $x ) || is_array( $x ) ) {
					$v[ $k ] = self::deep( $x );
				}
			}
		}
		return $v;
	}

	/** every Route Planner verb answers with codes cut (registered last, so it wraps what this app registered). */
	public static function guard_verbs( $caps ) {
		if ( ! is_array( $caps ) ) {
			return $caps;
		}
		foreach ( $caps as $verb => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( array( 'callable', 'callback' ) as $k ) {
				$cb = $row[ $k ] ?? null;
				if ( is_array( $cb ) && isset( $cb[0] ) && is_string( $cb[0] ) && 0 === strpos( $cb[0], 'ZRP_' ) && is_callable( $cb ) ) {
					$caps[ $verb ][ $k ] = function ( $args = array() ) use ( $cb ) {
						return ZRP_Codes::deep( call_user_func( $cb, $args ) );
					};
				}
			}
		}
		return $caps;
	}

	/** The code, for someone who may open it now (the caller checked): from the vault, or this app's own box. */
	public static function reveal_plain( array $c, $uid ) {
		if ( (int) ( $c['vault_id'] ?? 0 ) > 0 ) {
			return self::vault_on() ? ZDZ_Sealed::open( (int) $c['vault_id'], (int) $uid ) : null;   // logged by the vault
		}
		return self::open( (string) $c['sealed'] );
	}

	public static function add( array $a ) {
		global $wpdb;
		// into the theme's vault; this app's own box only when the vault is not here
		$vid = 0;
		if ( self::vault_on() && self::has_vault_column() ) {
			$vid = (int) ZDZ_Sealed::seal( self::vault_kind( $a['kind'] ?? 'gate' ), (string) $a['code'], self::vault_scope( $a['street_key'] ?? '', $a['zip'] ?? '' ) );
		}
		$sealed = $vid > 0 ? '' : self::seal( (string) $a['code'] );
		if ( null === $sealed ) {
			return 0;
		}
		$row = array(
			'label'      => mb_substr( sanitize_text_field( (string) ( $a['label'] ?? '' ) ), 0, 80 ),
			'kind'       => substr( preg_replace( '/[^a-z ]/', '', strtolower( (string) ( $a['kind'] ?? 'gate' ) ) ), 0, 20 ) ?: 'gate',
			'street'     => mb_substr( (string) ( $a['street'] ?? '' ), 0, 120 ),
			'street_key' => mb_substr( (string) ( $a['street_key'] ?? '' ), 0, 120 ),
			'zip'        => substr( preg_replace( '/[^0-9]/', '', (string) ( $a['zip'] ?? '' ) ), 0, 5 ),
			'lat'        => isset( $a['lat'] ) && is_numeric( $a['lat'] ) ? round( (float) $a['lat'], 7 ) : null,
			'lng'        => isset( $a['lng'] ) && is_numeric( $a['lng'] ) ? round( (float) $a['lng'], 7 ) : null,
			'sealed'     => $sealed,
			'digits'     => min( 99, strlen( (string) $a['code'] ) ),
			'source'     => in_array( $a['source'] ?? '', array( 'voice', 'typed', 'audio' ), true ) ? $a['source'] : 'typed',
			'note_id'    => isset( $a['note_id'] ) ? (int) $a['note_id'] : null,
			'vault_id'   => $vid,
			'created_by' => (int) $a['created_by'],
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		if ( ! self::has_vault_column() ) {
			unset( $row['vault_id'] );
		}
		$ok = $wpdb->insert( self::t_codes(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function get( $id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t_codes() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_codes() ($wpdb->prefix and a fixed name); values are placeholders
		return is_array( $r ) ? $r : null;
	}

	/* ------------------------------------------------------------------ who may open one */

	/**
	 * '' when $uid may open code $c now, else the reason. The admin tier: always. Anyone else: a Doorhangers route
	 * assigned to them (doorhangers.list, asked as them), planned or in progress, due or planned for today on the site's
	 * clock, in the code's ZIP and naming the code's street.
	 */
	public static function why_not( array $c, $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 || zrp_is_kiosk( $uid ) ) {
			return 'no';
		}
		if ( (int) ( $c['vault_id'] ?? 0 ) > 0 && self::vault_on() ) {
			return '' === ZDZ_Sealed::why_not( (int) $c['vault_id'], $uid ) ? '' : 'not_assigned';   // the vault decides (this class's rule is its filter)
		}
		if ( zrp_user_is_admin( $uid ) ) {
			return '';
		}
		$basis = self::assigned_route( $c, $uid );
		return $basis ? '' : 'not_assigned';
	}

	/** "route #12" when $uid walks a route through the code's street today, else ''. */
	public static function assigned_route( array $c, $uid ) {
		// a code with no ZIP opens for the admin tier only (a same-named street elsewhere is not it)
		if ( '' === (string) $c['street_key'] || '' === (string) $c['zip'] ) {
			return '';
		}
		foreach ( self::routes_today( $uid ) as $rt ) {
			if ( (string) ( $rt['zip'] ?? '' ) !== (string) $c['zip'] ) {
				continue;
			}
			foreach ( preg_split( '/\s*\/\s*|\s*,\s*/', (string) ( $rt['streets'] ?? '' ) ) as $name ) {
				$name = trim( preg_replace( '/\s*(?:\(.*\)|\b\d+\s*[-\x{2013}]\s*\d+\b|#\d+)\s*$/u', '', (string) $name ) ); // "Elm St 100-200", "Elm St (12 homes)"
				if ( '' !== $name && zrp_street_key( $name ) === (string) $c['street_key'] ) {
					return 'route #' . (int) $rt['id'];
				}
			}
		}
		return '';
	}

	/**
	 * The Doorhangers routes assigned to $uid for today (doorhangers.list, asked as them), once per request (not once
	 * per code in a list). Planned: due or planned for today. In progress: started today, or
	 * due today (a route left "in progress" yesterday does not open a code today).
	 */
	public static function routes_today( $uid ) {
		static $cache = array();
		$uid = (int) $uid;
		if ( isset( $cache[ $uid ] ) ) {
			return $cache[ $uid ];
		}
		$out = array();
		if ( (int) get_current_user_id() === $uid && class_exists( 'ZDZ_Capabilities' ) && ZDZ_Capabilities::get( 'doorhangers.list' ) ) {
			$r = ZDZ_Capabilities::invoke( 'doorhangers.list', array( 'scope' => 'mine' ) );
			if ( ! empty( $r['success'] ) && empty( $r['denied'] ) ) {
				$today = ( new DateTime( '@' . (int) apply_filters( 'zdz_sealed_now', time() ) ) )->setTimezone( function_exists( 'zdh_timezone' ) ? zdh_timezone() : wp_timezone() )->format( 'Y-m-d' ); // the business's own day, as every app's "today" (was the site's clock)
				foreach ( (array) ( $r['routes'] ?? array() ) as $rt ) {
					if ( (int) ( $rt['assigned_uid'] ?? 0 ) !== $uid ) {
						continue;
					}
					$day = (string) ( $rt['due_by'] ?? '' ) ?: (string) ( $rt['planned_for'] ?? '' );
					$st  = (string) ( $rt['status'] ?? '' );
					$ok  = ( 'planned' === $st && $day === $today )
						|| ( 'in_progress' === $st && ( $day === $today || substr( (string) ( $rt['started_at'] ?? '' ), 0, 10 ) === $today ) );
					if ( $ok ) {
						$out[] = $rt;
					}
				}
			}
		}
		if ( ! defined( 'ZRP_TESTING' ) ) {
			$cache[ $uid ] = $out;
		}
		return $out;
	}

	public static function log_view( $code_id, $uid, $basis ) {
		global $wpdb;
		return (bool) $wpdb->insert( self::t_views(), array( 'code_id' => (int) $code_id, 'user_id' => (int) $uid, 'basis' => substr( (string) $basis, 0, 60 ), 'at' => gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/** A code as a list shows it: never the code. */
	public static function out( array $c, $uid ) {
		$who = get_userdata( (int) $c['created_by'] );
		$admin = zrp_user_is_admin( $uid );
		$o = array(
			'id' => (int) $c['id'], 'label' => (string) $c['label'], 'kind' => (string) $c['kind'], 'street' => (string) $c['street'], 'zip' => (string) $c['zip'],
			'digits' => $admin ? (int) $c['digits'] : null, 'added' => substr( (string) $c['created_at'], 0, 10 ), 'by' => $who ? (string) $who->display_name : '',
			'may_open' => '' === self::why_not( $c, $uid ),
		);
		if ( $admin ) {
			global $wpdb;
			$o['opened'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t_views() . ' WHERE code_id = %d', (int) $c['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_views() ($wpdb->prefix and a fixed name); values are placeholders
			if ( (int) ( $c['vault_id'] ?? 0 ) > 0 && self::vault_on() ) {   // openings since the move are in the vault's log
				$o['opened'] += count( array_filter( ZDZ_Sealed::log( (int) $c['vault_id'], 1000 ), function ( $l ) { return 0 !== strpos( (string) $l['basis'], 'refused' ); } ) );
			}
		}
		return $o;
	}

	public static function all() {
		global $wpdb;
		return (array) $wpdb->get_results( 'SELECT * FROM ' . self::t_codes() . ' ORDER BY id DESC LIMIT 500', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_codes() ($wpdb->prefix and a fixed name); the query takes no values
	}
}
