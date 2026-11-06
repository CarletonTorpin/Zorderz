<?php
/**
 * ZIM_Notices - a push from ANOTHER app, held to the person's quiet hours like a Messages push.
 *
 * WHY: ZIM_Push::send_to_user() sends at once (no quiet hours), and the quiet-hours queue
 * (ZIM_Notifications::queue / dispatch_due) is per conversation: fire_group() treats any row whose person is not a
 * member of its conversation as fired and sends nothing. A notice from the theme's alert router (an estimate was
 * accepted) has no conversation, so without this class it would either push at 3 a.m. or vanish. This is the small
 * public seam the router's push channel calls - a filter, never a class of this app:
 *
 *     $id = apply_filters( 'zim_queue_notice', null, int $user_id, array $payload );   // int row id | WP_Error | null
 *
 *   payload: { title (<= 120 chars), body (<= 200), url (this site only), tag (<= 64, [a-z0-9_-]) }. Anything else is
 *   dropped, so a caller cannot put a customer's details in a push by accident beyond what it chose for title and body.
 *
 * HOW IT IS DELIVERED: one queue row of kind 'notice', conversation 0, release_at from the person's quiet hours (their
 * own, else the business's, else Core's: ZDZ_Quiet_Hours). The per-minute dispatch_due() groups rows by (person,
 * conversation), so a person's notices form one group; fire_group() hands a conversation-0 group here:
 *   - the person must still have Messages (zim_user_has_access) and must not be the shared device, a messaging
 *     read-only role or an account in Privacy View (ZIM_Push::refused); else the rows are marked fired and nothing is
 *     sent;
 *   - one notice: its payload as given;
 *   - several released together (after quiet hours): one digest, "N new notices", that opens the app;
 *   - no e-mail fallback: the router's own e-mail channel decides e-mail.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIM_Notices {

	const KIND = 'notice';

	/** @var callable|null (int $user_id, array $payload): array - tests; else ZIM_Push::send_to_user. */
	public static $sender = null;

	const MAX_URL   = 500;    // characters
	const MAX_BYTES = 2000;   // the encoded payload; ZIM_Push cuts at 3,800 mid-JSON, so stay far under it

	public static function init(): void {
		add_filter( 'zim_queue_notice', array( __CLASS__, 'f_queue' ), 10, 3 );
	}

	/** Who never gets a notice from Messages: nobody, the shared device, a read-only role, Privacy View, no Messages. */
	public static function refused( int $uid ): bool {
		if ( $uid <= 0 || ! get_userdata( $uid ) ) {
			return true;
		}
		if ( class_exists( 'ZIM_Push' ) && method_exists( 'ZIM_Push', 'refused' ) ) {
			if ( ZIM_Push::refused( $uid ) ) {
				return true;
			}
		} else {
			if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
				return true;
			}
			if ( function_exists( 'zim_user_is_read_only' ) && zim_user_is_read_only( $uid ) ) {
				return true;
			}
		}
		return function_exists( 'zim_user_has_access' ) ? ! zim_user_has_access( $uid ) : true;
	}

	/**
	 * A link on this site, or ''. The result is REBUILT from this site's origin plus the parsed path, query and
	 * fragment, so nothing in the caller's scheme, host or user-info part survives ("https://evil.example\@app.example/"
	 * parses as this host in PHP and as the other host in a browser). Backslashes, control characters and user-info are
	 * refused outright. An empty link opens the app's home.
	 */
	public static function clean_url( $url ): string {
		$url  = is_string( $url ) ? trim( $url ) : '';
		$home = home_url( '/' );
		$h    = wp_parse_url( $home );
		if ( ! is_array( $h ) || empty( $h['host'] ) ) {
			return '';
		}
		$scheme  = strtolower( (string) ( $h['scheme'] ?? 'https' ) );
		$default = 'https' === $scheme ? '443' : '80';
		$hport   = (string) ( $h['port'] ?? '' );
		$origin  = $scheme . '://' . strtolower( (string) $h['host'] ) . ( '' !== $hport && $default !== $hport ? ':' . $hport : '' );
		if ( '' === $url ) {
			return $home;
		}
		if ( strlen( $url ) > self::MAX_URL || false !== strpos( $url, '\\' ) || preg_match( '/[\x00-\x20\x7F]/', $url ) ) {
			return '';
		}
		if ( '/' === $url[0] ) {
			if ( isset( $url[1] ) && '/' === $url[1] ) {
				return '';                                                 // //host
			}
			$u = wp_parse_url( 'https://placeholder.invalid' . $url );      // a path on this site's origin
		} else {
			$u = wp_parse_url( $url );
			if ( ! is_array( $u ) || isset( $u['user'] ) || isset( $u['pass'] ) ) {
				return '';
			}
			if ( strtolower( (string) ( $u['scheme'] ?? '' ) ) !== $scheme ) {
				return '';
			}
			if ( strtolower( (string) ( $u['host'] ?? '' ) ) !== strtolower( (string) $h['host'] ) ) {
				return '';
			}
			$uport = (string) ( $u['port'] ?? '' );
			if ( ( '' === $uport ? $default : $uport ) !== ( '' === $hport ? $default : $hport ) ) {
				return '';
			}
		}
		if ( ! is_array( $u ) ) {
			return '';
		}
		$path = (string) ( $u['path'] ?? '/' );
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		// Rebuilt on this site's ORIGIN (not home_url, whose own path would double up on a site in a subfolder).
		$out = $origin . $path . ( isset( $u['query'] ) ? '?' . $u['query'] : '' ) . ( isset( $u['fragment'] ) ? '#' . $u['fragment'] : '' );
		return strlen( $out ) <= self::MAX_URL + 100 ? $out : '';
	}

	/** The payload as stored and sent: four short strings, nothing else, at most MAX_BYTES once encoded. */
	public static function clean_payload( array $p ): array {
		$cut = function ( $s, $n ) {
			$s = is_scalar( $s ) ? (string) $s : '';
			$s = function_exists( 'mb_scrub' ) ? mb_scrub( $s, 'UTF-8' ) : $s;   // invalid UTF-8 is repaired, not refused
			$s = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $s ) ) );
			return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $n ) : substr( $s, 0, $n );
		};
		$tag = strtolower( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', is_scalar( $p['tag'] ?? '' ) ? (string) ( $p['tag'] ?? '' ) : '' ) );
		if ( '' === $tag ) {
			$tag = 'zim-notice';
		} elseif ( 0 === strpos( $tag, 'zim-' ) && 'zim-notice' !== $tag ) {
			$tag = 'x-' . $tag;   // zim-<n> is a conversation's own tag: a notice never replaces a message's push
		}
		$out = array(
			'title' => $cut( $p['title'] ?? '', 120 ),
			'body'  => $cut( $p['body'] ?? '', 200 ),
			'url'   => self::clean_url( $p['url'] ?? '' ),
			'tag'   => substr( $tag, 0, 64 ),
		);
		// Characters are not bytes (an emoji encodes to 12): shorten the body, then the title, until it fits.
		foreach ( array( 'body', 'title' ) as $k ) {
			while ( strlen( (string) wp_json_encode( $out ) ) > self::MAX_BYTES && '' !== $out[ $k ] ) {
				$len       = function_exists( 'mb_strlen' ) ? mb_strlen( $out[ $k ] ) : strlen( $out[ $k ] );
				$out[ $k ] = $cut( $out[ $k ], max( 0, (int) floor( $len * 0.8 ) ) );
			}
		}
		return $out;
	}

	/**
	 * zim_queue_notice: ( null, int $user_id, array $payload ) -> int queued row id | WP_Error. A non-null value from an
	 * earlier callback is passed through untouched.
	 */
	public static function f_queue( $prev, $user_id = 0, $payload = array() ) {
		if ( null !== $prev ) {
			return $prev;
		}
		$uid = (int) $user_id;
		if ( self::refused( $uid ) ) {
			return new WP_Error( 'zim_notice_refused', 'This person does not get notices from Messages.' );
		}
		$p = self::clean_payload( is_array( $payload ) ? $payload : array() );
		if ( '' === $p['title'] ) {
			return new WP_Error( 'zim_notice_empty', 'A notice needs a title.' );
		}
		if ( '' === $p['url'] ) {
			return new WP_Error( 'zim_notice_url', 'A notice opens a page on this site only.' );
		}
		$id = ZIM_Notifications::queue( $uid, 0, 0, self::KIND, $p );
		return $id > 0 ? (int) $id : new WP_Error( 'zim_notice_write', 'The notice could not be queued.' );
	}

	/**
	 * Deliver one person's released notices (a conversation-0 group from dispatch_due()). Returns the push result, or
	 * null when nothing was sent. The caller marks the rows fired either way.
	 */
	public static function fire( array $group ): ?array {
		$uid   = (int) ( $group[0]['user_id'] ?? 0 );
		$group = array_values( array_filter( $group, function ( $r ) {
			return self::KIND === (string) ( $r['kind'] ?? '' );
		} ) );
		if ( ! $group || self::refused( $uid ) ) {
			return null;
		}
		if ( 1 === count( $group ) ) {
			// Cleaned when it was queued; sent as stored (cleaning twice could cut a long link), after one more check
			// that the link is still on this site.
			$p    = json_decode( (string) ( $group[0]['payload_json'] ?? '' ), true );
			$p    = is_array( $p ) ? $p : array();
			$push = array();
			foreach ( array( 'title', 'body', 'url', 'tag' ) as $k ) {
				$push[ $k ] = is_scalar( $p[ $k ] ?? '' ) ? (string) ( $p[ $k ] ?? '' ) : '';
			}
			$h    = wp_parse_url( home_url( '/' ) );
			$u    = wp_parse_url( $push['url'] );
			$same = is_array( $h ) && is_array( $u ) && ! isset( $u['user'] ) && false === strpos( $push['url'], '\\' )
				&& strtolower( (string) ( $u['host'] ?? '' ) ) === strtolower( (string) ( $h['host'] ?? '' ) );
			if ( '' === $push['title'] || ! $same ) {
				return null;
			}
		} else {
			$n    = count( $group );
			$push = array(
				/* translators: %d: how many notices */
				'title' => sprintf( __( '%d new notices', 'zorderz' ), $n ),
				'body'  => __( 'Tap to open the app.', 'zorderz' ),
				'url'   => home_url( '/' ),
				'tag'   => 'zim-notices',
			);
		}
		$send = is_callable( self::$sender ) ? self::$sender : array( 'ZIM_Push', 'send_to_user' );
		$r    = call_user_func( $send, $uid, $push );
		return is_array( $r ) ? $r : array();
	}
}
