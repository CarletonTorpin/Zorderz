<?php
/**
 * ZAGR_Calendar - local days, the moment a cancellation period ends, and the dates people read. [CORE]
 *
 * The time zone is the business's (the Business Profile's locale.timezone, else WordPress's own setting, else UTC;
 * filter zagr_timezone). A cancellation period ends at 11:59:59 pm local time on its last day. Which day that is comes
 * from the legal pack (ZAGR_Pack::deadline()), never from here.
 *
 * Dates in and out are 'Y-m-d' strings.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Calendar {

	/** @var DateTimeZone|null this request's zone */
	private static $tz = null;

	/** The site's time zone. */
	public static function tz(): DateTimeZone {
		if ( null !== self::$tz ) {
			return self::$tz;
		}
		$name = '';
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			$name = (string) ZDZ_Business_Profile::get( 'locale.timezone', '' );
		}
		if ( '' === $name && function_exists( 'wp_timezone_string' ) ) {
			$name = (string) wp_timezone_string();
		}
		if ( function_exists( 'apply_filters' ) ) {
			$name = (string) apply_filters( 'zagr_timezone', $name );
		}
		try {
			self::$tz = new DateTimeZone( '' !== $name ? $name : 'UTC' );
		} catch ( \Throwable $e ) {
			self::$tz = new DateTimeZone( 'UTC' );
		}
		return self::$tz;
	}

	/** The zone's name as people read it after a time ("America/Chicago", or "UTC+02:00"). */
	public static function tz_label(): string {
		return self::tz()->getName();
	}

	/** Forget the zone (after a settings change, and in the suites). */
	public static function forget(): void {
		self::$tz = null;
	}

	/** "Monday, January 5, 2026". */
	public static function long_date( string $ymd ): string {
		if ( ! self::valid( $ymd ) ) {
			return '';
		}
		return ( new DateTimeImmutable( $ymd, self::tz() ) )->format( 'l, F j, Y' );
	}

	/** "Thu, Jan 8". */
	public static function short_date( string $ymd ): string {
		if ( ! self::valid( $ymd ) ) {
			return '';
		}
		return ( new DateTimeImmutable( $ymd, self::tz() ) )->format( 'D, M j' );
	}

	/** Today, locally (through ZAGR_Clock, so the suites can fix the day). */
	public static function today(): string {
		return ZAGR_Clock::today();
	}

	/** A real calendar date written Y-m-d (2026-02-30 is not). */
	public static function valid( string $ymd ): bool {
		return ZAGR_Business_Days::valid( $ymd );
	}

	/** The Unix time the period closes: 11:59:59 pm local on $ymd (0 when $ymd is not a date). */
	public static function end_ts( string $ymd ): int {
		if ( ! self::valid( $ymd ) ) {
			return 0;
		}
		return ( new DateTimeImmutable( $ymd . ' 23:59:59', self::tz() ) )->getTimestamp();
	}

	/** Is the period that ends on $cancel_by still open at $now? */
	public static function window_open( string $cancel_by, int $now ): bool {
		$end = self::end_ts( $cancel_by );
		return $end > 0 && $now <= $end;
	}

	/** $days calendar days after $ymd. */
	public static function add_days( string $ymd, int $days ): string {
		if ( ! self::valid( $ymd ) ) {
			return '';
		}
		return ( new DateTimeImmutable( $ymd, self::tz() ) )->modify( '+' . max( 0, $days ) . ' day' )->format( 'Y-m-d' );
	}
}
