<?php
/**
 * ZANA_Research_Gate — who may start a deep research run, and when the chat may offer one.
 *
 * A run spends real money and reads the web, so every answer here is "no" until each condition says yes:
 *
 *   the feature     switched on by an administrator, and the site's Ai set up (a budget, a model, the workspace);
 *   the person      signed in; an administrator-level account; holding "Deep research queries" (Zorderz → Data
 *                   Permissions); never the shared device, never a Privacy View account, never inside Demo Mode;
 *   the limits      the person's daily chat allowance, the site's Ai calls for research today, the person's runs
 *                   and research cost today, and one run at a time a person.
 *
 * A missing part of the platform (the permissions class, the tier rule) is a "no", not a pass.
 *
 * could_ever() is who may be TOLD about deep research at all: someone it could never run for gets no button. A few are
 * never told anything about it, not even why it did not run (silent()): the shared device, a Privacy View account,
 * Demo Mode, and a reply the assistant writes in a Messages thread. refusal() is why a run cannot start right now, in
 * words for the person, asked in this order: who they are (an account below administrator level hears only that it
 * is not available to them), then the site (switched off, not set up), then their permission, then the limits.
 *
 * It also holds the two sentences the chat's own buttons send ("Deep research on my question above #<tag>", "Keep
 * going on deep research #<id>") and reads them back. A sentence is only a request: every gate is asked again when
 * it arrives.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Research_Gate {

	/** A run is not started when fewer Ai calls than this are left for research today: it could not finish. */
	const START_MIN_DAY_CALLS = 20;

	/** The button is offered under an answer only when the question had at least this many of the person's own words. */
	const OFFER_MIN_WORDS = 6;

	const SAY_DEEPER = 'Deep research on my question above';
	const SAY_MORE   = 'Keep going on deep research #';

	/** Tests: callable(): bool — stands in for "this request is inside Demo Mode". */
	public static $demo = null;

	/** Tests: callable( int $uid, string $key ): bool — stands in for the platform's data permissions. */
	public static $permission = null;

	/** Tests: callable( int $uid ): bool — stands in for "a reply in a Messages thread is being written". */
	public static $messages_turn = null;

	/* ================================================================== the person */

	/** Is this someone deep research could run for at all (whatever today's limits say)? */
	public static function could_ever( int $uid ): bool {
		return '' === self::person_refusal( $uid );
	}

	/**
	 * Is this a turn where deep research is never mentioned at all: no run, no button, no "did not run" note? The
	 * shared device, a Privacy View account, Demo Mode, and a reply written in a Messages thread (a run is never
	 * started or continued from one).
	 */
	public static function silent( int $uid ): bool {
		if ( $uid <= 0 || self::in_messages_reply( $uid ) || self::demo() || self::privacy_view( $uid ) ) {
			return true;
		}
		return ! class_exists( 'ZANA_LLM_Client' ) || ZANA_LLM_Client::is_shared_device( $uid );
	}

	/** Is the assistant writing a reply in a Messages thread in this request? */
	public static function in_messages_reply( int $uid = 0 ): bool {
		if ( is_callable( self::$messages_turn ) ) {
			return (bool) call_user_func( self::$messages_turn, $uid );
		}
		return class_exists( 'ZANA_PV_DM_Agent' ) && method_exists( 'ZANA_PV_DM_Agent', 'in_turn' ) && ZANA_PV_DM_Agent::in_turn();
	}

	private static function demo(): bool {
		return is_callable( self::$demo ) ? (bool) call_user_func( self::$demo ) : ( class_exists( 'ZDZ_Kiosk_Demo' ) && ZDZ_Kiosk_Demo::is_active() );
	}

	/** A data permission of this person's (Zorderz → Data Permissions). Without the platform's permissions: no. */
	public static function can( int $uid, string $key ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		if ( is_callable( self::$permission ) ) {
			return (bool) call_user_func( self::$permission, $uid, $key );
		}
		return class_exists( 'ZDZ_Data_Permissions' ) && ZDZ_Data_Permissions::can( $uid, $key );
	}

	/** Why deep research can never run for this account as it is; '' when it can. */
	public static function person_refusal( int $uid ): string {
		$no = self::who_refusal( $uid );
		return '' !== $no ? $no : self::permission_refusal( $uid );
	}

	/** Who the person is: signed in, their own device, not in Demo Mode or Privacy View, an administrator-level account. */
	private static function who_refusal( int $uid ): string {
		if ( $uid <= 0 ) {
			return __( 'Deep research needs a signed-in account', 'zorderz' );
		}
		if ( ! class_exists( 'ZANA_LLM_Client' ) || ZANA_LLM_Client::is_shared_device( $uid ) ) {
			return __( 'Deep research is not available on the shared device', 'zorderz' );
		}
		if ( self::demo() ) {
			return __( 'Deep research is not available in Demo Mode', 'zorderz' );
		}
		if ( self::privacy_view( $uid ) ) {
			return __( 'Deep research is not available in Privacy View', 'zorderz' );
		}
		if ( ! class_exists( 'ZDZ_Capabilities' ) || 'admin' !== ZDZ_Capabilities::tier_of( $uid ) ) {
			return __( 'Deep research isn\'t available on this account', 'zorderz' );
		}
		return '';
	}

	/** The person's own permission for deep research. */
	private static function permission_refusal( int $uid ): string {
		if ( ! self::can( $uid, 'access_deep_research' ) ) {
			return __( 'Deep research isn\'t switched on for this account (an administrator sets it on Zorderz → Data Permissions)', 'zorderz' );
		}
		return '';
	}

	/** A Privacy View account, by either of the two places that know: either one saying yes is a yes. */
	public static function privacy_view( int $uid ): bool {
		if ( class_exists( 'ZANA_Pseudonym_Route' ) && ZANA_Pseudonym_Route::is_on( $uid ) ) {
			return true;
		}
		return class_exists( 'ZDZ_Privacy_View' ) && method_exists( 'ZDZ_Privacy_View', 'is_on' ) && ZDZ_Privacy_View::is_on( $uid );
	}

	/** May this person's run search the web and have outside facts checked? */
	public static function web_allowed( int $uid ): bool {
		return self::can( $uid, 'access_web_research' );
	}

	/* ================================================================== right now */

	/** Why the feature itself cannot run on this site right now; '' when it can. */
	public static function site_refusal(): string {
		if ( ! class_exists( 'ZANA_Research_Settings' ) || ! ZANA_Research_Settings::enabled() ) {
			return __( 'Deep research is switched off (an administrator turns it on under Zorderz → Deep research)', 'zorderz' );
		}
		$missing = ZANA_Research_Settings::missing();
		if ( $missing ) {
			/* translators: %s: what is missing, in words */
			return sprintf( __( 'Deep research is not set up on this site yet: %s', 'zorderz' ), implode( '; ', $missing ) );
		}
		return '';
	}

	/** Ai calls left for research today, the whole site. */
	public static function day_calls_left(): int {
		return max( 0, (int) ZANA_Research_Settings::get( 'day_calls' ) - ZANA_Research_Store::day_calls() );
	}

	/** "it starts again at <time>": when the day count starts over, in the site's own time. */
	public static function day_reset_words(): string {
		$t = ZANA_Research_Store::day_resets_at();
		return function_exists( 'wp_date' ) ? (string) wp_date( (string) get_option( 'time_format', 'H:i' ), $t ) : gmdate( 'H:i', $t ) . ' UTC';
	}

	/**
	 * Why this person cannot start a run right now; '' when they can. $continuing: a "Keep going" round of a run they
	 * already started today (it is not another run against the day's count).
	 */
	public static function refusal( int $uid, bool $continuing = false ): string {
		foreach ( array( self::who_refusal( $uid ), self::site_refusal(), self::permission_refusal( $uid ) ) as $no ) {
			if ( '' !== $no ) {
				return $no;
			}
		}
		$chat = class_exists( 'ZANA_Allowance' ) ? ZANA_Allowance::refusal( $uid ) : null;
		if ( null !== $chat ) {
			return rtrim( (string) $chat, '. ' );
		}
		$s = ZANA_Research_Settings::all();
		if ( self::day_calls_left() < self::START_MIN_DAY_CALLS ) {
			/* translators: 1: Ai calls used today, 2: the day's limit, 3: a time of day */
			return sprintf( __( 'too few of today\'s deep research Ai calls are left to finish a run (%1$d of %2$d Ai calls used); the count starts again at %3$s, or an administrator can raise "Ai calls a day" under Zorderz → Deep research', 'zorderz' ), ZANA_Research_Store::day_calls(), (int) $s['day_calls'], self::day_reset_words() );
		}
		list( $runs, $milli ) = ZANA_Research_Store::person_day( $uid );
		if ( ! $continuing && $runs >= (int) $s['person_runs_day'] ) {
			/* translators: %d: a number of runs */
			return sprintf( _n( 'you have already started %d deep research run today, which is the limit for one person', 'you have already started %d deep research runs today, which is the limit for one person', $runs, 'zorderz' ), $runs );
		}
		if ( $milli >= (int) round( (float) $s['person_usd_day'] * 1000 ) ) {
			/* translators: %s: an amount in US dollars */
			return sprintf( __( 'your deep research today has reached its limit of $%s for one person', 'zorderz' ), number_format( (float) $s['person_usd_day'], 2 ) );
		}
		$live = ZANA_Research_Store::live_for( $uid );
		if ( $live > 0 ) {
			/* translators: %d: the number of a research run */
			return sprintf( __( 'you already have deep research #%d in progress: stop it or let it finish first', 'zorderz' ), $live );
		}
		return '';
	}

	/* ================================================================== what was asked */

	/** The person's own words: without a quoted email, without a block shared from an app. */
	public static function own_words( string $message ): string {
		$own = class_exists( 'ZANA_Email_Turn' ) && method_exists( 'ZANA_Email_Turn', 'own_words' ) ? (string) ZANA_Email_Turn::own_words( $message ) : $message;
		foreach ( class_exists( 'ZANA_Markers' ) ? ZANA_Markers::fence_tokens() : array() as $t ) {
			$p = strpos( $own, $t );
			if ( false !== $p ) {
				$own = substr( $own, 0, $p );
			}
		}
		return $own;
	}

	/** Did the person ask for deep research by name ("deep research", "deep dive") in their own words? */
	public static function asked_by_name( string $message ): bool {
		return (bool) preg_match( '/\bdeep[\s-]*(?:research|dive)\b/i', self::own_words( $message ) );
	}

	public static function question_words( string $message ): int {
		return count( preg_split( '/\s+/u', trim( self::own_words( $message ) ), -1, PREG_SPLIT_NO_EMPTY ) );
	}

	/**
	 * May the chat put the "Go deeper" button under this answer? Only for someone who could start a run right now,
	 * on a question with enough of their own words, that was not itself a request for one, an email to write, or a
	 * press of one of the buttons.
	 *
	 * @param array $ctx email_task (bool), privacy_view (bool)
	 */
	public static function may_offer( int $uid, string $message, array $ctx = array() ): bool {
		if ( ! empty( $ctx['privacy_view'] ) || ! empty( $ctx['email_task'] ) || self::silent( $uid ) ) {
			return false;
		}
		if ( self::asked_by_name( $message ) || self::more_id( $message ) > 0 || self::is_deeper_press( $message ) ) {
			return false;
		}
		if ( self::question_words( $message ) < self::OFFER_MIN_WORDS ) {
			return false;
		}
		if ( class_exists( 'ZANA_Email_Turn' ) && ZANA_Email_Turn::is_email_task( $message ) ) {
			return false;
		}
		return '' === self::refusal( $uid );
	}

	/* ================================================================== the buttons' sentences */

	private static function flat( string $question ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $question ) : strip_tags( $question ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- only where WordPress is not loaded; the text is compared, never shown
	}

	/** Eight characters that stand for a question, so a button under one answer starts research on that question. */
	public static function tag( string $question ): string {
		return substr( md5( mb_strtolower( self::flat( $question ) ) ), 0, 8 );
	}

	public static function is_deeper_press( string $message ): bool {
		return (bool) preg_match( '/^\s*' . preg_quote( self::SAY_DEEPER, '/' ) . '(?:\s*#[0-9a-f]{8})?\s*$/i', $message );
	}

	/**
	 * The question a "Go deeper" press is about: the person's earlier message whose tag the press carries, else their
	 * newest question. null when the message is not that press; '' when there is no earlier question.
	 *
	 * @param array $history [ { role, content } ] oldest first, before this message
	 */
	public static function deeper_question( string $message, array $history ): ?string {
		if ( ! preg_match( '/^\s*' . preg_quote( self::SAY_DEEPER, '/' ) . '(?:\s*#([0-9a-f]{8}))?\s*$/i', $message, $m ) ) {
			return null;
		}
		$tag    = strtolower( (string) ( $m[1] ?? '' ) );
		$newest = '';
		foreach ( array_reverse( $history ) as $h ) {
			if ( ! is_array( $h ) || 'user' !== strtolower( (string) ( $h['role'] ?? '' ) ) || ! is_string( $h['content'] ?? null ) ) {
				continue;
			}
			$text = trim( $h['content'] );
			$flat = self::flat( $text );
			if ( '' === $flat || self::is_deeper_press( $flat ) || self::more_id( $flat ) > 0 ) {
				continue;   // a press is never the question
			}
			if ( '' !== $tag && self::tag( $text ) === $tag ) {
				return $text;
			}
			if ( '' === $newest ) {
				$newest = $text;
				if ( '' === $tag ) {
					break;
				}
			}
		}
		return $newest;
	}

	/** The run a "Keep going" press names, or 0. */
	public static function more_id( string $message ): int {
		return preg_match( '/^\s*' . preg_quote( self::SAY_MORE, '/' ) . '(\d{1,9})\s*$/', $message, $m ) ? (int) $m[1] : 0;
	}
}
