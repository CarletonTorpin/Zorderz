<?php
/**
 * ZEST_Edit_Token - the server's own guard on saving an opened estimate.
 *
 * Opening an estimate for editing issues a token for that estimate, for the person who opened it; a save is refused
 * without a token this person was issued for THAT estimate, before the billing system or the row is touched. The
 * browser drops its token whenever it leaves the opened estimate (start over, a fresh preview, leave) and tells the
 * server to forget it too (zest_drop_edit_token). So a slip in the browser can never save one estimate's new lines,
 * notes or customer over another.
 *
 * Only a hash of each token is kept, one user-meta row per token (two screens opening at once never lose each other's
 * token). A token lasts 24 hours from its last use, and a person keeps at most 100 (several tabs or devices can each
 * have an estimate open). A signed agreement still accepts edits: a change after signing is a change order, so nothing
 * here looks at the agreement.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Edit_Token {

	const PREFIX = 'zest_et_';
	const TTL    = 86400;
	const MAX    = 100;
	const TOUCH  = 3600;   // a save refreshes "last used" at most once an hour

	public static function init(): void {
		add_action( 'wp_ajax_zest_drop_edit_token', array( __CLASS__, 'ajax_drop' ) );
	}

	public static function says_none(): string {
		return __( 'Not saved: this screen doesn\'t have that estimate open any more. Open it again from the list, then save. (If the app was just updated, reload the page first.)', 'zorderz' );
	}

	public static function says_other(): string {
		return __( 'Not saved: what you are saving is not the estimate this screen opened. Nothing was changed. Open the estimate again from the list, then save.', 'zorderz' );
	}

	/** A new token for this person and estimate (shown to the browser once; only its hash is kept). */
	public static function issue( int $user_id, int $estimate_id ): string {
		if ( $user_id <= 0 || $estimate_id <= 0 ) {
			return '';
		}
		self::prune( $user_id );
		$token = bin2hex( random_bytes( 16 ) );
		add_user_meta( $user_id, self::key( $token ), $estimate_id . '|' . time(), true );
		return $token;
	}

	/** '' when this token is one this person was issued for this estimate, else the words to refuse with. */
	public static function check( int $user_id, int $estimate_id, $token ): string {
		$token = is_string( $token ) ? trim( $token ) : '';
		if ( $user_id <= 0 || $estimate_id <= 0 || ! preg_match( '/^[0-9a-f]{32}$/', $token ) ) {
			return self::says_none();
		}
		$row = self::read( $user_id, self::key( $token ) );
		if ( null === $row || ( time() - $row[1] ) > self::TTL ) {
			return self::says_none();
		}
		if ( $row[0] !== $estimate_id ) {
			return self::says_other();
		}
		if ( ( time() - $row[1] ) > self::TOUCH ) {
			update_user_meta( $user_id, self::key( $token ), $estimate_id . '|' . time() );
		}
		return '';
	}

	/** Forget one token (the browser left the estimate it was for). */
	public static function drop( int $user_id, $token ): bool {
		$token = is_string( $token ) ? trim( $token ) : '';
		if ( $user_id <= 0 || ! preg_match( '/^[0-9a-f]{32}$/', $token ) ) {
			return false;
		}
		return (bool) delete_user_meta( $user_id, self::key( $token ) );
	}

	public static function ajax_drop(): void {
		$uid = ZEST_Access::guard();
		self::drop( $uid, isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::guard() above
		wp_send_json_success( array( 'dropped' => true ) );
	}

	/** [ estimate id, last used ] of one token's row, or null. */
	private static function read( int $user_id, string $key ): ?array {
		$v = get_user_meta( $user_id, $key, true );
		if ( ! is_string( $v ) || ! preg_match( '/^(\d+)\|(\d+)$/', $v, $m ) ) {
			return null;
		}
		return array( (int) $m[1], (int) $m[2] );
	}

	/** Expired tokens go; past MAX, the least recently used go. */
	private static function prune( int $user_id ): void {
		$rows = array();
		foreach ( (array) get_user_meta( $user_id, '', false ) as $k => $vals ) {
			if ( 0 !== strpos( (string) $k, self::PREFIX ) ) {
				continue;
			}
			$t = preg_match( '/^\d+\|(\d+)$/', (string) ( is_array( $vals ) ? ( $vals[0] ?? '' ) : $vals ), $m ) ? (int) $m[1] : 0;
			if ( ( time() - $t ) > self::TTL ) {
				delete_user_meta( $user_id, $k );
			} else {
				$rows[ $k ] = $t;
			}
		}
		if ( count( $rows ) >= self::MAX ) {
			asort( $rows );
			foreach ( array_slice( array_keys( $rows ), 0, count( $rows ) - self::MAX + 1 ) as $k ) {
				delete_user_meta( $user_id, $k );
			}
		}
	}

	private static function key( string $token ): string {
		return self::PREFIX . substr( hash( 'sha256', 'zest-edit-token|' . $token ), 0, 48 );
	}
}
