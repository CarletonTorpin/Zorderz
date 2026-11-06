<?php
/**
 * ZHR_Schedule -- a person's own appointments from the Scheduler app, beside their hours (a day lists them; the office's
 * hints for a forgotten finish read the last one's end).
 *
 * Read through the Scheduler's public query with the VIEWER's own rights, never a table read of our own, and kept to the
 * person's own appointments or ones they take part in. Title and times only. When the Scheduler is not installed (or its
 * query is not there) there is nothing to list and nothing breaks: an empty list.
 *
 * @package Zorderz\Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZHR_Schedule {

	/** The Scheduler's appointment model. */
	const SOURCE = 'ZSCH_Appointments';

	/** Is the Scheduler's query there to ask? $class: the model's class name (tests name a stand-in). */
	public static function available( $class = self::SOURCE ) {
		return is_string( $class ) && '' !== $class && class_exists( $class ) && method_exists( $class, 'query' );
	}

	/**
	 * $uid's appointments in [$from, $to] (site dates), by day: [ 'Y-m-d' => [ ['title','start','end','all_day'] … ] ],
	 * at most 12 a day, in time order. Asked as $viewer. [] when the Scheduler is absent or the read fails.
	 */
	public static function for_person( $uid, $from, $to, $viewer, $class = self::SOURCE ) {
		if ( ! self::available( $class ) ) {
			return array();
		}
		$tz = ZHR_Time::tz();
		try {
			$a    = ( new DateTimeImmutable( $from . ' 00:00:00', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			$b    = ( new DateTimeImmutable( $to . ' 23:59:59', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			$rows = call_user_func( array( $class, 'query' ), (int) $viewer, $a, $b, array( 'scope' => 'all', 'owner_id' => (int) $uid ) );
		} catch ( Throwable $e ) {
			return array();
		}
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$mine = (int) ( $r['owner_user_id'] ?? 0 ) === (int) $uid
				|| in_array( (int) $uid, array_map( 'intval', (array) ( $r['participant_ids'] ?? array() ) ), true );
			if ( ! $mine ) {
				continue; // the shared calendar's other events, and anyone else's, are not this person's day
			}
			try {
				$s = ( new DateTimeImmutable( (string) ( $r['start_utc'] ?? '' ), new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
				$e = ( new DateTimeImmutable( (string) ( $r['end_utc'] ?? '' ), new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
			} catch ( Throwable $ex ) {
				continue;
			}
			$day = $s->format( 'Y-m-d' );
			if ( $day < $from || $day > $to ) {
				continue;
			}
			$out[ $day ][] = array(
				'title'   => ZHR_Time::cut( ZHR_Time::clean_note( (string) ( $r['title'] ?? '' ) ), 80 ),
				'start'   => $s->format( 'H:i' ),
				'end'     => $e->format( 'H:i' ),
				'all_day' => ! empty( $r['is_all_day'] ),
			);
		}
		foreach ( $out as $d => $list ) {
			usort( $list, function ( $x, $y ) { return strcmp( $x['start'], $y['start'] ); } );
			$out[ $d ] = array_slice( $list, 0, 12 );
		}
		ksort( $out );
		return $out;
	}
}
