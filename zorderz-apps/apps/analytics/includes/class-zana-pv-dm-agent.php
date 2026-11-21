<?php
/**
 * ZANA_PV_DM_Agent — the assistant answers in a Privacy View account's Messages thread.
 *
 * An administrator may put an account in Privacy View and choose the ONE administrator it may message (Zorderz →
 * Privacy View). When that administrator writes in their direct-message thread with the account, the assistant can
 * answer there, as the account: with the account's own permissions, as in its chats. It answers and drafts; it never
 * carries anything out. Off until an administrator gives it a daily line (Zorderz → Settings).
 *
 *   the administrator posts in the thread
 *     └─ Messages: zim_message_posted ( message, conversation, author )
 *          └─ who(): the author is the administrator chosen for Privacy View accounts (checked every time), signed
 *             in and typing it themselves (never a scheduled job), and the conversation is THE direct conversation
 *             between them and an account in Privacy View; anything else: nothing happens
 *          └─ asks(): every message there wants an answer, except a short acknowledgement ("thanks", "ok")
 *          └─ one turn at a time for a thread (a message written meanwhile is answered next); at most 20 an hour;
 *             at most the day's line in dollars (then one note saying so, until tomorrow)
 *     └─ the turn runs off the administrator's request (a signed loopback, WP-Cron behind it, a watchdog behind both):
 *          the chat's own turn (ZANA_Chat::send) as the account, in the account's notes chat for this thread (never
 *          listed among its chats, closed to everything but these replies). What it reads first:
 *            how replies here work · notes from the chats the account's messages came from · the thread so far
 *          Each call's cap is what is left of the day's line, so the Ai router books no more than that.
 *     └─ the answer, as plain text, is posted in the thread by the account through Messages' own seam (zim_dm_send):
 *          Messages stores it with the real details for the administrator; the account sees it masked, like everything
 *          else it is shown, on every view. Something to do is written as a draft, never done, with a link that opens
 *          the administrator's own chat and sends their request there; the action waits for its own button.
 *
 * Money: the day's line is option `zana_pv_dm_daily_usd` (filter of the same name), 0 to MAX_USD dollars. Core: 0,
 * which means the account does not reply. What the replies cost is what the Ai router charged the turn's calls,
 * counted on the day (option `zana_pv_dm_spend`), a reply never counting for less than a twentieth of the line. When
 * the line cannot be read, or nothing is left, nothing is sent. The site's monthly Ai budget always applies first.
 *
 * Other code may ask in_turn() and skip work that does not belong in a Messages reply.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_PV_DM_Agent {

	const DAILY_OPT    = 'zana_pv_dm_daily_usd';   // dollars a day the replies may cost; Core: 0 (the account does not reply)
	const MAX_USD      = 50.0;
	const SESSIONS_OPT = 'zana_pv_dm_sessions';    // conversation id => the account's notes chat (session id)
	const SPEND_OPT    = 'zana_pv_dm_spend';       // array( 'day' => Y-m-d, 'milli' => int )
	const ASK_TTL      = 604800;                   // a draft's request link works for 7 days, once
	const HOURLY_MAX   = 20;
	const BUSY_TTL     = 900;
	const TURN_TTL     = 1800;
	const THREAD_MAX   = 30;                       // thread messages read
	const CHATS_MAX    = 4;                        // chats the account's messages came from, noted
	const QUESTION_MAX = 2000;                     // characters of the administrator's message
	const BODY_MAX     = 6000;                     // characters of a reply
	const WATCH_S      = 150;                      // the watchdog looks every 2½ minutes, five times
	const WATCH_TRIES  = 5;
	const RUN_ACTION   = 'zana_pv_dm_run';
	const WATCH_HOOK   = 'zana_pv_dm_watch';
	const ASK_PARAM    = 'zana_ask';
	const DRAFT_LINE   = 'Draft (nothing was done):';

	/** The turn this process is running: array( pv, session, conv, day, spent ) or null. */
	private static $running = null;

	/** Tests hand in the chat's turn: callable( int $pv, int $session, string $question, array $history ) => array. */
	public static $turn = null;

	/** Tests see a start instead of a loopback request: callable( int $conv, string $token ). */
	public static $kicker = null;

	public static function boot(): void {
		add_action( 'zim_message_posted', array( __CLASS__, 'on_posted' ), 10, 3 );
		add_action( 'wp_ajax_' . self::RUN_ACTION, array( __CLASS__, 'ajax_run' ) );
		add_action( 'wp_ajax_nopriv_' . self::RUN_ACTION, array( __CLASS__, 'ajax_run' ) );
		add_action( self::RUN_ACTION, array( __CLASS__, 'run' ), 10, 2 );
		add_action( self::WATCH_HOOK, array( __CLASS__, 'watch' ), 10, 3 );
		add_filter( 'zana_llm_call_cap_usd', array( __CLASS__, 'call_cap' ), 10, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'zdz_core_settings_rows', array( __CLASS__, 'render_setting_row' ) );
	}

	/* ================================================================== the day's line */

	/** The day's line in thousandths of a dollar; 0 = the account does not reply. */
	public static function daily_milli(): int {
		$v = function_exists( 'get_option' ) ? get_option( self::DAILY_OPT, 0 ) : 0;
		$v = is_numeric( $v ) ? (float) $v : 0.0;
		if ( function_exists( 'apply_filters' ) ) {
			$f = apply_filters( self::DAILY_OPT, $v );
			$v = is_numeric( $f ) ? (float) $f : 0.0;   // a line that cannot be read is no line: nothing is sent
		}
		return (int) round( max( 0.0, min( self::MAX_USD, $v ) ) * 1000 );
	}

	public static function sanitize_usd( $v ): float {
		$v = is_numeric( $v ) ? (float) $v : 0.0;
		return round( max( 0.0, min( self::MAX_USD, $v ) ), 2 );
	}

	/** On: a line is set, and Privacy View, Messages (with its send seam) and the chat are all here. */
	public static function enabled(): bool {
		return self::daily_milli() > 0
			&& class_exists( 'ZDZ_Privacy_View' ) && method_exists( 'ZDZ_Privacy_View', 'dm_partner' )
			&& class_exists( 'ZIM_Privacy_View' ) && class_exists( 'ZIM_DMs' ) && class_exists( 'ZIM_Messages' )
			&& class_exists( 'ZANA_Chat' )
			&& function_exists( 'has_filter' ) && (bool) has_filter( 'zim_dm_send' );
	}

	private static function today(): string {
		return class_exists( 'ZANA_Allowance' ) ? ZANA_Allowance::today() : gmdate( 'Y-m-d' );
	}

	public static function spent_today(): int {
		$s = function_exists( 'get_option' ) ? get_option( self::SPEND_OPT, array() ) : array();
		return ( is_array( $s ) && ( $s['day'] ?? '' ) === self::today() ) ? max( 0, (int) ( $s['milli'] ?? 0 ) ) : 0;
	}

	public static function add_spend( int $milli ): void {
		update_option( self::SPEND_OPT, array( 'day' => self::today(), 'milli' => self::spent_today() + max( 0, $milli ) ), false );
	}

	/** What is left of today's line, in thousandths of a dollar (0 when it is used up or off). */
	public static function left_milli(): int {
		return max( 0, self::daily_milli() - self::spent_today() );
	}

	/** A reply never counts for less than a twentieth of the day's line: a day holds at most twenty replies. */
	public static function floor_milli(): int {
		return max( 1, (int) ceil( self::daily_milli() / 20 ) );
	}

	/** What the account's chat has spent today, as the chat itself counts it (the router's charge for each call). */
	private static function counted( int $pv ): int {
		return class_exists( 'ZANA_Allowance' ) ? ZANA_Allowance::spent_milli( $pv ) : 0;
	}

	/** What the running turn has cost so far. */
	private static function turn_cost(): int {
		if ( null === self::$running ) {
			return 0;
		}
		$now = self::counted( (int) self::$running['pv'] );
		// past midnight the chat's own count starts again: everything on it is this turn's
		return self::today() === self::$running['day'] ? max( 0, $now - (int) self::$running['spent'] ) : $now;
	}

	/**
	 * Filter `zana_llm_call_cap_usd`: while a reply's turn runs, each of the account's calls may cost at most what is
	 * left of the day's line (the router's hold books no more), and nothing once it is used up. Never widens a cap an
	 * earlier callback set; every other person's calls are left alone.
	 */
	public static function call_cap( $cap, $uid = 0 ) {
		if ( null === self::$running || (int) $uid !== (int) self::$running['pv'] ) {
			return $cap;
		}
		$usd = max( 0, self::left_milli() - self::turn_cost() ) / 1000;
		return is_numeric( $cap ) ? min( (float) $cap, $usd ) : $usd;
	}

	/** Is a Messages reply's turn running in this process (optionally: for this person)? */
	public static function in_turn( int $uid = 0 ): bool {
		return null !== self::$running && ( $uid <= 0 || $uid === (int) self::$running['pv'] );
	}

	/* ================================================================== who and when */

	/**
	 * The Privacy View account whose thread this message is in, written by the administrator chosen for it, posted by
	 * them signed in (never a scheduled job, such as a reply that arrived by e-mail, where an automatic answer could
	 * start a loop, or a post something else made in their name); else 0.
	 */
	public static function who( int $message_id, int $conversation_id, int $author_id ): int {
		if ( (int) get_current_user_id() !== $author_id || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
			return 0;
		}
		return self::thread_of( $message_id, $conversation_id, $author_id );
	}

	/**
	 * The same without who is signed in: for a turn taken up later, checked again when it runs. The chosen
	 * administrator (still an administrator, still not in Privacy View), the thread and the account are read afresh.
	 */
	public static function thread_of( int $message_id, int $conversation_id, int $author_id ): int {
		if ( ! self::enabled() ) {
			return 0;
		}
		$partner = (int) ZDZ_Privacy_View::dm_partner();
		if ( $partner <= 0 || $author_id !== $partner || $conversation_id <= 0 || $message_id <= 0 ) {
			return 0;
		}
		$other = (int) ZIM_DMs::other_party( $conversation_id, $partner );   // a direct conversation only
		if ( $other <= 0 || $other === $partner || ! ZDZ_Privacy_View::is_on( $other ) ) {
			return 0;
		}
		return (int) ZIM_Privacy_View::conversation_id( $other ) === $conversation_id ? $other : 0;
	}

	/** The names the assistant answers to in the thread: its own (when the business named it) and the account's. */
	public static function names( int $pv ): array {
		$out = array();
		if ( class_exists( 'ZANA_Prompt_Builder' ) ) {
			$voice = ZANA_Prompt_Builder::voice();
			$out[] = (string) ( $voice['name'] ?? '' );
		}
		$out[] = function_exists( 'get_user_meta' ) ? (string) get_user_meta( $pv, 'first_name', true ) : '';
		$u     = function_exists( 'get_userdata' ) ? get_userdata( $pv ) : null;
		if ( $u ) {
			$out[] = (string) preg_split( '/\s+/', trim( (string) $u->display_name ) )[0];
		}
		$out = array_map( static function ( $n ) {
			return mb_strtolower( trim( $n ) );
		}, $out );
		return array_values( array_unique( array_filter( $out, static function ( $n ) {
			return mb_strlen( $n ) >= 2;
		} ) ) );
	}

	/**
	 * Does the message want an answer? Every message in the thread does, except a short acknowledgement ("thanks",
	 * "ok", "got it", a thumbs-up), so a thank-you does not start a reply and its cost. A question mark, or a message
	 * that starts with one of the names, always does.
	 */
	public static function asks( string $text, array $names = array() ): bool {
		$t = trim( $text );
		if ( '' === $t ) {
			return false;
		}
		if ( false !== strpos( $t, '?' ) ) {
			return true;
		}
		foreach ( $names as $n ) {
			if ( '' !== (string) $n && 1 === preg_match( '/^\W{0,3}' . preg_quote( (string) $n, '/' ) . '\b/iu', $t ) ) {
				return true;
			}
		}
		return ! self::acknowledgement( $t );
	}

	/** A short thank-you, okay or thumbs-up, with nothing asked or to do in it. */
	public static function acknowledgement( string $t ): bool {
		$w = strtolower( (string) preg_replace( '/[^\p{L}\p{N}\s\']+/u', ' ', str_replace( array( "\u{2019}", "\u{2018}" ), "'", $t ) ) );
		$w = trim( (string) preg_replace( '/\s+/', ' ', $w ) );
		if ( '' === $w ) {
			return true;   // only emoji or punctuation
		}
		if ( count( explode( ' ', $w ) ) > 8 ) {
			return false;
		}
		if ( preg_match( '/\b(?:draft|send|write|find|check|look|show|tell|give|list|pull|make|add|update|remind|compare|summari[sz]e|explain|can|could|would|will you|please|what|how|who|which|when|where|why|whether|need|want)\b/', $w ) ) {
			return false;   // something asked or to do
		}
		return 1 === preg_match( '/^(?:ok(?:ay)?|k|kk|thanks?|thank you|thx|ty|cheers|got it|gotcha|cool|nice|great|perfect|awesome|sounds good|will do|noted|understood|lol|haha|good|good job|nice work|great work|appreciate it)\b/', $w );
	}

	/* ================================================================== a message arrives */

	public static function on_posted( $message_id, $conversation_id, $author_id ): void {
		try {
			$message_id      = (int) $message_id;
			$conversation_id = (int) $conversation_id;
			$author_id       = (int) $author_id;
			$pv              = self::who( $message_id, $conversation_id, $author_id );
			if ( $pv <= 0 ) {
				return;
			}
			$text = self::message_text( $message_id, $conversation_id, $author_id );
			if ( ! self::asks( $text, self::names( $pv ) ) ) {
				return;   // kept in the thread; read as part of it the next time an answer is wanted
			}
			self::dispatch( $pv, $conversation_id, $author_id, $message_id );
		} catch ( \Throwable $e ) {
			error_log( 'ZANA_PV_DM_Agent: a reply could not be started (' . get_class( $e ) . ').' );   // the message stands; its words are never logged
		}
	}

	/** The words of a live message by that author in that conversation, as Messages reads it; '' otherwise. */
	public static function message_text( int $message_id, int $conversation_id, int $author_id ): string {
		$rows = ZIM_Messages::fetch_since( $conversation_id, $message_id - 1, 1 );
		$r    = is_array( $rows ) && $rows ? $rows[0] : null;
		if ( ! is_array( $r ) || (int) ( $r['id'] ?? 0 ) !== $message_id || ! empty( $r['deleted'] ) || (int) ( $r['author']['id'] ?? 0 ) !== $author_id ) {
			return '';
		}
		return self::plain( (string) ( $r['body_raw'] ?? '' ) );
	}

	/** Plain text: entities decoded, tags gone, lines kept. */
	public static function plain( string $s ): string {
		$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$s = (string) preg_replace( '/<br\s*\/?>|<\/(?:p|div|li)>/i', "\n", $s );
		$s = wp_strip_all_tags( $s, false );
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $s ) );
	}

	private static function cut( string $s, int $n ): string {
		$s = trim( $s );
		return mb_strlen( $s ) > $n ? rtrim( mb_substr( $s, 0, $n ) ) . ' …' : $s;
	}

	/* ================================================================== one turn at a time */

	/** Start a reply, or keep the message to be answered next when a turn for the thread is already under way. */
	public static function dispatch( int $pv, int $conv, int $partner, int $message_id ): string {
		if ( ! self::lock( $conv ) ) {
			set_transient( 'zana_pv_dm_again_' . $conv, $message_id, self::BUSY_TTL );
			self::rekick( $conv );
			return 'busy';
		}
		$r = 'error';
		try {
			$r = self::start( $pv, $conv, $partner, $message_id );
		} finally {
			if ( 'queued' !== $r ) {
				self::unlock( $conv );   // never left held by a start that queued nothing
			}
		}
		return $r;
	}

	private static function lock_name( int $conv ): string {
		return '_zana_pv_dm_lock_' . $conv;
	}

	/**
	 * One turn at a time for a thread. Taken with ONE statement (INSERT IGNORE on the options table's unique name: of
	 * two at once, exactly one adds the row); a lock older than BUSY_TTL is a turn that died and is taken over with one
	 * conditional UPDATE (exactly one wins). Without a database handle it cannot be taken, so no turn starts.
	 */
	public static function lock( int $conv ): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) ) {
			return false;
		}
		$k   = self::lock_name( $conv );
		$now = time();
		$got = 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $k, (string) $now ) );
		if ( ! $got ) {
			$got = 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", (string) $now, $k, $now - self::BUSY_TTL ) );
		}
		self::forget_cached( $k );
		return $got;
	}

	public static function unlock( int $conv ): void {
		self::drop_row( self::lock_name( $conv ) );
	}

	private static function drop_row( string $k ): void {
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->options ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $k ) );
			self::forget_cached( $k );
		}
	}

	private static function forget_cached( string $k ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $k, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}

	private static function start( int $pv, int $conv, int $partner, int $message_id ): string {
		if ( self::left_milli() <= 0 ) {
			self::tell_once( 'cap', $pv, $partner, $conv, __( 'I\'ve used today\'s limit for answering here, so I\'ll pick this up again tomorrow. You can ask in the chat in the meantime.', 'zorderz' ) );
			return 'cap';
		}
		$hour = get_transient( 'zana_pv_dm_hour_' . $conv );
		$hour = array_values( array_filter( is_array( $hour ) ? $hour : array(), static function ( $t ) {
			return (int) $t > time() - 3600;
		} ) );
		if ( count( $hour ) >= self::HOURLY_MAX ) {
			self::tell_once( 'hour', $pv, $partner, $conv, __( 'That\'s a lot of questions in an hour, so I\'m pausing here for a bit. Ask me again shortly, or in the chat.', 'zorderz' ) );
			return 'hour';
		}
		$hour[] = time();
		set_transient( 'zana_pv_dm_hour_' . $conv, $hour, 3600 );
		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			return 'no_token';
		}
		// ids only: the message's words are read again from Messages when the turn runs
		$turn = array( 'token' => $token, 'pv' => $pv, 'partner' => $partner, 'message_id' => $message_id, 'at' => time() );
		if ( ! set_transient( 'zana_pv_dm_turn_' . $conv, $turn, self::TURN_TTL ) ) {
			return 'no_queue';
		}
		self::kick( $conv, $token );
		return 'queued';
	}

	private static function sig( int $conv, string $token ): string {
		return wp_hash( 'zana_pv_dm|' . $conv . '|' . $token );
	}

	/**
	 * Start the turn off this request: a signed loopback that does not wait, WP-Cron behind it in case the loopback is
	 * lost, and a watchdog behind both. Whichever arrives first takes the turn (run() claims it); the others find it
	 * taken. The loopback carries no cookie: the turn runs as the account by its record, never by who asked.
	 */
	public static function kick( int $conv, string $token ): void {
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time(), self::RUN_ACTION, array( $conv, $token ) );
			wp_schedule_single_event( time() + self::WATCH_S, self::WATCH_HOOK, array( $conv, $token, 1 ) );
		}
		self::loopback( $conv, $token );
		if ( ! is_callable( self::$kicker ) && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	private static function loopback( int $conv, string $token ): void {
		if ( is_callable( self::$kicker ) ) {
			call_user_func( self::$kicker, $conv, $token );
			return;
		}
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return;
		}
		wp_remote_post( admin_url( 'admin-ajax.php' ), array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ),
			'body'      => array( 'action' => self::RUN_ACTION, 'conv' => $conv, 'token' => $token, 'sig' => self::sig( $conv, $token ) ),
		) );
	}

	/** A message arrived while a turn is held: if it is still waiting to start, start it again (at most once a minute). */
	private static function rekick( int $conv ): void {
		$turn = get_transient( 'zana_pv_dm_turn_' . $conv );
		if ( is_array( $turn ) && ! empty( $turn['token'] ) && ! get_transient( 'zana_pv_dm_kicked_' . $conv ) ) {
			set_transient( 'zana_pv_dm_kicked_' . $conv, 1, 60 );
			self::loopback( $conv, (string) $turn['token'] );
		}
	}

	/** The loopback's entry. Nobody is signed in on it: the signature (the site's own secret) is what lets it in. */
	public static function ajax_run(): void {
		// A server-to-server start: nobody is signed in, so there is no nonce to check. It is authorised by a signature
		// made with the site's own secret (hash_equals below), bound to one conversation and one single-use token.
		$conv  = isset( $_POST['conv'] ) ? absint( wp_unslash( $_POST['conv'] ) ) : 0;   // phpcs:ignore WordPress.Security.NonceVerification.Missing -- signature-gated loopback, see above
		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( (string) $_POST['token'] ) ) : '';   // phpcs:ignore WordPress.Security.NonceVerification.Missing -- signature-gated loopback, see above
		$sig   = isset( $_POST['sig'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['sig'] ) ) : '';   // phpcs:ignore WordPress.Security.NonceVerification.Missing -- signature-gated loopback, see above
		if ( $conv <= 0 || ! preg_match( '/^[a-f0-9]{32}$/', $token ) || '' === $sig || ! hash_equals( self::sig( $conv, $token ), $sig ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );   // the caller does not wait for the answer
		}
		self::run( $conv, $token );
		wp_die();
	}

	/* ================================================================== the turn */

	/**
	 * Run the queued turn for a thread and post its answer. Exactly one caller takes a turn (an INSERT IGNORE claim);
	 * everything about it is checked again here, because the start was only a request: the thread, the chosen
	 * administrator, the account, the day's line. Returns what happened, for the log and the tests.
	 */
	public static function run( $conv, $token = '' ): string {
		$conv  = (int) $conv;
		$token = (string) $token;
		$turn  = get_transient( 'zana_pv_dm_turn_' . $conv );
		if ( $conv <= 0 || ! is_array( $turn ) || '' === $token || ! hash_equals( (string) ( $turn['token'] ?? '' ), $token ) ) {
			return 'none';
		}
		if ( ! self::claim( $conv, $token ) ) {
			return 'taken';
		}
		$pv      = (int) ( $turn['pv'] ?? 0 );
		$partner = (int) ( $turn['partner'] ?? 0 );
		$mid     = (int) ( $turn['message_id'] ?? 0 );
		$out     = 'error';
		try {
			$out = self::answer( $conv, $token, $pv, $partner, $mid );
		} catch ( \Throwable $e ) {
			error_log( 'ZANA_PV_DM_Agent: a reply could not be finished (' . get_class( $e ) . ').' );
			if ( self::take( $conv, $token ) ) {
				self::post( $pv, $partner, $conv, self::sorry() );
			}
		} finally {
			self::take( $conv, $token );
			self::unlock( $conv );
			self::drop_row( self::claim_name( $conv, $token ) );
		}
		self::next( $conv, $pv, $partner );
		return $out;
	}

	private static function sorry(): string {
		return __( 'I couldn\'t answer that just now. Please ask again, or ask in the chat.', 'zorderz' );
	}

	private static function claim_name( int $conv, string $token ): string {
		return '_zana_pv_dm_run_' . md5( $conv . '|' . $token );
	}

	/** One runner for a turn, ever. */
	private static function claim( int $conv, string $token ): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) ) {
			return false;
		}
		$got = 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::claim_name( $conv, $token ), (string) time() ) );
		if ( $got && 1 === mt_rand( 1, 50 ) ) {
			// a claim left by a runner that died is of no use after a day
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d", '\_zana\_pv\_dm\_run\_%', time() - 86400 ) );
		}
		return $got;
	}

	/** The turn's record, taken before anything is posted for it: whoever takes it posts, nobody else does. */
	private static function take( int $conv, string $token ): bool {
		$turn = get_transient( 'zana_pv_dm_turn_' . $conv );
		if ( ! is_array( $turn ) || ! hash_equals( (string) ( $turn['token'] ?? '' ), $token ) ) {
			return false;
		}
		return (bool) delete_transient( 'zana_pv_dm_turn_' . $conv );
	}

	/** The checked turn: the chat's own turn as the account, then its answer as a message. */
	private static function answer( int $conv, string $token, int $pv, int $partner, int $mid ): string {
		if ( $pv <= 0 || self::thread_of( $mid, $conv, $partner ) !== $pv ) {
			return 'gone';   // the thread is no longer this account's with this administrator: nothing is said
		}
		$question = self::cut( self::message_text( $mid, $conv, $partner ), self::QUESTION_MAX );
		if ( '' === $question ) {
			return 'gone';   // deleted meanwhile
		}
		if ( self::left_milli() <= 0 ) {
			if ( self::take( $conv, $token ) ) {
				self::tell_once( 'cap', $pv, $partner, $conv, __( 'I\'ve used today\'s limit for answering here, so I\'ll pick this up again tomorrow. You can ask in the chat in the meantime.', 'zorderz' ) );
			}
			return 'cap';
		}
		$session = self::session( $pv, $conv, $partner );
		if ( $session <= 0 ) {
			if ( self::take( $conv, $token ) ) {
				self::post( $pv, $partner, $conv, self::sorry() );
			}
			return 'no_session';
		}
		$was           = (int) get_current_user_id();
		self::$running = array( 'pv' => $pv, 'session' => $session, 'conv' => $conv, 'day' => self::today(), 'spent' => self::counted( $pv ) );
		$cost          = 0;
		$r             = array( 'ok' => false );
		try {
			if ( function_exists( 'wp_set_current_user' ) ) {
				wp_set_current_user( $pv );   // as the account, with its permissions, as in its chats
			}
			$history = self::history( $pv, $conv, $partner, $mid );
			$r       = is_callable( self::$turn )
				? (array) call_user_func( self::$turn, $pv, $session, $question, $history )
				: ZANA_Chat::send( $pv, $session, $question, array( 'history' => $history ) );
		} finally {
			$cost          = self::turn_cost();
			self::$running = null;
			if ( function_exists( 'wp_set_current_user' ) ) {
				wp_set_current_user( $was );
			}
			self::add_spend( max( self::floor_milli(), $cost ) );
		}
		$body = ! empty( $r['ok'] ) ? self::for_dm( (string) ( $r['answer'] ?? '' ), $question, $partner ) : self::sorry();
		if ( '' === trim( $body ) ) {
			$body = __( 'I couldn\'t put that answer into a message. Please ask in the chat.', 'zorderz' );
		}
		if ( ! self::take( $conv, $token ) ) {
			return 'late';   // the watchdog already said it could not answer
		}
		return self::post( $pv, $partner, $conv, $body ) ? ( ! empty( $r['ok'] ) ? 'done' : 'failed' ) : 'not_posted';
	}

	/** A message written while the turn ran is answered next (the newest one; the thread holds the others). */
	private static function next( int $conv, int $pv, int $partner ): void {
		$again = (int) get_transient( 'zana_pv_dm_again_' . $conv );
		if ( $again <= 0 ) {
			return;
		}
		delete_transient( 'zana_pv_dm_again_' . $conv );
		try {
			if ( $pv > 0 && self::thread_of( $again, $conv, $partner ) === $pv && self::asks( self::message_text( $again, $conv, $partner ), self::names( $pv ) ) ) {
				self::dispatch( $pv, $conv, $partner, $again );
			}
		} catch ( \Throwable $e ) {
			error_log( 'ZANA_PV_DM_Agent: the next reply could not be started (' . get_class( $e ) . ').' );
		}
	}

	/**
	 * The watchdog: every WATCH_S, WATCH_TRIES times. A turn still waiting to start is started again; at the end, a
	 * turn that never answered is given up: one note that it couldn't, and the thread is free again.
	 */
	public static function watch( $conv, $token = '', $try = 1 ): void {
		try {
			$conv  = (int) $conv;
			$token = (string) $token;
			$turn  = get_transient( 'zana_pv_dm_turn_' . $conv );
			if ( ! is_array( $turn ) || ! hash_equals( (string) ( $turn['token'] ?? '' ), $token ) ) {
				return;   // answered, or given up
			}
			if ( (int) $try < self::WATCH_TRIES ) {
				self::loopback( $conv, $token );
				if ( function_exists( 'wp_schedule_single_event' ) ) {
					wp_schedule_single_event( time() + self::WATCH_S, self::WATCH_HOOK, array( $conv, $token, (int) $try + 1 ) );
				}
				return;
			}
			if ( self::take( $conv, $token ) ) {
				self::post( (int) ( $turn['pv'] ?? 0 ), (int) ( $turn['partner'] ?? 0 ), $conv, self::sorry() );
				self::unlock( $conv );
				self::drop_row( self::claim_name( $conv, $token ) );
			}
		} catch ( \Throwable $e ) {
			error_log( 'ZANA_PV_DM_Agent: the watchdog failed (' . get_class( $e ) . ').' );
		}
	}

	/* ================================================================== the notes chat */

	/** The notes chats (never listed among a person's chats). */
	public static function session_ids(): array {
		$map = function_exists( 'get_option' ) ? get_option( self::SESSIONS_OPT, array() ) : array();
		return is_array( $map ) ? array_values( array_filter( array_map( 'intval', $map ) ) ) : array();
	}

	/** Are these a thread's notes, and is this not the reply that writes them? Then nothing else writes there. */
	public static function closed_to( int $session_id ): bool {
		if ( $session_id <= 0 || ! in_array( $session_id, self::session_ids(), true ) ) {
			return false;
		}
		return null === self::$running || (int) self::$running['session'] !== $session_id;
	}

	/** The account's notes chat for this thread: its own session, made once. */
	public static function session( int $pv, int $conv, int $partner ): int {
		global $wpdb;
		$map = get_option( self::SESSIONS_OPT, array() );
		$map = is_array( $map ) ? $map : array();
		$sid = (int) ( $map[ $conv ] ?? 0 );
		if ( $sid > 0 && ZANA_Chat::owns_session( $pv, $sid ) ) {
			return $sid;
		}
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! class_exists( 'ZANA_DB' ) ) {
			return 0;
		}
		$now = current_time( 'mysql' );
		/* translators: %s: a person's first name */
		$title = sprintf( __( 'Messages with %s', 'zorderz' ), self::first_name( $partner ) );
		$wpdb->insert( ZANA_DB::sessions_table(), array( 'user_id' => $pv, 'title' => mb_substr( $title, 0, 190 ), 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%s', '%s', '%s' ) );
		$sid = (int) $wpdb->insert_id;
		if ( $sid > 0 ) {
			$map[ $conv ] = $sid;
			update_option( self::SESSIONS_OPT, $map, false );
		}
		return $sid;
	}

	private static function first_name( int $uid ): string {
		$name = class_exists( 'ZANA_User_Memory' ) ? ZANA_User_Memory::name_for( $uid ) : '';
		return '' !== $name ? $name : __( 'your administrator', 'zorderz' );
	}

	/** How replies in Messages work: the first thing every turn reads. */
	public static function house( int $partner ): string {
		$name = self::first_name( $partner );
		return "How replies here work (read this, then answer the next message).\n"
			. '- This is Messages: a direct-message thread with ' . $name . ", not the chat. Reply as a short direct message, in plain text: a few sentences or a short list, no tables.\n"
			. "- Earlier messages in this thread are the conversation so far; notes from the chats your messages came from follow. Keep their facts and decisions straight across the thread.\n"
			. '- You cannot carry anything out from here. If they want something done, write it as a draft under the line "' . self::DRAFT_LINE . '" and say they can do it from their own chat.';
	}

	/**
	 * What the turn reads before the message: how replies here work; notes from the chats the account's messages came
	 * from (its own chats only); the thread's newest messages (the administrator: user, the account: assistant).
	 * Messages reads the thread; the message being answered, anyone else's message and a deleted one are left out. An
	 * answer posted after the message was written (to the question before it) is part of the thread too.
	 */
	public static function history( int $pv, int $conv, int $partner, int $asked_id ): array {
		$h    = array( array( 'role' => 'user', 'content' => self::house( $partner ) ), array( 'role' => 'assistant', 'content' => 'Understood.' ) );
		$rows = ZIM_Messages::fetch_before( $conv, 0, self::THREAD_MAX + 1 );
		$rows = array_values( array_filter( is_array( $rows ) ? $rows : array(), static function ( $r ) use ( $asked_id ) {
			return (int) ( $r['id'] ?? 0 ) !== $asked_id;
		} ) );
		$note = self::chat_notes( $pv, $rows );
		if ( '' !== $note ) {
			$h[] = array( 'role' => 'user', 'content' => "Notes from the chats your messages here came from (background, not a question):\n\n" . $note );
			$h[] = array( 'role' => 'assistant', 'content' => 'Noted.' );
		}
		$prefix = ZIM_Privacy_View::prefix();
		foreach ( $rows as $r ) {
			$who  = (int) ( $r['author']['id'] ?? 0 );
			$body = empty( $r['deleted'] ) ? self::plain( (string) ( $r['body_raw'] ?? '' ) ) : '';
			if ( '' === $body || ( $who !== $pv && $who !== $partner ) ) {
				continue;
			}
			if ( $who === $pv && '' !== trim( $prefix ) && 0 === strpos( $body, trim( $prefix ) ) ) {
				$body = ltrim( substr( $body, strlen( trim( $prefix ) ) ) );
			}
			$h[] = array( 'role' => $who === $pv ? 'assistant' : 'user', 'content' => $body );   // the chat's client joins neighbours by one side
		}
		return $h;
	}

	/**
	 * For the account's messages in the thread that came from one of its chats: the very answer each came from and the
	 * question before it (the chat's newest pair when the message does not say which answer). Its own chats only, never
	 * a thread's notes; at most CHATS_MAX.
	 */
	public static function chat_notes( int $pv, array $rows ): string {
		if ( ! class_exists( 'ZIM_DM_Send' ) || ! method_exists( 'ZIM_DM_Send', 'sources_for_viewer' ) ) {
			return '';
		}
		$mine = array();
		foreach ( $rows as $r ) {
			$mine[] = array( 'id' => (int) ( $r['id'] ?? 0 ), 'author_user_id' => (int) ( $r['author']['id'] ?? 0 ), 'deleted_at' => empty( $r['deleted'] ) ? '' : '1' );
		}
		$src = ZIM_DM_Send::sources_for_viewer( $pv, $mine );
		if ( ! $src ) {
			return '';
		}
		krsort( $src, SORT_NUMERIC );   // the newest message first
		$picked = array();
		$notes  = self::session_ids();
		foreach ( $src as $s ) {
			$sid = (int) ( $s['session_id'] ?? 0 );
			$mid = (int) ( $s['message_id'] ?? 0 );
			if ( $sid > 0 && ! in_array( $sid, $notes, true ) && ! isset( $picked[ $sid . ':' . $mid ] ) ) {
				$picked[ $sid . ':' . $mid ] = array( $sid, $mid );
			}
			if ( count( $picked ) >= self::CHATS_MAX ) {
				break;
			}
		}
		$out = array();
		foreach ( $picked as $pair ) {
			list( $sid, $mid ) = $pair;
			if ( ! ZANA_Chat::owns_session( $pv, $sid ) ) {
				continue;   // not one of its own chats
			}
			$a    = '';
			$q    = '';
			$last = '';   // the person's newest message so far
			foreach ( ZANA_Chat::session_messages( $sid, $pv ) as $m ) {
				$id = (int) ( $m['id'] ?? 0 );
				if ( $mid > 0 && $id > $mid ) {
					break;
				}
				if ( 'assistant' !== ( $m['role'] ?? '' ) ) {
					$last = (string) ( $m['body'] ?? '' );
				} elseif ( $mid <= 0 || $id === $mid ) {
					$a = (string) ( $m['body'] ?? '' );
					$q = $last;
				}
			}
			if ( '' === trim( $a ) ) {
				continue;
			}
			$n     = 0;
			$out[] = 'Asked: ' . self::cut( self::plain( $q ), 400 ) . "\nAnswer: " . self::cut( self::words( $a, $n ), 1200 );
		}
		return implode( "\n\n", $out );
	}

	/* ================================================================== the answer as a message */

	/**
	 * An answer's words, as plain text: the page's own markup and small print gone; a drafted message, post or email
	 * shown as "Draft (nothing was done):" with its words ($drafts counts them); any other bracketed token of the apps
	 * removed; a link keeps its words and loses its address, and a bare address is replaced, so no address a model wrote
	 * is ever passed on.
	 */
	public static function words( string $content, int &$drafts ): string {
		$t = (string) preg_replace( '/<!--[\s\S]*?-->/', '', $content );
		$t = (string) preg_replace( '/<button\b[^>]*>[\s\S]*?<\/button>/i', '', $t );
		$t = (string) preg_replace( '/<sub>[\s\S]*?<\/sub>/u', '', $t );
		$draft = static function ( $m ) use ( &$drafts ) {
			$drafts++;
			return "\n\n" . self::DRAFT_LINE . "\n" . trim( (string) $m[2] ) . "\n";
		};
		$kinds = 'ZANA_DM_DRAFT|ZANA_POST_DRAFT|ZDZ_EMAIL_DRAFT';
		// a whole draft first; one left open ends at the next blank line
		$t = (string) preg_replace_callback( '/\[(' . $kinds . ')(?::[^\]\n]{0,200})?\]([\s\S]*?)\[\/\1\]/u', $draft, $t );
		$t = (string) preg_replace_callback( '/\[(' . $kinds . ')(?::[^\]\n]{0,200})?\]([\s\S]*?)(?=\n[ \t]*\n|$)/u', $draft, $t );
		$t = (string) preg_replace( '/\[\/?Z[A-Z]*_[A-Z0-9_]+(?::[^\]\n]{0,200})?\]/u', '', $t );
		$t = self::plain( $t );
		$t = (string) preg_replace( '/!?\[([^\]\n]{1,200})\]\((?:[^()\s]++|\([^()\s]*\))+\)/u', '$1', $t );
		$gone = __( '[link removed]', 'zorderz' );
		$t    = (string) preg_replace_callback( '/\b(?:https?:\/\/|www\.)[^\s<>"\')\]]+/iu', static function ( $m ) use ( $gone ) {
			return $gone . ( preg_match( '/[.,;:!?]+$/', $m[0], $end ) ? $end[0] : '' );   // the sentence keeps its own punctuation
		}, $t );
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $t ) );
	}

	/**
	 * The answer as a message. Nothing in it is ever carried out here. When it holds a draft, or says it could not do
	 * what was asked from this account, a link is added that opens the administrator's own chat and sends their request
	 * there; the action then waits for its own button, pressed by them.
	 */
	public static function for_dm( string $content, string $ask, int $partner = 0 ): string {
		$drafts = 0;
		$t      = self::cut( self::words( $content, $drafts ), self::BODY_MAX );
		$todo   = $drafts > 0 || false !== strpos( $t, self::DRAFT_LINE );
		if ( ! $todo && class_exists( 'ZANA_Actions' ) ) {
			$todo = false !== strpos( $t, ZANA_Actions::refusal_words( 'privacy_view', '' ) );
		}
		if ( $todo && '' !== trim( $ask ) && $partner > 0 ) {
			$id = self::ask_link( $partner, $ask );
			if ( '' !== $id ) {
				$t .= "\n\n" . __( 'To do this, open it in your chat (your request is sent there; then press the button on the card):', 'zorderz' ) . "\n" . home_url( '/?' . self::ASK_PARAM . '=' . $id );
			}
		}
		return $t;
	}

	/**
	 * The link carries an opaque id, never the request's words (the account sees the thread masked, and masking does
	 * not read an encoded address). The words are kept on the server for the administrator alone, for ASK_TTL.
	 */
	public static function ask_link( int $partner, string $ask ): string {
		try {
			$id = bin2hex( random_bytes( 12 ) );
		} catch ( \Throwable $e ) {
			return '';
		}
		return set_transient( 'zana_pv_dm_ask_' . $id, array( 'uid' => $partner, 'text' => self::cut( $ask, self::QUESTION_MAX ) ), self::ASK_TTL ) ? $id : '';
	}

	/** The words behind a link id: only for the person it was made for, and only once; '' otherwise. */
	public static function ask_text( string $id, int $uid ): string {
		if ( ! preg_match( '/^[0-9a-f]{24}$/', $id ) || $uid <= 0 ) {
			return '';
		}
		$r = get_transient( 'zana_pv_dm_ask_' . $id );
		if ( ! is_array( $r ) || (int) ( $r['uid'] ?? 0 ) !== $uid ) {
			return '';
		}
		delete_transient( 'zana_pv_dm_ask_' . $id );   // it sends when opened, so a second opening sends nothing
		return (string) ( $r['text'] ?? '' );
	}

	/** Posted by the account in its thread, through Messages' own seam (plain text, its label, its one partner). */
	private static function post( int $pv, int $partner, int $conv, string $body ): bool {
		if ( $pv <= 0 || $partner <= 0 || '' === trim( $body ) ) {
			return false;
		}
		$r = apply_filters( 'zim_dm_send', null, $pv, $partner, $body, array() );
		return is_array( $r ) && (int) ( $r['conversation_id'] ?? 0 ) === $conv && (int) ( $r['message_id'] ?? 0 ) > 0;
	}

	/** A note said once a day ('cap') or once an hour ('hour'). */
	private static function tell_once( string $kind, int $pv, int $partner, int $conv, string $text ): void {
		$key = 'zana_pv_dm_told_' . $kind . '_' . $conv;
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 'cap' === $kind ? self::seconds_to_tomorrow() : 3600 );
		self::post( $pv, $partner, $conv, $text );
	}

	private static function seconds_to_tomorrow(): int {
		$now = function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time();   // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- the site's own clock: the day ends at the site's midnight
		return max( 60, 86400 - ( $now % 86400 ) );
	}

	/* ================================================================== the request behind a link */

	public static function register_routes(): void {
		register_rest_route( ZDZ_REST_NS, '/analytics/ask', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_ask' ),
			'permission_callback' => array( __CLASS__, 'can_ask' ),
		) );
	}

	/** Signed in, with the chat; never the shared device or an account in Privacy View. */
	public static function can_ask(): bool {
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 || ! class_exists( 'ZANA_REST' ) || ! ZANA_REST::can_access() ) {
			return false;
		}
		if ( class_exists( 'ZANA_LLM_Client' ) && ZANA_LLM_Client::is_shared_device( $uid ) ) {
			return false;
		}
		return ! ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid ) );
	}

	/** { id } → { text }: the request the link stands for, to the person it was made for, once. */
	public static function rest_ask( WP_REST_Request $req ) {
		$text = self::ask_text( sanitize_key( (string) $req->get_param( 'id' ) ), (int) get_current_user_id() );
		if ( '' === $text ) {
			return new WP_Error( 'zana_ask', __( 'That link has expired or is not yours.', 'zorderz' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'text' => $text ) );
	}

	/* ================================================================== the setting */

	public static function register_setting(): void {
		register_setting( 'zdz_core_settings_group', self::DAILY_OPT, array(
			'type'              => 'number',
			'default'           => 0,
			'sanitize_callback' => array( __CLASS__, 'sanitize_usd' ),
		) );
	}

	/** The line, on Zorderz → Settings. */
	public static function render_setting_row(): void {
		$v = self::daily_milli() / 1000;
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( self::DAILY_OPT ); ?>"><?php esc_html_e( 'Privacy View: the assistant answers in Messages (USD a day)', 'zorderz' ); ?></label></th>
			<td>
				<input type="number" min="0" max="<?php echo esc_attr( (string) self::MAX_USD ); ?>" step="0.5" id="<?php echo esc_attr( self::DAILY_OPT ); ?>" name="<?php echo esc_attr( self::DAILY_OPT ); ?>" value="<?php echo esc_attr( (string) $v ); ?>" class="small-text" />
				<p class="description"><?php esc_html_e( '0 = off. With a daily limit set, when the administrator chosen on Zorderz → Privacy View writes in their Messages thread with a Privacy View account, the assistant answers there as that account: it looks things up and drafts, and never changes anything. The site\'s monthly Ai budget (Zorderz → Ai usage) always applies first.', 'zorderz' ); ?></p>
			</td>
		</tr>
		<?php
	}
}
