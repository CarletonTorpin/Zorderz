<?php
/**
 * ZPREP_Install_Fallback — scheduled-date fallback from billing-document text.
 *
 * The Projects/jobs app owns the real, appointment-linked install date. But when
 * no appointment is linked — or that app is absent/too old — Prep would show only
 * the LEAD's creation date, which is not what an operator plans around.
 *
 * In practice the scheduled date is written into the first "Location" line item of
 * the FreshBooks document, e.g.
 *
 *     Downtown - (AB) Scheduled 8/4
 *     Westside / CD Scheduled 8-7-26
 *     Lakeview - (EF) Scheduled 8-14-26
 *     Hillcrest - (GH) Sched. Wed 8/18/26 10 am        (invoice — with a time)
 *
 * So this parses that line: anchored on "Sched"/"Scheduled" (also install/appt),
 * it reads a M/D, M-D-YY, M/D/YY or ISO date and an optional "10 am" time. The
 * anchor is why a bare measurement, a phone number, or the document's OWN date is
 * never mistaken for the install date. `source:'estimate'` marks it a fallback so
 * it can never masquerade as a confirmed appointment. No WordPress at load — pure
 * and unit-tested standalone.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ZPREP_Install_Fallback {

	/** Flatten CRM notes (string, or array of note objects) to plain text. */
	public static function flatten_notes( $notes ): string {
		if ( is_string( $notes ) ) return $notes;
		if ( is_array( $notes ) ) {
			$parts = [];
			foreach ( $notes as $n ) {
				if ( is_string( $n ) )      $parts[] = $n;
				elseif ( is_array( $n ) )   $parts[] = (string) ( $n['body'] ?? $n['note'] ?? $n['text'] ?? '' );
			}
			return implode( "\n", $parts );
		}
		return '';
	}

	/**
	 * Parse a scheduled install date (+ optional time) out of estimate/invoice text.
	 *
	 * @return array{date:string,hour:?int,minute:int}|null  date is Y-m-d.
	 */
	public static function parse( $text ): ?array {
		$text = self::flatten_notes( $text );
		if ( $text === '' ) return null;

		// TWO passes, strongest anchor first. The scheduled install date always rides a
		// "Sched"/"Scheduled" line, so that anchor is tried across the WHOLE document
		// before the weaker install/appt/appointment anchors get a turn. This prevents a
		// bookkeeping edit note (e.g. "Rev 2: … full-installation … (8/13/2026)") — which
		// matches the weak "install" anchor and, since notes precede line items, would be
		// read first — from overriding the real "Location … Scheduled 8/27" line.
		return self::parse_anchored( $text, '/\bsched/i', false )
			?? self::parse_anchored( $text, '/\b(install(?:ation)?|appt|appointment)/i', true );
	}

	/**
	 * One anchored scan of the text: the first line matching $anchor that yields a
	 * date wins. $skip_rev additionally drops revision / bookkeeping notes (the weak
	 * install/appt pass only) so an edit stamp can't be read as an install date.
	 *
	 * @return array{date:string,hour:?int,minute:int,assignee:string}|null
	 */
	private static function parse_anchored( string $text, string $anchor, bool $skip_rev ): ?array {
		$months = 'jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec';

		foreach ( preg_split( '/[\r\n]+/', $text ) as $line ) {
			if ( ! preg_match( $anchor, $line, $km, PREG_OFFSET_CAPTURE ) ) continue;
			// Skip the labour / fee / receipt lines that merely contain "installation".
			if ( preg_match( '/installation (included|of|receipt)|tax and installation|receipt\s*[-:]/i', $line ) ) continue;
			// Weak-anchor pass only: skip a revision / restored / edit note (it carries
			// a bookkeeping date, not an install date).
			if ( $skip_rev && preg_match( '/\brev(?:ision)?\.?\s*\d*\s*[:.\-]|\brestored\b|\brevised\b/i', $line ) ) continue;

			// Only look AFTER the keyword, so a location code or phone number before
			// it can never be read as the date.
			$tail = substr( $line, (int) $km[0][1] );

			$date = null;
			if ( preg_match( '/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $tail, $m ) ) {
				$date = self::ymd( (int) $m[1], (int) $m[2], (int) $m[3] );
			} elseif ( preg_match( '#\b(0?[1-9]|1[0-2])[/\-](0?[1-9]|[12]\d|3[01])(?:[/\-](\d{2,4}))?\b#', $tail, $m ) ) {
				$mo = (int) $m[1]; $day = (int) $m[2];
				if ( isset( $m[3] ) && $m[3] !== '' ) {
					$yr = (int) $m[3];
					if ( $yr < 100 ) $yr += 2000;
				} else {
					// No year written ("8/4"): assume this year, but roll to next year
					// if that date is already well in the past (a Dec estimate → Jan job).
					$yr    = (int) gmdate( 'Y' );
					$guess = mktime( 12, 0, 0, $mo, $day, $yr );
					if ( $guess && $guess < time() - 180 * 86400 ) $yr++;
				}
				$date = self::ymd( $yr, $mo, $day );
			} elseif ( preg_match( '/\b(' . $months . ')[a-z]*\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s*(\d{4}))?/i', $tail, $m ) ) {
				$ts = strtotime( $m[0] );
				if ( $ts ) $date = gmdate( 'Y-m-d', $ts );
			}
			if ( ! $date ) continue;

			$hour = null; $minute = 0;
			if ( preg_match( '/\b(\d{1,2})(?::(\d{2}))?\s*(a\.?m\.?|p\.?m\.?)/i', $tail, $t ) ) {
				$hour = (int) $t[1] % 12;
				if ( stripos( ltrim( $t[3] ), 'p' ) === 0 ) $hour += 12;
				$minute = ( isset( $t[2] ) && $t[2] !== '' ) ? (int) $t[2] : 0;
			}
			$head = substr( $line, 0, (int) $km[0][1] );
			return [ 'date' => $date, 'hour' => $hour, 'minute' => $minute, 'assignee' => self::extract_assignee( $head ) ];
		}
		return null;
	}

	/**
	 * Pull the installer / salesperson initials from the text BEFORE "Sched" —
	 * parenthesised "(AB)" OR bare "AB" (two–three capitals, no space). The bare form
	 * is why we take the LAST all-caps token before the keyword: the city name comes
	 * first, the initials sit right against "Sched". A short stop-list keeps a state
	 * ("CA") or a colour code from being read as initials.
	 */
	private static function extract_assignee( string $head ): string {
		if ( preg_match( '/\(([A-Za-z]{2,3})\)/', $head, $m ) ) return strtoupper( $m[1] );
		if ( preg_match_all( '/(?<![A-Za-z])([A-Z]{2,3})(?![A-Za-z])/', $head, $ms ) ) {
			$stop = [ 'CA', 'USA', 'LLC', 'INC', 'TBD', 'TBT', 'PST', 'PDT', 'AM', 'PM' ];
			for ( $i = count( $ms[1] ) - 1; $i >= 0; $i-- ) {
				$c = strtoupper( $ms[1][ $i ] );
				if ( ! in_array( $c, $stop, true ) ) return $c;
			}
		}
		return '';
	}

	/** The installer initials alone (works even when no date is parseable). */
	public static function assignee_from_text( $text ): string {
		$text = self::flatten_notes( $text );
		if ( $text === '' ) return '';
		foreach ( preg_split( '/[\r\n]+/', $text ) as $line ) {
			if ( ! preg_match( '/\bsched/i', $line, $km, PREG_OFFSET_CAPTURE ) ) continue;
			if ( preg_match( '/installation (included|of|receipt)|tax and installation/i', $line ) ) continue;
			$a = self::extract_assignee( substr( $line, 0, (int) $km[0][1] ) );
			if ( $a !== '' ) return $a;
		}
		return '';
	}

	private static function ymd( int $y, int $mo, int $d ): ?string {
		if ( $mo < 1 || $mo > 12 || $d < 1 || $d > 31 ) return null;
		$ts = mktime( 0, 0, 0, $mo, $d, $y );
		return $ts ? gmdate( 'Y-m-d', $ts ) : null;
	}

	/** Convenience / test helper: just the Y-m-d, or null. */
	public static function scheduled_date_from_text( $text ): ?string {
		$p = self::parse( $text );
		return $p ? $p['date'] : null;
	}

	/**
	 * Wrap a parsed date into the same {state,start_utc,tz,spread,source} envelope
	 * entry the widget already renders for a real install date. Stored at the
	 * scheduled LOCAL time (or local midnight when no time is written) → UTC, so the
	 * widget's atLocalMidnight() heuristic shows a time only when there is one.
	 */
	public static function from_text( $text ): ?array {
		$p = self::parse( $text );
		if ( ! $p ) return null;
		try {
			$tz   = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
			$time = ( $p['hour'] === null ) ? '00:00:00' : sprintf( '%02d:%02d:00', $p['hour'], $p['minute'] );
			$dt   = new DateTime( $p['date'] . ' ' . $time, $tz );
			$name = $tz->getName();
			$dt->setTimezone( new DateTimeZone( 'UTC' ) );
			return [
				'state'     => 'scheduled',
				'start_utc' => $dt->format( 'Y-m-d H:i:s' ),
				'tz'        => $name,
				'spread'    => false,
				'source'    => 'estimate',
				'assignee'  => $p['assignee'] ?? '',
			];
		} catch ( Exception $e ) {
			return null;
		}
	}
}
