<?php
/**
 * ZANA_Hours_Route — "how many hours did I work this week?" asks the Hours app.
 *
 * A rule route: a narrow detector, the Hours app's own read verb `hours.lookup` asked through the registry as the
 * signed-in person, and an answer rendered from what Hours itself says (Hours writes the sentence; nothing here sums
 * or guesses). The model is never asked.
 *
 * Only the ASKER'S OWN hours ("my", "I"), and only for a period Hours names (today, yesterday, this / last week, this /
 * last month, this year). Another person, a job, a pay period, a month by name, "hours of operation", "what hours are
 * you open": not this route (the chat goes on as usual). Never the shared device, and only for someone the registry
 * lets use the verb (ZDZ_Capabilities::can); Hours checks again and names the asker from the session, never from
 * anything here.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Hours_Route {

	const VERB = 'hours.lookup';

	/** The last line of every answer: a follow-up ("and last week?") is read against it. */
	const MARK = '_From Hours._';

	/** Tests hand in: may this person use hours.lookup (bool), and the verb's answer (callable $uid, $payload). */
	public static $can    = null;
	public static $invoke = null;

	/** Period words => the Hours app's period keys. Longest first. */
	const PERIODS = array(
		'so far this week' => 'this_week',
		'this week so far' => 'this_week',
		'this week'        => 'this_week',
		'last week'        => 'last_week',
		'this month'       => 'this_month',
		'last month'       => 'last_month',
		'this year'        => 'this_year',
		'today'            => 'today',
		'yesterday'        => 'yesterday',
	);

	/**
	 * The words a question may hold besides the hours phrase and ONE period from PERIODS; anything else is not this
	 * route. No period word on its own (week, month, year, last, this, all) and no digit: "last year", "for the month",
	 * "2025", "week 42", "10/14 to 10/18" are periods Hours does not name.
	 */
	const OK_WORDS = '/^(?:how|many|much|long|what|what\'s|whats|are|is|was|were|did|do|does|have|has|had|been|am|i|i\'ve|ive|i\'m|im|me|my|mine|hours?|hrs?|total|totals|work|worked|working|clock|clocked|clocking|log|logged|put|in|so|far|the|a|for|of|at|up|to|now|currently|show|tell|give|can|could|you|please|check|see|get|got|and|ok|okay|hey|altogether)$/';

	/** A request to ADD hours ("log my hours", "put in my hours") -- Hours' own screen, not a read. */
	const WRITE_WORDS = '/\b(?:log|put|add|enter|record|submit|fill|clock)\b/';
	const ASKED_PAST  = '/\b(?:did|have|has|had)\s+i\s+(?:log|put|clock)\b|\bi(?:\'ve|\s+have)?\s+(?:logged|put in|clocked)\b|\bhours?\s+(?:i\s+)?(?:logged|put in|clocked)\b/';

	/**
	 * Is this the asker asking about their own hours worked? Returns array( 'period' => key ) or null.
	 * Pure (no WordPress): the words only.
	 */
	public static function detect( $message ) {
		$m = strtolower( str_replace( array( "\u{2019}", "\u{2018}", '`' ), "'", (string) $message ) );
		$m = trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[?!.,;:]+/', ' ', $m ) ) );
		if ( '' === $m || strlen( $m ) > 160 ) {
			return null;
		}
		// hours worked, by the asker: "my hours", "how many hours did / have I ...", "hours I worked"
		$mine = preg_match( '/\bmy (?:(?:work(?:ed)?|logged|clocked|total) )?(?:hours|hrs)\b/', $m )
			|| preg_match( '/\bhow many (?:hours|hrs)\b.*\b(?:did|have|do|am|has) i\b/', $m )
			|| preg_match( '/\b(?:hours|hrs) (?:have |did )?i(?:\'ve| have)? (?:work|worked|clocked|logged|put in)\b/', $m )
			|| preg_match( '/\bhow (?:long|much) (?:did|have) i (?:work|worked|clocked)\b/', $m );
		if ( ! $mine ) {
			return null;
		}
		if ( preg_match( self::WRITE_WORDS, $m ) && ! preg_match( self::ASKED_PAST, $m ) ) {
			return null; // "log my hours for today": the person wants to add hours
		}
		$period = 'this_week'; // "my hours" alone: this week (Hours' own default)
		$rest   = ' ' . $m . ' ';
		$found  = 0;
		foreach ( self::PERIODS as $w => $k ) {
			if ( false !== strpos( $rest, ' ' . $w . ' ' ) ) {
				if ( $found && $k !== $period ) {
					return null; // two periods ("this week vs last week"): not this route
				}
				$period = $k;
				$found++;
				$rest = str_replace( ' ' . $w . ' ', ' ', $rest );
			}
		}
		foreach ( preg_split( '/[^a-z0-9\']+/', $rest, -1, PREG_SPLIT_NO_EMPTY ) as $w ) { // digits kept, so they fail
			// every other word must be one OK_WORDS knows: this is what refuses another meaning of hours ("hours of
			// operation", "what hours are you open", "your hours"), someone else's ("for alex", "the crew"), a job's ("on the
			// example job"), the schedule ("scheduled"), pay ("paid", "pay period") and a period Hours does not name ("in
			// september", "since monday")
			if ( ! preg_match( self::OK_WORDS, trim( $w, "'" ) ) ) {
				return null;
			}
		}
		return array( 'period' => $period );
	}

	/** A short follow-up right after an hours answer: "and last week?", "what about today?". */
	public static function follow_up( $message, array $history ) {
		$last = '';
		for ( $i = count( $history ) - 1; $i >= 0; $i-- ) {
			if ( 'assistant' === strtolower( (string) ( $history[ $i ]['role'] ?? '' ) ) ) {
				$last = (string) ( $history[ $i ]['content'] ?? '' );
				break;
			}
		}
		if ( false === strpos( $last, self::MARK ) ) {
			return null;
		}
		$m = strtolower( trim( (string) preg_replace( '/[?!.,;:]+/', ' ', (string) $message ) ) );
		// the longest lead-in first, so "and what about last week?" leaves only the period
		$m = trim( (string) preg_replace( '/^(?:and what about|and how about|and for|what about|how about|and|ok|okay|so)\s+/', '', $m ) );
		$m = trim( (string) preg_replace( '/\s+/', ' ', $m ) );
		return isset( self::PERIODS[ $m ] ) ? array( 'period' => self::PERIODS[ $m ] ) : null;
	}

	/** May this person ask Hours (the registry's own check; Hours checks again)? */
	public static function can( int $uid ): bool {
		if ( null !== self::$can ) {
			return (bool) self::$can;
		}
		return $uid > 0 && class_exists( 'ZDZ_Capabilities' ) && ZDZ_Capabilities::can( self::VERB, $uid );
	}

	/**
	 * The route: the answer for this turn, or null when it is not an hours question for this person (the chat goes on
	 * as usual). Never for the shared device.
	 */
	public static function route( int $uid, string $message, array $history = array(), bool $shared_device = false ): ?string {
		if ( $shared_device || $uid <= 0 || '' === trim( $message ) ) {
			return null;
		}
		$h = self::detect( $message );
		if ( null === $h && $history ) {
			$h = self::follow_up( $message, $history );
		}
		if ( null === $h || ! self::can( $uid ) ) {
			return null;
		}
		return self::answer( $h, $uid );
	}

	/** The answer, whole (markdown). Hours writes the sentence; this only frames it. */
	public static function answer( array $h, int $uid ): string {
		$key = (string) ( $h['period'] ?? 'this_week' );
		try {
			$r = self::invoke( $uid, array( 'period' => $key ) );
		} catch ( \Throwable $e ) {
			$r = array( 'success' => false );
		}
		$label = strtolower( (string) ( $r['period']['label'] ?? str_replace( '_', ' ', $key ) ) );
		$msg   = trim( (string) ( $r['message'] ?? '' ) );
		if ( ! empty( $r['success'] ) && empty( $r['denied'] ) && '' !== $msg ) {
			/* translators: %s: the period, e.g. "this week" */
			return '**' . sprintf( __( 'Your hours · %s', 'zorderz' ), $label ) . "**\n\n" . $msg . "\n\n" . self::MARK;
		}
		if ( ! empty( $r['denied'] ) || ! empty( $r['needs_clarify'] ) ) {
			return '' !== $msg ? $msg : __( 'Hours isn\'t available on this account.', 'zorderz' );
		}
		return "\u{26A0}\u{FE0F} " . __( 'I couldn\'t read your hours just now. Please ask again in a minute, or open Hours.', 'zorderz' );
	}

	/** Hours, through the registry, as the signed-in person (the registry names them; nothing here does). */
	private static function invoke( int $uid, array $payload ): array {
		if ( is_callable( self::$invoke ) ) {
			return (array) call_user_func( self::$invoke, $uid, $payload );
		}
		if ( class_exists( 'ZDZ_Capabilities' ) && function_exists( 'get_current_user_id' ) && (int) get_current_user_id() === $uid ) {
			return (array) ZDZ_Capabilities::invoke( self::VERB, $payload );
		}
		return array( 'success' => false, 'error' => 'not_session_user' );
	}
}
