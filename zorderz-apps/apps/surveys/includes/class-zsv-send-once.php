<?php
/**
 * Zorderz Surveys — one review e-mail per customer, unless a person asks for another.
 *
 * A page can be old (a phone left open, a tab from before another device sent), and two requests
 * can arrive in the same second, so "do not e-mail this customer twice" cannot be a rule the page
 * keeps. It is the site's rule, and three things make it hold:
 *
 *   1. ONE LOCK PER CUSTOMER, taken without waiting (a MySQL named lock, released by the database
 *      if the worker dies). A second request for the same customer is told a send is running and
 *      sends nothing. When the database does not answer the lock at all, nothing is sent either:
 *      without the lock "only once" cannot be promised, and a second review request is worse than
 *      a late first one.
 *   2. THE ROW IS READ AGAIN UNDER THE LOCK. The copy read at the top of a request can be seconds
 *      old; the decision and the send log use the fresh copy.
 *   3. AN E-MAIL THAT WENT IS NEVER FORGOTTEN. When the row will not take the time after the
 *      e-mail has left, the write is tried once more; if it still fails the customer is remembered
 *      in one small option (UNRECORDED_OPTION) that the same check reads, before the lock is let
 *      go. Without it the next press would e-mail them again.
 *
 * "The same customer" is the survey row: one row, one e-mail. A person who has two survey rows
 * can be e-mailed once for each (the resurvey cooldown is what keeps a second row from being made).
 * "E-mailed" does not wear off: the time on the row, or the newest time in its send log, or the
 * remembered list. A second e-mail goes only when the request names that customer in `send_again`
 * in the SAME request, which the site accepts from an administrator only and the admin screen sends
 * only after a person has answered a question. It counts for that one request.
 *
 * The lock is let go as soon as the row (or the remembered list) carries the time, before any CRM
 * work, which takes locks of its own.
 *
 * @package Zorderz\Surveys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSV_Send_Once {

	/** Customers whose e-mail went but whose row would not take the time: row id => 'Y-m-d H:i:s'. */
	const UNRECORDED_OPTION = 'zsv_emailed_unrecorded';

	/** How many of those are kept (the newest). */
	const UNRECORDED_KEEP = 200;

	/** A date column MySQL filled with its empty value: not a send. */
	const EMPTY_DATE = '0000-00-00 00:00:00';

	/* ── the lock ───────────────────────────────────────────────── */

	/** One customer's lock name: per install, per row, inside MySQL's 64 characters. */
	public static function lock_name( int $wp_lead_id ): string {
		global $wpdb;
		return 'zsv_send_' . substr( md5( ( $wpdb ? $wpdb->prefix : '' ) . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) ), 0, 12 ) . '_' . $wp_lead_id;
	}

	/**
	 * Take one customer's send lock WITHOUT waiting.
	 *
	 * @return int 1 = taken; 0 = another request holds it (a send to this customer is running);
	 *             -1 = the database did not answer (GET_LOCK answers NULL on an error).
	 *             Anything but 1 means nothing is sent.
	 */
	public static function lock( int $wp_lead_id ): int {
		global $wpdb;
		if ( ! $wpdb || $wp_lead_id < 1 ) {
			return -1;
		}
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name( $wp_lead_id ), 0 ) );
		if ( null === $raw ) {
			return -1;
		}
		return 1 === (int) $raw ? 1 : 0;
	}

	public static function unlock( int $wp_lead_id ): void {
		global $wpdb;
		if ( $wpdb && $wp_lead_id > 0 ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $wp_lead_id ) ) );
		}
	}

	/* ── was this customer e-mailed? ────────────────────────────── */

	/** The times a row's send log holds, oldest first ([] when it has none or cannot be read). */
	public static function send_log( array $row ): array {
		$log = ! empty( $row['email_send_log'] ) ? json_decode( (string) $row['email_send_log'], true ) : null;
		if ( ! is_array( $log ) ) {
			return array();
		}
		$out = array();
		foreach ( $log as $t ) {
			$t = is_scalar( $t ) ? trim( (string) $t ) : '';
			if ( '' !== $t ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	/**
	 * When this customer was last e-mailed, or '' if never: the time on the row, or failing that
	 * the newest time in its send log (a row whose time was lost but whose log was written still
	 * counts as e-mailed). The database's empty date is not a send. Pure.
	 *
	 * @param array $row A survey row (email_sent_at, email_send_log).
	 * @return string 'Y-m-d H:i:s' or ''.
	 */
	public static function emailed_at( array $row ): string {
		$at = isset( $row['email_sent_at'] ) ? trim( (string) $row['email_sent_at'] ) : '';
		if ( '' !== $at && self::EMPTY_DATE !== $at ) {
			return $at;
		}
		$log = self::send_log( $row );
		return $log ? (string) end( $log ) : '';
	}

	/* ── the remembered list ────────────────────────────────────── */

	/**
	 * Customers whose e-mail WENT but whose row would not take the time: row id => time. Read
	 * straight from the options table (a request's option cache can be seconds old). Two different
	 * customers failing in the same instant can lose one entry (a read-modify-write with no lock of
	 * its own): each still leaves its log line.
	 */
	public static function unrecorded(): array {
		global $wpdb;
		if ( $wpdb && isset( $wpdb->options ) && function_exists( 'maybe_unserialize' ) ) {
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::UNRECORDED_OPTION ) );
			$l   = null === $raw ? array() : maybe_unserialize( $raw );
		} else {
			$l = get_option( self::UNRECORDED_OPTION, array() );
		}
		return is_array( $l ) ? $l : array();
	}

	public static function unrecorded_add( int $wp_lead_id, string $when ): void {
		if ( $wp_lead_id < 1 ) {
			return;
		}
		$l = self::unrecorded();
		unset( $l[ $wp_lead_id ] );
		$l[ $wp_lead_id ] = $when;
		if ( count( $l ) > self::UNRECORDED_KEEP ) {
			$l = array_slice( $l, -self::UNRECORDED_KEEP, null, true );
		}
		update_option( self::UNRECORDED_OPTION, $l, false );
	}

	public static function unrecorded_forget( int $wp_lead_id ): void {
		$l = self::unrecorded();
		if ( isset( $l[ $wp_lead_id ] ) ) {
			unset( $l[ $wp_lead_id ] );
			update_option( self::UNRECORDED_OPTION, $l, false );
		}
	}

	/* ── "send again" ───────────────────────────────────────────── */

	/**
	 * The customers of THIS request a person asked to e-mail again. $raw is the request's
	 * `send_again` (a list, or numbers separated by commas); only whole numbers that are also among
	 * the request's own customers count, so a number that is not being sent now means nothing and a
	 * request that names no customer never sends again. Pure.
	 *
	 * @param mixed $raw      The request's send_again value.
	 * @param int[] $lead_ids The customers this request is for.
	 * @return int[]
	 */
	public static function named_again( $raw, array $lead_ids ): array {
		if ( is_array( $raw ) ) {
			$parts = $raw;
		} elseif ( is_scalar( $raw ) ) {
			$parts = explode( ',', (string) $raw );
		} else {
			return array();
		}
		$ids = array();
		foreach ( $parts as $p ) {
			$n = is_scalar( $p ) ? abs( (int) trim( (string) $p ) ) : 0;
			if ( $n > 0 ) {
				$ids[ $n ] = true;
			}
		}
		$mine = array_map( 'intval', $lead_ids );
		return array_values( array_intersect( array_keys( $ids ), $mine ) );
	}
}
