<?php
/**
 * ZAGR_Clock - the one place the agreements app reads the time, so the suites can stand on a fixed day. [CORE]
 *
 * Stored times are UTC 'Y-m-d H:i:s'. The days a customer sees (signed on, cancel by) are local days in the site's
 * time zone (ZAGR_Calendar::tz()).
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Clock {

	/** @var int|null a fixed Unix time for tests; null = the real clock */
	public static $fixed = null;

	public static function now(): int {
		return null === self::$fixed ? time() : (int) self::$fixed;
	}

	/** Now as UTC 'Y-m-d H:i:s'. */
	public static function utc( ?int $t = null ): string {
		return gmdate( 'Y-m-d H:i:s', null === $t ? self::now() : $t );
	}

	/** Now as UTC with microseconds, for the record ('2026-10-30 18:42:07.123456'). */
	public static function utc_micro(): string {
		if ( null !== self::$fixed ) {
			return gmdate( 'Y-m-d H:i:s', self::now() ) . '.000000';
		}
		$m = microtime( true );
		return gmdate( 'Y-m-d H:i:s', (int) $m ) . sprintf( '.%06d', (int) round( ( $m - floor( $m ) ) * 1000000 ) % 1000000 );
	}

	/** A UTC 'Y-m-d H:i:s' as a Unix time (0 when empty or unreadable). */
	public static function ts( ?string $utc ): int {
		if ( null === $utc || '' === $utc ) {
			return 0;
		}
		$t = strtotime( substr( $utc, 0, 19 ) . ' UTC' );
		return false === $t ? 0 : $t;
	}

	/** Today in the site's time zone, 'Y-m-d'. */
	public static function today(): string {
		return self::local_day( self::now() );
	}

	/** The local day of a Unix time. */
	public static function local_day( int $t ): string {
		$d = new DateTimeImmutable( '@' . $t );
		return $d->setTimezone( ZAGR_Calendar::tz() )->format( 'Y-m-d' );
	}

	/** "Friday, January 9, 2026 at 6:42 pm America/Chicago" in the site's time zone, from a UTC 'Y-m-d H:i:s'. */
	public static function local_long( string $utc ): string {
		$t = self::ts( $utc );
		if ( ! $t ) {
			return '';
		}
		$d = ( new DateTimeImmutable( '@' . $t ) )->setTimezone( ZAGR_Calendar::tz() );
		return $d->format( 'l, F j, Y' ) . ' at ' . $d->format( 'g:i a' ) . ' ' . ZAGR_Calendar::tz_label();
	}
}
