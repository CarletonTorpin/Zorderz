<?php
/**
 * ZANA_User_Memory — "About you": a few plain sentences about how a person likes to work with the chat.
 *
 * Memory is about the PERSON, never the company: company facts live in the business's facts list or the knowledge
 * library. Each person sees and can delete every sentence under "About you" (REST: GET / DELETE …/analytics/memory).
 *
 * How a sentence is made:
 *   - the person asks: "remember that I like the total first" → one model call phrases it as ONE neutral sentence that
 *     starts with their name (or says NONE); "forget that I…" → one call picks which saved sentence they mean;
 *   - optionally, once a week (Zorderz → Settings, off unless the business turns it on): the person's own messages of
 *     the last seven days are read for ONE habit that shows on at least three different days. Slow on purpose: at most
 *     one new sentence a person a week, none once twelve exist, at least eight messages that week, and the days the
 *     model names must be days the person really chatted — one busy afternoon is never a pattern.
 *
 * The model proposes; the server decides (sentence_problem): one short sentence, about the person by name, nothing
 * sensitive (health, family, beliefs, money of their own, judgement), nothing that looks like a company or customer
 * detail (money, an address, a link, a document number, a phone), and nothing already said. Every model call goes
 * through the Ai router (ZANA_LLM_Client: coded, budgeted, ledgered). The shared device has no memory; an account in
 * Privacy View saves and deletes nothing.
 *
 * Stored in wp_zana_memory (category 'about_user'). The chat's instructions get a short "ABOUT <NAME>" block
 * (prompt_block()) and are told never to promise a sentence was saved.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_User_Memory {

	const CATEGORY       = 'about_user';
	const MAX_SENTENCES  = 12;
	const MIN_DAYS       = 3;
	const MIN_MESSAGES   = 8;
	const MAX_LEN        = 220;
	const ENABLED_OPT    = 'zana_umem_enabled';   // "About you" at all (requests + the block); Core: on
	const WEEKLY_OPT     = 'zana_umem_weekly';    // the automatic weekly pass; Core: off
	const HOOK_WEEKLY    = 'zana_umem_weekly';
	const HOOK_STEP      = 'zana_umem_step';
	const QUEUE_OPT      = 'zana_umem_queue';
	const LAST_WEEK      = 'zana_umem_last_week'; // user meta: the ISO week of the last automatic sentence
	const USERS_PER_STEP = 2;

	/** Topics a sentence about a person must never carry (stems, each followed by \w* so every form matches). */
	const SENSITIVE = '/\b(?:health|medic|ill(?:ness)?\b|sick|diagnos|disease|diabet|cancer|surgery|hospital|doctor|therap|depress|anxiet|pregnan|baby|divorc|marri|wife|husband|girlfriend|boyfriend|partner\'s|kids?\b|children|son\b|daughter|religio|church|mosque|synagogue|pray|faith|politic|democrat|republican|vote|election|sexual|gay\b|lesbian|transgender|drink|alcohol|drunk|drug|salar|paycheck|debt|loan|mortgage|bankrupt|lawsuit|sued|arrest|criminal|password|social security|ssn\b|ethnic|immigra|visa\b|years? old|lazy|angry|rude|careless|incompetent|stupid|dumb)\w*/i';

	/** Marks of a company or customer detail: money, e-mail, links, document numbers, long numbers, phone numbers. */
	const COMPANY = '/(\$|@|https?:|www\.|\b(?:invoice|estimate|lead|job|order)\s*#?\s*\d|\b\d{5,}\b|\b\d{3}[-. ]\d{3}[-. ]\d{4}\b)/i';

	/** Tests hand in the model: callable( string $prompt, int $uid ) => string. */
	public static $model = null;

	/* ─────────────────────────── settings ─────────────────────────── */

	public static function enabled(): bool {
		return '0' !== (string) ( function_exists( 'get_option' ) ? get_option( self::ENABLED_OPT, '1' ) : '1' );
	}

	public static function weekly_enabled(): bool {
		return self::enabled() && '1' === (string) ( function_exists( 'get_option' ) ? get_option( self::WEEKLY_OPT, '0' ) : '0' );
	}

	/** No memory for this account: signed out, the shared device, or an account in Privacy View. */
	public static function refused( int $uid ): bool {
		if ( $uid <= 0 ) {
			return true;
		}
		if ( class_exists( 'ZANA_LLM_Client' ) && ZANA_LLM_Client::is_shared_device( $uid ) ) {
			return true;
		}
		return class_exists( 'ZANA_Pseudonym_Route' ) && ZANA_Pseudonym_Route::is_on( $uid );
	}

	/** The name a sentence starts with: first name, else the first word of the display name. */
	public static function name_for( int $uid ): string {
		$first = function_exists( 'get_user_meta' ) ? trim( (string) get_user_meta( $uid, 'first_name', true ) ) : '';
		if ( '' !== $first ) {
			return $first;
		}
		$u = function_exists( 'get_userdata' ) ? get_userdata( $uid ) : null;
		if ( ! $u ) {
			return '';
		}
		$dn = trim( (string) $u->display_name );
		return '' !== $dn ? (string) preg_split( '/\s+/', $dn )[0] : (string) $u->user_login;
	}

	/* ─────────────────────────── storage ──────────────────────────── */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zana_memory';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			category VARCHAR(40) NOT NULL DEFAULT '',
			fact_key VARCHAR(100) NOT NULL DEFAULT '',
			fact_value TEXT NOT NULL,
			source_session_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY user_cat (user_id, category)
		) " . $wpdb->get_charset_collate() . ';' );
	}

	/** This person's sentences, oldest first: [ { id, fact_value, created_at } ]. */
	public static function sentences( int $uid ): array {
		global $wpdb;
		if ( self::refused( $uid ) ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, fact_value, created_at FROM ' . self::table() . ' WHERE user_id = %d AND category = %s ORDER BY created_at ASC, id ASC LIMIT 50',   // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$uid,
			self::CATEGORY
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Save one sentence ($source 'weekly' or 'asked'). The row id, or a string saying why not. */
	public static function save( int $uid, string $sentence, string $source, int $session_id = 0, string $week = '' ) {
		global $wpdb;
		if ( self::refused( $uid ) ) {
			return __( 'no memory on this account', 'zorderz' );
		}
		$existing = self::sentences( $uid );
		$why      = self::sentence_problem( $sentence, self::name_for( $uid ), $existing );
		if ( '' !== $why ) {
			return $why;
		}
		if ( count( $existing ) >= self::MAX_SENTENCES ) {
			return __( 'the list is full: delete one first', 'zorderz' );
		}
		$now = current_time( 'mysql' );
		$wpdb->insert( self::table(), array(
			'user_id'           => $uid,
			'category'          => self::CATEGORY,
			'fact_key'          => 'weekly' === $source ? 'weekly_' . ( '' !== $week ? $week : self::week_key() ) : 'asked_' . gmdate( 'Ymd_His' ) . '_' . wp_rand( 100, 999 ),
			'fact_value'        => trim( $sentence ),
			'source_session_id' => $session_id,
			'created_at'        => $now,
			'updated_at'        => $now,
		), array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' ) );
		return (int) $wpdb->insert_id > 0 ? (int) $wpdb->insert_id : __( 'could not be saved', 'zorderz' );
	}

	/** Delete one of THIS person's sentences (never anyone else's: the person is in the WHERE). */
	public static function delete( int $uid, int $id ): bool {
		global $wpdb;
		if ( $uid <= 0 || $id <= 0 || ( class_exists( 'ZANA_Pseudonym_Route' ) && ZANA_Pseudonym_Route::is_on( $uid ) ) ) {
			return false;
		}
		return (bool) $wpdb->delete( self::table(), array( 'id' => $id, 'user_id' => $uid, 'category' => self::CATEGORY ), array( '%d', '%d', '%s' ) );
	}

	/* ─────────────────────────── the rules ────────────────────────── */

	/** Lower-cased words of 3+ letters, for the repeat test. */
	private static function words( $s ): array {
		preg_match_all( '/[a-z]{3,}/', strtolower( (string) $s ), $m );
		return array_values( array_unique( $m[0] ) );
	}

	/** True when $s says what an existing sentence already says (word overlap ≥ 60%). */
	public static function repeats( string $s, array $existing ): bool {
		$a = self::words( $s );
		if ( ! $a ) {
			return false;
		}
		foreach ( $existing as $row ) {
			$b     = self::words( is_array( $row ) ? ( $row['fact_value'] ?? '' ) : $row );
			$inter = count( array_intersect( $a, $b ) );
			$union = count( array_unique( array_merge( $a, $b ) ) );
			if ( $union > 0 && $inter / $union >= 0.6 ) {
				return true;
			}
		}
		return false;
	}

	/** '' when $sentence may be saved about $name; otherwise why not. */
	public static function sentence_problem( string $sentence, string $name, array $existing = array() ): string {
		$s = trim( (string) preg_replace( '/\s+/u', ' ', $sentence ) );
		if ( '' === $s || 'NONE' === strtoupper( $s ) ) {
			return __( 'nothing to save', 'zorderz' );
		}
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s );
		if ( $len < 15 || $len > self::MAX_LEN ) {
			return __( 'not one short sentence', 'zorderz' );
		}
		if ( preg_match( '/[\r\n]|[.!?]\s+[A-Z]/u', $s ) ) {   // a second sentence: a stop, a space and a capital
			return __( 'more than one sentence', 'zorderz' );
		}
		if ( '' !== $name && 0 !== stripos( $s, $name ) ) {
			/* translators: %s: the person's first name */
			return sprintf( __( 'not a sentence about %s', 'zorderz' ), $name );
		}
		if ( preg_match( self::SENSITIVE, $s ) ) {
			return __( 'personal or judgemental: not kept', 'zorderz' );
		}
		if ( preg_match( self::COMPANY, $s ) ) {
			return __( 'looks like a company or customer detail: those belong in the business\'s facts or the knowledge library', 'zorderz' );
		}
		if ( self::repeats( $s, $existing ) ) {
			return __( 'already known', 'zorderz' );
		}
		return '';
	}

	/**
	 * 'remember' | 'forget' | '' — only a REQUEST about the speaker themselves. The verb opens the message or a
	 * sentence, or follows "please" / "can you", so "I remember I sent it" or "I don't remember the price" are
	 * conversation; "forget it, show me June" and "forget about the Example Co estimate" are not about the person.
	 */
	public static function explicit_intent( string $message ): string {
		$m    = strtolower( trim( $message ) );
		$lead = '(?:^|[.!?]\s+|[,;:]\s*|\b(?:please|hey|also|fyi|ok|okay|and)\s+|\b(?:can|could|would|will)\s+you\s+(?:please\s+)?|\bi\s+(?:want|need|would like)\s+you\s+to\s+|\b(?:from now on|going forward|in future|in the future),?\s+)';
		if ( preg_match( '/' . $lead . '(?:forget|stop remembering)\s+(?:that|what|about|everything|the (?:fact|note|sentence) that)\b[^.?!,]{0,30}?\b(?:i|i\'m|i am|i\'ve|my|me)\b/', $m )
			|| preg_match( '/' . $lead . '(?:delete|remove) (?:that|the|this) memory\b/', $m ) ) {
			return 'forget';
		}
		if ( preg_match( '/' . $lead . '(?:remember|note|keep in mind|make a note)\b[^.?!]{0,30}?\b(?:i|i\'m|i am|i\'ve|my|me)\b/', $m ) ) {
			return 'remember';
		}
		return '';
	}

	/**
	 * A "remember that I…" or "forget that…" request in chat (after the turn's answer). One model call phrases or
	 * picks; the server decides. A short outcome for the log.
	 */
	public static function handle_turn( int $uid, string $message, int $session_id = 0 ): string {
		if ( ! self::enabled() || self::refused( $uid ) ) {
			return 'skipped';
		}
		$intent = self::explicit_intent( $message );
		if ( '' === $intent ) {
			return 'no request';
		}
		$name     = self::name_for( $uid );
		$existing = self::sentences( $uid );
		if ( 'forget' === $intent ) {
			if ( ! $existing ) {
				return 'nothing to forget';
			}
			$list = '';
			foreach ( $existing as $r ) {
				$list .= '(' . (int) $r['id'] . ') ' . $r['fact_value'] . "\n";
			}
			$out = self::ask( "The person below asked the assistant to forget something about them. From the saved sentences, give the number of the ONE sentence they mean, or NONE if none clearly matches. Answer with only the number or NONE.\n\nREQUEST: " . mb_substr( $message, 0, 600 ) . "\n\nSAVED SENTENCES:\n" . $list, $uid );
			$id  = self::picked_id( $out, $existing );
			if ( $id > 0 && self::delete( $uid, $id ) ) {
				return 'forgot #' . $id;
			}
			return 'no match to forget';
		}
		$out   = self::ask( 'Rewrite what this person asked the assistant to remember ABOUT THEMSELVES as ONE short, neutral sentence in the third person that starts with "' . $name . '" (for example: "' . $name . ' prefers short answers with the total first."). Only how they work, what they prefer, or what they are responsible for. If the request is about the company, a customer, a price or any other fact that is not about the person, or is personal or sensitive, answer NONE.' . "\n\nREQUEST: " . mb_substr( $message, 0, 600 ), $uid );
		$saved = self::save( $uid, trim( $out ), 'asked', $session_id );
		return is_int( $saved ) ? 'saved #' . $saved : 'not saved: ' . $saved;
	}

	/** The id a forget reply names — only when the reply IS one of the person's own ids. 0 otherwise. */
	public static function picked_id( string $reply, array $existing ): int {
		if ( ! preg_match( '/^\s*[\[(]?(\d+)[\])]?\s*\.?\s*$/', $reply, $m ) ) {
			return 0;
		}
		foreach ( $existing as $r ) {
			if ( (int) $r['id'] === (int) $m[1] ) {
				return (int) $m[1];
			}
		}
		return 0;
	}

	/** One short model call through the Ai router; '' on any failure. $uid 0 = background work (charged to no one). */
	private static function ask( string $prompt, int $uid ): string {
		if ( is_callable( self::$model ) ) {
			return trim( (string) call_user_func( self::$model, $prompt, $uid ) );
		}
		if ( ! class_exists( 'ZANA_LLM_Client' ) ) {
			return '';
		}
		$r = ( new ZANA_LLM_Client( $uid ) )->chat( array( array( 'role' => 'user', 'content' => $prompt ) ), array( 'budget_s' => 60, 'max_tokens' => 400 ) );
		return ! empty( $r['ok'] ) ? trim( (string) $r['text'] ) : '';
	}

	/* ─────────────────────────── the week ─────────────────────────── */

	/** ISO week, e.g. 2026-W39. */
	public static function week_key( $ts = null ): string {
		return gmdate( 'o-\WW', null === $ts ? time() : (int) $ts );
	}

	/** Keep the weekly pass scheduled while it is on; clear it when it is off. */
	public static function schedule(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}
		if ( ! self::weekly_enabled() ) {
			wp_clear_scheduled_hook( self::HOOK_WEEKLY );
			wp_clear_scheduled_hook( self::HOOK_STEP );
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK_WEEKLY ) ) {
			$next = strtotime( 'next sunday 09:00 UTC' );
			wp_schedule_event( $next ? $next : time() + DAY_IN_SECONDS, 'weekly', self::HOOK_WEEKLY );
		}
	}

	/** Weekly: queue every person who chatted in the last seven days (never the shared device). */
	public static function weekly_start(): void {
		global $wpdb;
		if ( ! self::weekly_enabled() ) {
			return;
		}
		$ids   = $wpdb->get_col( $wpdb->prepare(
			'SELECT DISTINCT user_id FROM ' . ZANA_DB::messages_table() . " WHERE role = 'user' AND created_at >= %s AND user_id > 0",   // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			self::since( 7 )
		) );
		$queue = array_values( array_filter( array_map( 'intval', (array) $ids ), function ( $u ) {
			return $u > 0 && ! self::refused( $u );
		} ) );
		update_option( self::QUEUE_OPT, array( 'week' => self::week_key(), 'users' => $queue ), false );
		if ( $queue ) {
			wp_schedule_single_event( time() + 60, self::HOOK_STEP );
		}
	}

	/** A few people per run, so no run nears the request wall. Each person is taken off the queue before the work. */
	public static function step(): void {
		$q = get_option( self::QUEUE_OPT, array() );
		if ( ! is_array( $q ) || empty( $q['users'] ) || ! self::weekly_enabled() ) {
			return;
		}
		$n = 0;
		while ( $n < self::USERS_PER_STEP && ! empty( $q['users'] ) ) {
			$uid = (int) array_shift( $q['users'] );
			update_option( self::QUEUE_OPT, $q, false );
			$n++;
			self::analyze_user( $uid, (string) ( $q['week'] ?? self::week_key() ) );
		}
		if ( ! empty( $q['users'] ) ) {
			wp_schedule_single_event( time() + 120, self::HOOK_STEP );
		}
	}

	/** This person's own words from the last $days days: array( 'Y-m-d' => [texts] ). Never the assistant's, never anyone else's. */
	public static function recent_messages( int $uid, int $days = 7 ): array {
		global $wpdb;
		$rows   = $wpdb->get_results( $wpdb->prepare(
			'SELECT body, created_at FROM ' . ZANA_DB::messages_table() . " WHERE user_id = %d AND role = 'user' AND created_at >= %s ORDER BY created_at ASC LIMIT 400",   // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$uid,
			self::since( $days )
		), ARRAY_A );
		$by_day = array();
		foreach ( (array) $rows as $r ) {
			$by_day[ substr( (string) $r['created_at'], 0, 10 ) ][] = trim( mb_substr( wp_strip_all_tags( (string) $r['body'] ), 0, 300 ) );
		}
		return $by_day;
	}

	/** The weekly decision for one person. A short outcome; every "no" is the normal outcome. */
	public static function analyze_user( int $uid, string $week = '', ?array $by_day = null ): string {
		$week = '' !== $week ? $week : self::week_key();
		if ( ! self::weekly_enabled() || self::refused( $uid ) ) {
			return 'skipped';
		}
		if ( (string) get_user_meta( $uid, self::LAST_WEEK, true ) === $week ) {
			return 'already had a sentence this week';
		}
		$existing = self::sentences( $uid );
		if ( count( $existing ) >= self::MAX_SENTENCES ) {
			return 'list full';
		}
		$by_day = null === $by_day ? self::recent_messages( $uid ) : $by_day;
		$total  = array_sum( array_map( 'count', $by_day ) );
		if ( count( $by_day ) < self::MIN_DAYS || $total < self::MIN_MESSAGES ) {
			return 'too little this week';
		}
		$name  = self::name_for( $uid );
		$chats = '';
		foreach ( $by_day as $day => $texts ) {
			$chats .= "\n=== {$day} ===\n- " . implode( "\n- ", $texts ) . "\n";
		}
		$known = '';
		foreach ( $existing as $r ) {
			$known .= '- ' . $r['fact_value'] . "\n";
		}
		$prompt = "Below are the messages {$name} typed to a business assistant over the last week, grouped by day.\n"
			. "Is there ONE clear habit in HOW {$name} works with the assistant — what they tend to ask for, when, or how they like answers — that shows on at least "
			. self::MIN_DAYS . " DIFFERENT days? If so, write ONE short, neutral sentence starting with \"{$name}\" "
			. "(for example: \"{$name} usually asks for the week's totals on Monday mornings and likes the total first.\").\n"
			. 'Rules: nothing about health, family, feelings, politics, religion, money of their own or anything private; no judgement; '
			. 'no customer names, prices, figures or company facts; nothing already covered by the sentences we have. '
			. "When in doubt, answer NONE — most weeks the answer is NONE.\n"
			. "Reply ONLY with JSON: {\"sentence\":\"...\" or \"NONE\", \"days\":[\"YYYY-MM-DD\", ...]} listing the days the habit shows.\n\n"
			. "SENTENCES WE ALREADY HAVE:\n" . ( '' !== $known ? $known : "(none)\n" )
			. "\nMESSAGES:" . mb_substr( $chats, 0, 14000 );
		$json = self::parse_json( self::ask( $prompt, 0 ) );
		if ( ! is_array( $json ) ) {
			return 'no usable answer';
		}
		$sentence = trim( (string) ( $json['sentence'] ?? '' ) );
		if ( '' === $sentence || 'NONE' === strtoupper( $sentence ) ) {
			return 'no pattern this week';
		}
		$days = self::real_days( (array) ( $json['days'] ?? array() ), $by_day );
		if ( count( $days ) < self::MIN_DAYS ) {
			return 'pattern not seen on enough real days';
		}
		$saved = self::save( $uid, $sentence, 'weekly', 0, $week );
		if ( is_int( $saved ) ) {
			update_user_meta( $uid, self::LAST_WEEK, $week );
			return 'saved #' . $saved;
		}
		return 'refused: ' . $saved;
	}

	/** The days a model named that the person really chatted on (unique). Pure. */
	public static function real_days( array $named, array $by_day ): array {
		return array_values( array_unique( array_filter( array_map( 'strval', $named ), function ( $d ) use ( $by_day ) {
			return isset( $by_day[ $d ] );
		} ) ) );
	}

	/** N days back in the site's clock (the chat stores created_at in site time). */
	private static function since( int $days ): string {
		$now = function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time();
		return gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS );
	}

	public static function parse_json( string $text ): ?array {
		$t = trim( (string) preg_replace( '/^```(?:json)?|```$/m', '', $text ) );
		$a = strpos( $t, '{' );
		$b = strrpos( $t, '}' );
		if ( false === $a || false === $b || $b <= $a ) {
			return null;
		}
		$j = json_decode( substr( $t, $a, $b - $a + 1 ), true );
		return is_array( $j ) ? $j : null;
	}

	/* ─────────────────────────── the instructions ─────────────────── */

	/** The block the chat's instructions get. '' when there is nothing to say. */
	public static function prompt_block( int $uid ): string {
		if ( ! self::enabled() || self::refused( $uid ) ) {
			return '';
		}
		$rows = self::sentences( $uid );
		$name = self::name_for( $uid );
		$out  = 'ABOUT ' . strtoupper( '' !== $name ? $name : 'THIS PERSON' ) . "\n";
		if ( $rows ) {
			$out .= "Short notes about how this person likes to work, from their own chats or because they asked:\n";
			foreach ( $rows as $r ) {
				$out .= '- ' . $r['fact_value'] . "\n";
			}
			$out .= "Use them only to shape HOW you answer this person. They are not facts about the company, customers or figures, never quote them back unprompted, and let anything the person says now override them.\n";
		}
		$out .= 'Memory holds only short sentences about the person. If they ask you to remember something about THEMSELVES, say it will be added to "About you" if it fits (the server checks it; do not promise it was saved). A fact about the company or a customer is not kept in memory. They can see and delete every sentence under "About you".';
		return $out;
	}
}
