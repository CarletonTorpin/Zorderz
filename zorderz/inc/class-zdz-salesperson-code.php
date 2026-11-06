<?php
/**
 * ZDZ_Salesperson_Code -- one person's salesperson code, read the same way everywhere.
 *
 * The code is the short handle that ties a person to their sales, leads and documents ("AB"). Two screens keep one:
 *
 *   the profile     "Salesperson Code" on the person's WordPress profile, the lead generator's match key
 *                   (PROFILE_KEY, also read by the Leads app);
 *   the commission  the person's party code, set by the commission plan or a compensation pack (PARTY_KEY).
 *
 * get() reads the profile's code, then the party code, and returns the first that is set; party() reads the party
 * code first. Every reader of "this person's code" in the theme goes through here, so a code set on either screen is
 * seen by both.
 *
 * AN UPGRADED INSTALL. Before their rename the two screens kept the code under older keys (OLD_PROFILE_KEY,
 * OLD_PARTY_KEY). The rename migration copies them to the current keys; until it has (or when the app that declares
 * the row was not installed when it ran) the older key is read, but only while the current one is empty. Saving the
 * profile field removes the older copy, so a code cleared there never comes back from it.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Salesperson_Code {

	/** The profile's "Salesperson Code" (the lead generator's match key). The profile screen writes it. */
	const PROFILE_KEY = 'zl_salesperson_code';

	/** The person's party code (the commission plan's). */
	const PARTY_KEY = 'zdz_party_code';

	/** The keys the two screens used before their rename: read only while the current key is empty. */
	const OLD_PROFILE_KEY = 'tsl_salesperson_code';
	const OLD_PARTY_KEY   = 'tscc_salesperson_code';

	/** The longest code the profile field keeps. */
	const MAX = 10;

	/** The person's code: the profile's, else the party code ('' when neither is set). */
	public static function get( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$code = self::profile( $user_id );
		return '' !== $code ? $code : self::party( $user_id );
	}

	/** The profile field's code: the current key, else the key from before the rename. */
	public static function profile( int $user_id ): string {
		return $user_id > 0 ? self::pick( self::read( $user_id, array( self::PROFILE_KEY, self::OLD_PROFILE_KEY ) ), self::PROFILE_KEY, self::OLD_PROFILE_KEY ) : '';
	}

	/** The party code: the current key, else the key from before the rename. */
	public static function party( int $user_id ): string {
		return $user_id > 0 ? self::pick( self::read( $user_id, array( self::PARTY_KEY, self::OLD_PARTY_KEY ) ), self::PARTY_KEY, self::OLD_PARTY_KEY ) : '';
	}

	/**
	 * PURE: the code from one person's meta values (key => value): the current key when it holds a code, else the
	 * older key's. A value that is not a short line of text is no code.
	 */
	public static function pick( array $meta, string $key, string $old_key ): string {
		$now = self::clean( $meta[ $key ] ?? '' );
		if ( '' !== $now ) {
			return $now;
		}
		return self::clean( $meta[ $old_key ] ?? '' );
	}

	/** PURE: a stored value as a code: trimmed text without line breaks, or ''. */
	public static function clean( $v ): string {
		if ( ! is_string( $v ) && ! is_int( $v ) ) {
			return '';
		}
		$v = trim( (string) $v );
		if ( '' === $v || strlen( $v ) > 64 || preg_match( '/[\x00-\x1F\x7F]/', $v ) ) {
			return '';
		}
		return $v;
	}

	/**
	 * Save the profile field. The code is kept as typed (codes are matched exactly), at most MAX characters; an empty
	 * value clears it. The older copy is removed, so it is never read in its place.
	 */
	public static function save_profile( int $user_id, string $code ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		$code = substr( trim( $code ), 0, self::MAX );
		update_user_meta( $user_id, self::PROFILE_KEY, $code );
		if ( function_exists( 'delete_user_meta' ) ) {
			delete_user_meta( $user_id, self::OLD_PROFILE_KEY );
		}
	}

	/** @return array key => stored value */
	private static function read( int $user_id, array $keys ): array {
		$out = array();
		foreach ( $keys as $k ) {
			$out[ $k ] = function_exists( 'get_user_meta' ) ? get_user_meta( $user_id, $k, true ) : '';
		}
		return $out;
	}
}
