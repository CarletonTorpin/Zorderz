<?php
/**
 * ZHR_Private -- customers' details never stand in Hours' tables or its files: a thin face on the platform's one
 * customer-detail coder, ZDZ_Private::for( 'hours' ) (its own key, name index and code handling for Hours' stored notes).
 *
 *   ⟦c:<id>⟧      a customer the sales ledger knows; named again only from the on-site copy of that ledger, and only for
 *                 the entry's own person or the admin tier.
 *   ⟦e:<sealed>⟧  anything else private: a customer name another app knows, a surname in a work phrase, a phone number,
 *                 an e-mail, a street address, and in Hours' own notes a gate or door code.
 *
 * The people who work here are never coded. Files that leave the site say "a customer" / "[private]" instead.
 *
 * Fail closed: without the platform's coder a note is not stored at all (never stored uncoded), and nothing is decoded.
 *
 * @package Zorderz\Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZHR_Private {

	const OPEN  = '⟦';
	const CLOSE = '⟧';

	/** The platform's coder for Hours, or null when the platform has none. */
	private static function coder() {
		if ( ! class_exists( 'ZDZ_Private' ) || ! method_exists( 'ZDZ_Private', 'for' ) ) {
			return null;
		}
		return ZDZ_Private::for( 'hours', array(
			'team'         => function () {
				return array_map( function ( $p ) { return (string) $p['name']; }, zhr_people() );
			},
			'table_exists' => array( 'ZHR_DB', 'table_exists' ),
			'log'          => 'Hours',
		) );
	}

	/** Any ⟦ ⟧ in words is defused, so nobody can hand the site a code. */
	public static function defuse( $s ) {
		return str_replace( array( self::OPEN, self::CLOSE ), array( '[', ']' ), (string) $s );
	}

	public static function encode( $text ) {
		$c = self::coder();
		return $c ? $c->encode( $text ) : '';
	}

	/** Plain words for the entry's own person or the admin tier ($show true); "a customer" / "[private]" otherwise. */
	public static function decode( $text, $show ) {
		$c = self::coder();
		if ( ! $c ) {
			return (string) preg_replace( '/' . self::OPEN . '[ce]:[A-Za-z0-9_\-]*' . self::CLOSE . '/u', '[private]', (string) $text );
		}
		return $c->decode( $text, $show );
	}

	public static function has_codes( $text ) {
		return (bool) preg_match( '/' . self::OPEN . '[ce]:/u', (string) $text );
	}

	/** A note as stored: cleaned plain words, coded, at most 1,500 characters without ever cutting a code in two. */
	public static function store_note( $plain ) {
		$plain = ZHR_Time::clean_note( self::defuse( $plain ) );
		if ( '' === $plain ) {
			return '';
		}
		$enc = self::encode( $plain );
		while ( '' !== $enc && ZHR_Time::cut( $enc, 1500 ) !== $enc ) {
			$enc = preg_replace( '/\s*' . self::OPEN . '[ce]:[A-Za-z0-9_\-]*' . self::CLOSE . '[^' . self::OPEN . ']*$/u', ' …', $enc, 1 );
			if ( null === $enc || strlen( $enc ) < 10 ) {
				return '';
			}
		}
		return (string) $enc;
	}
}
