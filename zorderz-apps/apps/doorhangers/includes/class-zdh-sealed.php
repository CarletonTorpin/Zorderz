<?php
/**
 * ZDH_Sealed -- Doorhangers on the theme's one vault for gate, lockbox, alarm, door and keypad codes.
 *
 * An access code is user-gated inside the app and never printed in plain text anywhere else. Who may see one: the
 * admin tier, and the walker assigned the route, on that route's day.
 *
 *   On the way in   a route's (drop's) notes and description, however they are written (a new route, a plan, an
 *                   edit, the finish screen): each code is sealed under the route (['street', 'drop:<id>']) and the
 *                   words keep "[gate code sealed]" (ZDH_DB::insert_drop / update_drop_fields / finish_delivery).
 *   Show code       the route card lists the codes kept for it beside the label; the button posts to the theme's
 *                   sealed/<id>/open route.
 *   Who may open    zdz_sealed_may_open: the route's assigned walker, planned for today (its due date, else its
 *                   planned date) or in progress and started or due today. "Today" is the business's own day
 *                   (zdh_timezone()). Admins and the kiosk are decided by the theme first.
 *   Out             every Doorhangers verb answer and the CSV export carry no code.
 *
 * FAIL CLOSED. Without the theme's vault (ZDZ_Sealed absent, or present but not ready) nothing is kept in plain text:
 * a conservative reader of its own (fallback_spans()) finds what looks like a code -- a code word, then digits -- and
 * the words keep "[gate code removed]". Nothing is kept anywhere; it is better to lose a code than to store it.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDH_Sealed {

	const WORDS = '/code|gate|lock|key|combo|buzz|call\s*-?\s*box|alarm|door|garage|pool|community|entry|access|pin/i';

	public static function boot() {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}
		add_filter( 'zdz_sealed_may_open', array( __CLASS__, 'may_open' ), 10, 3 );
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'guard_verbs' ), 1000 );
	}

	/**
	 * Is the theme's vault there and ready to find, cut and keep codes? A site may turn the vault off for this app
	 * (filter `zdh_vault_on` false): codes are then cut and never kept. The filter can only narrow, never keep a code
	 * without the vault.
	 */
	public static function on() {
		if ( function_exists( 'apply_filters' ) && false === apply_filters( 'zdh_vault_on', true ) ) {
			return false;
		}
		return class_exists( 'ZDZ_Sealed' )
			&& method_exists( 'ZDZ_Sealed', 'cut_ids' ) && method_exists( 'ZDZ_Sealed', 'find' )
			&& method_exists( 'ZDZ_Sealed', 'seal' ) && method_exists( 'ZDZ_Sealed', 'label' )
			&& method_exists( 'ZDZ_Sealed', 'ready' ) && ZDZ_Sealed::ready();
	}

	const INVISIBLE = '/[\x{00AD}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u';

	/** A digit, a spoken digit or a full-width digit: without one there is no code to find. */
	const DIGITS = '/\d|\xEF\xBC[\x90-\x99]|\b(?:zero|oh|o|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety)\b/i';

	/** The quick word check (zero-width marks read through). */
	private static function words( string $text ): bool {
		$plain = preg_replace( self::INVISIBLE, '', $text );
		return (bool) preg_match( self::WORDS, null === $plain ? $text : $plain );
	}

	/**
	 * Could $text hold a code? The word list, OR the vault's own quick check (ZDZ_Sealed::might_have_code()) for text
	 * with a digit in it. Either is enough. find() still decides what is a code.
	 */
	private static function quick( string $text ): bool {
		if ( self::words( $text ) ) {
			return true;
		}
		$plain = preg_replace( self::INVISIBLE, '', $text ); // the vault reads through a zero-width mark; so must this
		if ( ! preg_match( self::DIGITS, null === $plain ? $text : $plain ) ) {
			return false;
		}
		return class_exists( 'ZDZ_Sealed' ) && method_exists( 'ZDZ_Sealed', 'might_have_code' ) && (bool) ZDZ_Sealed::might_have_code( $text );
	}

	/** Spoken digits for the fallback reader. */
	const SPOKEN = '(?:zero|oh|one|two|three|four|five|six|seven|eight|nine)';

	/**
	 * The fallback reader, used only when the vault is not there: a code word, a few words, then three or more digits
	 * (with # or * and spaces or hyphens between them), or three or more spoken digits. Conservative: it would rather
	 * cut a number that was not a code than keep one that was. A postal, building, fire or discount "code" and a
	 * number followed by a street name are left alone. [[start, len, kind, words]] (byte offsets).
	 */
	public static function fallback_spans( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return array();
		}
		$plain = preg_replace( self::INVISIBLE, ' ', $text );
		$plain = null === $plain ? $text : $plain;
		if ( strlen( $plain ) !== strlen( $text ) ) {
			$plain = $text; // offsets must hold: read the text as written
		}
		$kw = '(?:gate|lock\s*-?\s*box|key\s*-?\s*(?:box|pad|safe|code)|keypad|pin\s*-?\s*pad|alarm|disarm|door\s+code|codes?|combo|combination|pin|passcode|pass\s*code|password|buzz(?:er)?|call\s*-?\s*box|intercom|garage|entry|access)';
		$re = '/\b(' . $kw . ')\b([^\d\n]{0,25}?)((?:[#*]\s?)?\d(?:[\s\-]?[\d#*]){2,15}|(?:' . self::SPOKEN . '\b[\s,\-]*){3,12})/iu';
		if ( ! preg_match_all( $re, $plain, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$out = array();
		foreach ( $m as $hit ) {
			$word  = strtolower( $hit[1][0] );
			$start = (int) $hit[3][1];
			$code  = rtrim( $hit[3][0], " ,-\t" );
			$len   = strlen( $code );
			$before = strtolower( substr( $plain, max( 0, (int) $hit[1][1] - 12 ), min( 12, (int) $hit[1][1] ) ) );
			if ( preg_match( '/^codes?$/', $word ) && preg_match( '/\b(?:zip|postal|post|promo|promotional|discount|coupon|building|fire|area|country|tax|error|status)\s*$/', $before ) ) {
				continue;
			}
			$after = substr( $plain, $start + $len, 40 );
			if ( preg_match( '/^\s+(?:[A-Za-z]+\s+){0,2}(?:st|street|ave|avenue|rd|road|dr|drive|ln|lane|ct|court|way|blvd|pl|place)\b/i', $after ) ) {
				continue; // "Gate at 1450 Oak St": a house number, not a code
			}
			$out[] = array( $start, $len, self::fallback_kind( $word ), $hit[0][0] );
		}
		return $out;
	}

	/** A code word -> the vault's kinds: gate, lockbox, alarm, door, keypad. */
	public static function fallback_kind( $word ) {
		$w = strtolower( preg_replace( '/[\s\-]+/', '', (string) $word ) );
		if ( preg_match( '/^(?:lockbox|keybox|keysafe)$/', $w ) ) {
			return 'lockbox';
		}
		if ( preg_match( '/^(?:alarm|disarm)$/', $w ) ) {
			return 'alarm';
		}
		if ( preg_match( '/^(?:keypad|pinpad)$/', $w ) ) {
			return 'keypad';
		}
		if ( 'doorcode' === $w || 'garage' === $w ) {
			return 'door';
		}
		return 'gate';
	}

	/** The words that stand where a code was: the vault's label, or ours when the code was only cut. */
	public static function label( $kind, $kept ) {
		if ( $kept && class_exists( 'ZDZ_Sealed' ) && method_exists( 'ZDZ_Sealed', 'label' ) ) {
			return (string) ZDZ_Sealed::label( $kind );
		}
		$kind = in_array( $kind, array( 'gate', 'lockbox', 'alarm', 'door', 'keypad' ), true ) ? $kind : 'gate';
		return '[' . $kind . ' code removed]';
	}

	/**
	 * The codes in $text: the vault's reader when the vault is on, less door sizes ("door #3080" is a door's
	 * size, not a code: a "door" match counts only when its words say code, pin, combo or key); the fallback reader
	 * otherwise. [[start, len, kind, words]]
	 */
	public static function spans( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return array();
		}
		if ( ! self::on() ) {
			return self::fallback_spans( $text );
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

	private static function relabel( $text, array $spans, $kept ) {
		foreach ( array_reverse( $spans ) as $sp ) {
			$text = substr( $text, 0, $sp[0] ) . self::label( $sp[2], $kept ) . substr( $text, $sp[0] + $sp[1] );
		}
		return $text;
	}

	/** One code into the vault: a bare code ("4822") by itself, else the words around it. Its id, or 0. */
	private static function keep_id( array $sp, array $scope ) {
		$id = (int) ZDZ_Sealed::seal( $sp[2], $sp[3], $scope );
		if ( $id > 0 ) {
			return $id;
		}
		$r = ZDZ_Sealed::cut_ids( $sp[3], $scope );
		return (int) ( $r['ids'][0] ?? 0 );
	}

	public static function has_code( $t ) {
		return (bool) self::spans( $t );
	}

	/** Every code cut out of $t (nothing kept). */
	public static function cut( $t ) {
		$sp = self::spans( $t );
		return $sp ? self::relabel( (string) $t, $sp, self::on() ) : $t;
	}

	public static function scope( $drop_id ) {
		return array( 'street', 'drop:' . (int) $drop_id );
	}

	/** A route's words with every code sealed under it (or, without the vault, cut). */
	public static function seal( $t, $drop_id ) {
		$sp = self::spans( $t );
		if ( ! $sp ) {
			return $t;
		}
		if ( ! self::on() || (int) $drop_id <= 0 ) {
			return self::relabel( (string) $t, $sp, false ); // fail closed: cut, never kept
		}
		static $seen = array();   // a code met again under the same route in this request is not filed twice
		$lost = 0;
		foreach ( $sp as $one ) {
			$k = (int) $drop_id . '|' . $one[2] . '|' . strtolower( preg_replace( '/\s+/', ' ', $one[3] ) );
			if ( isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$lost += self::keep_id( $one, self::scope( $drop_id ) ) > 0 ? 0 : 1;
		}
		if ( $lost ) {
			error_log( 'Zorderz Doorhangers: ' . $lost . ' code(s) for route #' . (int) $drop_id . ' could not be kept in the vault; they were cut from the words.' ); // phpcs:ignore
		}
		return self::relabel( (string) $t, $sp, true );
	}

	/** The words fields a drop row holds. */
	public static function fields( array $f, $drop_id ) {
		foreach ( array( 'notes', 'description' ) as $k ) {
			if ( isset( $f[ $k ] ) && is_string( $f[ $k ] ) ) {
				$f[ $k ] = (int) $drop_id > 0 ? self::seal( $f[ $k ], $drop_id ) : self::cut( $f[ $k ] );
			}
		}
		return $f;
	}

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

	/* ------------------------------------------------------------------ "today", the business's own day */

	public static function today() {
		$now = function_exists( 'apply_filters' ) ? (int) apply_filters( 'zdz_sealed_now', time() ) : time();
		$tz  = function_exists( 'zdh_timezone' ) ? zdh_timezone() : new DateTimeZone( 'UTC' );
		return ( new DateTime( '@' . $now ) )->setTimezone( $tz )->format( 'Y-m-d' );
	}

	/* ------------------------------------------------------------------ who may open */

	/**
	 * May the walker of this route open its codes today? Pure: the route's row, the person, today's date.
	 * The assigned walker only; a planned route on its day (due date, else planned date); a route in progress that is
	 * due or was started today.
	 */
	public static function walker_today( array $r, $uid, $today ) {
		$uid = (int) $uid;
		if ( $uid <= 0 || (int) ( $r['assigned_uid'] ?? 0 ) !== $uid ) {
			return false;
		}
		$day = (string) ( ( $r['due_by'] ?? '' ) ?: ( $r['drop_date'] ?? '' ) );
		$st  = (string) ( $r['status'] ?? '' );
		return ( 'planned' === $st && '' !== $day && $day === $today )
			|| ( 'in_progress' === $st && ( ( '' !== $day && $day === $today ) || substr( (string) ( $r['started_at'] ?? '' ), 0, 10 ) === $today ) );
	}

	/** "route #12 today" when $uid walks route $drop_id today, else ''. */
	public static function route_today( $drop_id, $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 || ! class_exists( 'ZDH_DB' ) ) {
			return '';
		}
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status, assigned_uid, drop_date, due_by, started_at FROM ' . ZDH_DB::t_drops() . ' WHERE id = %d', (int) $drop_id ), ARRAY_A ); // phpcs:ignore
		if ( ! $r ) {
			return '';
		}
		return self::walker_today( $r, $uid, self::today() ) ? 'route #' . (int) $r['id'] . ' today' : '';
	}

	public static function may_open( $ok, $row, $uid ) {
		if ( true === $ok || ( is_string( $ok ) && '' !== trim( $ok ) ) ) {
			return $ok;
		}
		if ( ! is_array( $row ) || 'street' !== (string) ( $row['scope_type'] ?? '' ) || ! preg_match( '/^drop:(\d+)$/', (string) ( $row['scope_id'] ?? '' ), $m ) ) {
			return $ok;
		}
		if ( function_exists( 'zdh_is_kiosk' ) && zdh_is_kiosk( (int) $uid ) ) {
			return $ok; // never the shared device, whatever the route says
		}
		$b = self::route_today( (int) $m[1], (int) $uid );
		return '' !== $b ? $b : $ok;
	}

	/* ------------------------------------------------------------------ "Show code" */

	/** The codes kept for a route, for its card: id, kind, may_open (never the code). Only when its words say one is sealed. */
	public static function for_drop( array $d, $uid ) {
		$uid = (int) $uid;
		$id  = (int) ( $d['id'] ?? 0 );
		if ( $uid <= 0 || $id <= 0 || ! self::on() || ! method_exists( 'ZDZ_Sealed', 't_sealed' ) || ! method_exists( 'ZDZ_Sealed', 'info' ) || zdh_is_kiosk( $uid ) ) {
			return array();
		}
		if ( false === strpos( (string) ( $d['notes'] ?? '' ) . (string) ( $d['description'] ?? '' ), ' code sealed]' ) ) {
			return array();
		}
		global $wpdb;
		$t   = ZDZ_Sealed::t_sealed();
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$t}` WHERE scope_type = 'street' AND scope_id = %s ORDER BY id DESC LIMIT 20", 'drop:' . $id ) ); // phpcs:ignore
		$out = array();
		foreach ( $ids as $sid ) {
			$i = ZDZ_Sealed::info( (int) $sid, $uid );
			if ( is_array( $i ) ) {
				$out[] = array( 'id' => (int) $i['id'], 'kind' => (string) $i['kind'], 'label' => (string) $i['label'], 'added' => (string) $i['added'], 'may_open' => (bool) $i['may_open'] );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ verbs */

	/** Every Doorhangers verb's answer passes through deep(): no code ever leaves in an answer. */
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
				if ( is_array( $cb ) && isset( $cb[0] ) && is_string( $cb[0] ) && 0 === strpos( $cb[0], 'ZDH_' ) && is_callable( $cb ) ) {
					$caps[ $verb ][ $k ] = function ( $args = array() ) use ( $cb ) {
						return ZDH_Sealed::deep( call_user_func( $cb, $args ) );
					};
				}
			}
		}
		return $caps;
	}
}
