<?php
/**
 * ZANA_Pseudonym_Route — a Privacy View code typed into the chat.
 *
 * A Privacy View pseudonym ("Kgdj·UL6GL") is never a name. Taken for one, it would be searched for in the business's
 * records, and a loose match could bring back an unrelated real contact. So a message holding one is answered before
 * any search or model:
 *
 *   an administrator (not on the shared device, not a Privacy View account)
 *       -> what it stands for, from the kernel's look-up (ZDZ_Privacy_View::lookup): "customer: …", "invoice #…"
 *   anyone else, the Privacy View account included
 *       -> "That's a Privacy View code; only an administrator can look it up."
 *
 * A code is recognised by its shape AND its check character (ZDZ_Privacy_View::well_formed), so a word that merely looks
 * like one is not taken: "Tag·CODE" (a four-consonant tag, then five) or the labelled "Cust·CODE", "Inv·CODE" ...
 *
 * For a Privacy View account, a code from one of THIS chat's own answers is read as what it stands for (swap(): the
 * kernel's resolve_in() against the chat's saved answers), so the account can ask about, or cite, what it was just shown;
 * the turn then runs as usual and its answer reaches the account masked again, under a new tag. Every code in the message
 * must be from this chat (its tag one this chat was shown, kept by remember_tag() when a reply is masked), or none is
 * read and the reply says so. Earlier messages of the chat are read the same way for the model (swap_history()); what is
 * SAVED is always what was typed.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Pseudonym_Route {

	const MAX          = 5;    // codes answered from one message
	const HISTORY_MAX  = 4;    // earlier messages of the account's read for the model, at most
	const TAGS_META    = 'zana_pv_shown_tags';   // user meta: chat id => the tags it was shown
	const TAGS_CHATS   = 10;
	const TAGS_PER_CHAT = 100;

	private static $memo = array();

	private static function pv(): bool {
		return class_exists( 'ZDZ_Privacy_View' );
	}

	/** Is Privacy View on for this person? */
	public static function is_on( int $uid ): bool {
		return self::pv() && ZDZ_Privacy_View::is_on( $uid );
	}

	/** The Privacy View codes in a message (as typed, de-duplicated), checked by their check character. */
	public static function find( string $message ): array {
		if ( '' === trim( $message ) || strlen( $message ) > 600 || ! self::pv() || ! method_exists( 'ZDZ_Privacy_View', 'well_formed' ) ) {
			return array();
		}
		$re = '/(?<![\p{L}\p{N}])((?:[BCDFGHJKLMNPQRSTVWXZ][bcdfghjklmnpqrstvwxz]{3})|Cust|Staff|Inv|Est)\s?[·.\-]\s?([0-9A-Za-z]{4}[0-9A-Za-z_])(?![\p{L}\p{N}])/u';
		if ( ! preg_match_all( $re, $message, $m, PREG_SET_ORDER ) ) {
			return array();
		}
		$out = array();
		foreach ( $m as $x ) {
			$code = strtoupper( $x[2] );
			if ( ! ZDZ_Privacy_View::well_formed( $code ) ) {
				continue;   // the check character does not fit: an ordinary word, not a code
			}
			$out[ $x[1] . '·' . $code ] = true;
		}
		return array_slice( array_keys( $out ), 0, self::MAX );
	}

	/** May this person learn what a code stands for? An administrator, never the shared device or a Privacy View account. */
	public static function may_look_up( int $uid ): bool {
		if ( $uid <= 0 || ! function_exists( 'user_can' ) || ! user_can( $uid, 'manage_options' ) ) {
			return false;
		}
		if ( self::is_on( $uid ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && ( ( method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) || ( method_exists( 'ZDZ_Hierarchy', 'is_admin' ) && ! ZDZ_Hierarchy::is_admin( $uid ) ) ) ) {
			return false;
		}
		return true;
	}

	/** The whole answer, or null when the message holds no code (the turn goes on as before). */
	public static function answer( string $message, int $uid ): ?string {
		$codes = self::find( $message );
		if ( ! $codes ) {
			return null;
		}
		$one = 1 === count( $codes );
		if ( ! self::may_look_up( $uid ) || ! method_exists( 'ZDZ_Privacy_View', 'lookup' ) ) {
			$pv = self::is_on( $uid ) && method_exists( 'ZDZ_Privacy_View', 'resolve_in' );
			return ( $one ? __( 'That\'s a Privacy View code', 'zorderz' ) : __( 'Those are Privacy View codes', 'zorderz' ) )
				. ' -- ' . __( 'they stand in for a customer, a team member, an address or a document number, and change every time they\'re shown. Only an administrator can look one up (Zorderz → Privacy View). Nothing was searched.', 'zorderz' )
				. ( $pv ? ' ' . __( 'A code from one of this chat\'s own answers (a customer, a team member, an invoice or an estimate) can be used in a question here;', 'zorderz' ) . ' '
					. ( $one ? __( 'this one isn\'t.', 'zorderz' ) : __( 'at least one of these isn\'t.', 'zorderz' ) ) : '' );
		}
		$lines = array();
		foreach ( $codes as $c ) {
			$hits = (array) ZDZ_Privacy_View::lookup( $c );
			if ( ! $hits ) {
				$lines[] = '- **' . $c . '**: ' . __( 'no match among customers, team members, invoices or estimates. It may stand for an e-mail, a phone number or a street (those aren\'t kept to look up), or it was mistyped.', 'zorderz' );
				continue;
			}
			$lines[] = '- **' . $c . '**: ' . implode( '; ', array_map( 'strval', array_slice( $hits, 0, 3 ) ) )
				/* translators: %d: how many more matches */
				. ( count( $hits ) > 3 ? ' ' . sprintf( __( '(and %d more)', 'zorderz' ), count( $hits ) - 3 ) : '' );
		}
		return __( 'Privacy View look-up (nothing was searched):', 'zorderz' ) . "\n\n" . implode( "\n", $lines )
			. "\n\n" . __( 'Ask about it by its real name or number if you want more.', 'zorderz' );
	}

	/**
	 * For a Privacy View account, $message with each code read as what it stands for -- only when EVERY code in it stands
	 * for something this chat's own saved answers hold. null: no code, not such an account, the kernel cannot say, or a
	 * code that is not from this chat (answer() then replies).
	 *
	 * @param array $history [ { role, content } ] the chat's saved messages, oldest first
	 */
	public static function swap( string $message, int $uid, array $history, int $session_id = 0 ): ?string {
		$codes = self::find( $message );
		if ( ! $codes || $session_id <= 0 || ! method_exists( 'ZDZ_Privacy_View', 'resolve_in' ) || ! self::is_on( $uid ) ) {
			return null;
		}
		// a code under a tag this chat was never shown (another chat, another page) is never read
		$tags = self::tags_for( $uid, $session_id );
		foreach ( $codes as $c ) {
			if ( ! in_array( explode( '·', $c, 2 )[0], $tags, true ) ) {
				return null;
			}
		}
		$shown = self::shown( $history );
		if ( '' === trim( $shown ) ) {
			return null;
		}
		$mk = md5( $uid . "\0" . $session_id . "\0" . $message . "\0" . $shown );
		if ( array_key_exists( $mk, self::$memo ) ) {
			return self::$memo[ $mk ];
		}
		$out = $message;
		foreach ( $codes as $c ) {
			$r = ZDZ_Privacy_View::resolve_in( $c, $shown );
			if ( ! is_array( $r ) || '' === trim( (string) ( $r['value'] ?? '' ) ) ) {
				return self::$memo[ $mk ] = null;
			}
			list( $tag, $code ) = explode( '·', $c, 2 );
			$re  = '/(?<![\p{L}\p{N}])' . preg_quote( $tag, '/' ) . '\s?[·.\-]\s?' . preg_quote( $code, '/' ) . '(?![\p{L}\p{N}])/iu';
			$cur = $out;
			$out = (string) preg_replace_callback( $re, function ( $m ) use ( $r, $cur ) {
				return self::spoken( $r, $cur, $m[0] );
			}, $out );
		}
		return self::$memo[ $mk ] = $out;
	}

	/** The chat's earlier messages as the model reads them: a code in one of the account's own messages read the same way. */
	public static function swap_history( array $history, int $uid, int $session_id = 0 ): array {
		if ( $session_id <= 0 || ! self::is_on( $uid ) ) {
			return $history;
		}
		$left = self::HISTORY_MAX;   // the latest few only: each is a look-up
		for ( $i = count( $history ) - 1; $i >= 0 && $left > 0; $i-- ) {
			$h = $history[ $i ] ?? null;
			if ( ! is_array( $h ) || 'user' !== ( $h['role'] ?? '' ) || ! is_string( $h['content'] ?? null ) || ! self::find( $h['content'] ) ) {
				continue;
			}
			$left--;
			$sw = self::swap( $h['content'], $uid, $history, $session_id );
			if ( null !== $sw ) {
				$history[ $i ]['content'] = $sw;
			}
		}
		return $history;
	}

	/** Keep $tag as shown in chat $session_id (the newest 10 chats, 100 tags each: a small meta value). */
	public static function remember_tag( int $uid, int $session_id, string $tag ): void {
		if ( $uid <= 0 || $session_id <= 0 || ! preg_match( '/^[BCDFGHJKLMNPQRSTVWXZ][bcdfghjklmnpqrstvwxz]{3}$/', $tag ) ) {
			return;
		}
		$map  = get_user_meta( $uid, self::TAGS_META, true );
		$map  = is_array( $map ) ? $map : array();
		$list = isset( $map[ $session_id ] ) && is_array( $map[ $session_id ] ) ? $map[ $session_id ] : array();
		if ( in_array( $tag, $list, true ) ) {
			return;
		}
		$list[] = $tag;
		unset( $map[ $session_id ] );
		$map[ $session_id ] = array_slice( $list, -self::TAGS_PER_CHAT );   // this chat last: the newest
		if ( count( $map ) > self::TAGS_CHATS ) {
			$map = array_slice( $map, -self::TAGS_CHATS, null, true );
		}
		update_user_meta( $uid, self::TAGS_META, $map );
	}

	/** The tags chat $session_id was shown. */
	public static function tags_for( int $uid, int $session_id ): array {
		$map = get_user_meta( $uid, self::TAGS_META, true );
		return ( is_array( $map ) && isset( $map[ $session_id ] ) && is_array( $map[ $session_id ] ) ) ? array_values( $map[ $session_id ] ) : array();
	}

	/** What the chat has shown: its saved answers (never the account's own messages). */
	private static function shown( array $history ): string {
		$out = array();
		foreach ( $history as $h ) {
			if ( 'assistant' === ( $h['role'] ?? '' ) && is_string( $h['content'] ?? null ) && '' !== $h['content'] ) {
				$out[] = $h['content'];
			}
		}
		return implode( "\n\n", $out );
	}

	/** How a resolved code reads in the sentence: a name, or "invoice #123" (just "#123" after the word itself). */
	private static function spoken( array $r, string $message, string $typed ): string {
		$v = (string) $r['value'];
		if ( 'invoice' !== ( $r['kind'] ?? '' ) && 'estimate' !== ( $r['kind'] ?? '' ) ) {
			return $v;
		}
		$at     = stripos( $message, $typed );
		$before = false === $at ? '' : substr( $message, 0, $at );
		return preg_match( '/\b' . $r['kind'] . '\s*(?:#|no\.?|number)?\s*$/i', $before ) ? $v : $r['kind'] . ' ' . $v;
	}
}
