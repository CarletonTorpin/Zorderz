<?php
/**
 * ZANA_DM_Card — a direct message the chat drafted, sent only when the person presses Send, once.
 *
 * The model may draft a message to a teammate as `[ZANA_DM_DRAFT:@login]the message[/ZANA_DM_DRAFT]`. Nothing is sent
 * from that. The server reads each draft (hold()):
 *   - the recipient must be one signed-up person (by login, e-mail or name slug), not the asker, and the draft's text
 *     must not be empty or over 4,000 characters; anything else becomes a plain note and no card;
 *   - a good draft is HELD for this person and this chat for 10 minutes (a transient, never the model's words alone),
 *     and the answer carries the card: `[ZANA_DM_DRAFT:<32 hex id>|@login|Display Name]text[/ZANA_DM_DRAFT]`.
 * The press is the admin-ajax action `zana_confirm_zim_dm` (id, session_id, nonce `zana_nonce`): the held draft is
 * claimed once (one winner per id, ever: INSERT IGNORE on the options table), checked to be this person's and this
 * chat's, and handed to the messaging app's own seam:
 *   apply_filters( 'zim_dm_send', null, sender, recipient, text, [ 'source' => [ app: chat, session_id, message_id ] ] )
 * Messages owns the write (who may send — never the shared device or a read-only role —, the conversation, the post,
 * and, in Privacy View, only to the one administrator chosen for the account). The answer is { success,
 * conversation_id, message }; the card in the saved answer then reads what happened, so a reloaded chat never shows an
 * armed card. Without the messaging app the press says so and nothing is sent.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_DM_Card {

	const OPEN    = '[ZANA_DM_DRAFT:';
	const CLOSE   = '[/ZANA_DM_DRAFT]';
	const TTL     = 600;
	const MAX_LEN = 4000;
	const PREFIX  = 'zana_dm_';
	const CLAIM   = '_zana_dm_claim_';
	const ACTION  = 'zana_confirm_zim_dm';

	/** Tests hand in: the recipient lookup (callable $who => ?array{uid,name,login}), the claim (callable $key => bool). */
	public static $recipient = null;
	public static $claim     = null;

	public static function boot(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'ajax_confirm' ) );
	}

	/** Is the messaging app's seam answering? */
	public static function available(): bool {
		return function_exists( 'has_filter' ) && (bool) has_filter( 'zim_dm_send' );
	}

	/** The instructions block ('' when messages cannot be sent from here). */
	public static function prompt_block( int $uid ): string {
		if ( $uid <= 0 || ! self::available() ) {
			return '';
		}
		return 'DIRECT MESSAGES TO A TEAMMATE: when the person asks you to message someone on the team, write the message as' . "\n"
			. self::OPEN . "@<their login>]the message[/ZANA_DM_DRAFT]\n"
			. 'Nothing is sent until the person presses Send on it. One message per teammate. Never claim a message was sent.';
	}

	/** One recipient: '@login' → { uid, name, login } of one signed-up person, or null. */
	public static function recipient( string $who ): ?array {
		if ( is_callable( self::$recipient ) ) {
			$r = call_user_func( self::$recipient, $who );
			return is_array( $r ) ? $r : null;
		}
		$login = ltrim( trim( $who ), '@' );
		if ( '' === $login || ! function_exists( 'get_user_by' ) ) {
			return null;
		}
		$u = get_user_by( 'login', $login );
		if ( ! $u && is_email( $login ) ) {
			$u = get_user_by( 'email', $login );
		}
		if ( ! $u ) {
			$u = get_user_by( 'slug', sanitize_title( $login ) );
		}
		if ( ! $u ) {
			return null;
		}
		$name = trim( $u->first_name . ' ' . $u->last_name );
		return array( 'uid' => (int) $u->ID, 'name' => '' !== $name ? $name : (string) $u->display_name, 'login' => (string) $u->user_login );
	}

	/**
	 * Every draft in an answer becomes a held card (or a plain note). The model's own text is never sent as written
	 * from here: the card carries an id the server holds.
	 */
	public static function hold( string $answer, int $uid, int $session_id ): string {
		if ( false === strpos( $answer, self::OPEN ) ) {
			return $answer;
		}
		$re  = '/\[ZANA_DM_DRAFT:\s*(@?[A-Za-z0-9_.@\-]{1,100})\s*\]([\s\S]*?)\[\/ZANA_DM_DRAFT\]/';
		$out = preg_replace_callback( $re, function ( $m ) use ( $uid, $session_id ) {
			$body = trim( (string) $m[2] );
			$to   = self::recipient( (string) $m[1] );
			if ( $uid <= 0 || $session_id <= 0 || ! self::available() ) {
				return __( '(A message was drafted, but messages cannot be sent from the chat here.)', 'zorderz' ) . "\n\n" . $body;
			}
			if ( ! $to || (int) $to['uid'] === $uid ) {
				return __( '(I could not tell who that message is for: no one on the team has that login.)', 'zorderz' ) . "\n\n" . $body;
			}
			if ( '' === $body || mb_strlen( $body ) > self::MAX_LEN ) {
				return __( '(That message was empty or too long to send from the chat.)', 'zorderz' );
			}
			$id = bin2hex( random_bytes( 16 ) );
			set_transient( self::key( $uid, $session_id, $id ), array( 'to' => (int) $to['uid'], 'body' => $body, 'at' => time() ), self::TTL );
			$name = str_replace( array( '|', ']', '[' ), ' ', (string) $to['name'] );
			$log  = str_replace( array( '|', ']', '[' ), '', (string) $to['login'] );
			return self::OPEN . $id . '|@' . $log . '|' . trim( $name ) . ']' . $body . self::CLOSE;
		}, $answer );
		return is_string( $out ) ? $out : $answer;
	}

	private static function key( int $uid, int $sid, string $id ): string {
		return self::PREFIX . md5( $uid . ':' . $sid . ':' . strtolower( $id ) );
	}

	/** One winner per id, ever. */
	private static function claim( string $key ): bool {
		if ( is_callable( self::$claim ) ) {
			return (bool) call_user_func( self::$claim, $key );
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) ) {
			return false;
		}
		$n = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::CLAIM . md5( $key ), (string) time() ) );
		if ( 1 === mt_rand( 1, 50 ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %s", '\_zana\_dm\_claim\_%', (string) ( time() - self::TTL * 6 ) ) );
		}
		return 1 === (int) $n;
	}

	/**
	 * The press. { success, message, conversation_id? }. Only the person and chat the draft was held for, only once,
	 * only through Messages' own seam.
	 */
	public static function confirm( int $uid, int $session_id, string $id ): array {
		$fail = function ( $m, $code = 'refused' ) { return array( 'success' => false, 'message' => $m, 'code' => $code ); };
		$id   = strtolower( $id );
		if ( $uid <= 0 || $session_id <= 0 || ! preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			return $fail( __( 'That message is not waiting to be sent.', 'zorderz' ) );
		}
		if ( class_exists( 'ZANA_LLM_Client' ) && ZANA_LLM_Client::is_shared_device( $uid ) ) {
			return $fail( __( 'Messages cannot be sent from the shared device.', 'zorderz' ) );
		}
		$key  = self::key( $uid, $session_id, $id );
		$held = get_transient( $key );
		if ( ! is_array( $held ) || empty( $held['to'] ) || '' === (string) ( $held['body'] ?? '' ) ) {
			return $fail( __( 'That message has expired. Ask again and I\'ll draft it again.', 'zorderz' ) );
		}
		if ( ! self::claim( $key ) ) {
			return $fail( __( 'That message was already sent.', 'zorderz' ), 'already_sent' );
		}
		delete_transient( $key );
		if ( ! self::available() ) {
			return $fail( __( 'Messages is not available, so nothing was sent.', 'zorderz' ) );
		}
		$r = apply_filters( 'zim_dm_send', null, $uid, (int) $held['to'], (string) $held['body'], array(
			'source' => array( 'app' => 'chat', 'session_id' => $session_id, 'message_id' => 0 ),
		) );
		if ( is_wp_error( $r ) ) {
			return $fail( $r->get_error_message() );
		}
		if ( ! is_array( $r ) || empty( $r['conversation_id'] ) ) {
			return $fail( __( 'Messages did not take it, so nothing was sent.', 'zorderz' ) );
		}
		return array( 'success' => true, 'message' => __( 'Sent.', 'zorderz' ), 'conversation_id' => (int) $r['conversation_id'], 'message_id' => (int) ( $r['message_id'] ?? 0 ) );
	}

	/** The card in the saved answer becomes what happened. */
	public static function settle( int $session_id, int $uid, string $id, string $note ): void {
		global $wpdb;
		if ( $session_id <= 0 || $uid <= 0 || ! preg_match( '/^[a-f0-9]{32}$/', $id ) || ! isset( $wpdb ) ) {
			return;
		}
		$t    = ZANA_DB::messages_table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, body FROM {$t} WHERE session_id = %d AND user_id = %d AND role = 'assistant' AND body LIKE %s", $session_id, $uid, '%' . $wpdb->esc_like( self::OPEN . $id ) . '%' ), ARRAY_A );   // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $row ) {
			$body = (string) preg_replace( '/\[ZANA_DM_DRAFT:' . $id . '\|[^\]]*\][\s\S]*?\[\/ZANA_DM_DRAFT\]/', '(' . str_replace( '$', '\$', $note ) . ')', (string) $row['body'] );
			$wpdb->update( $t, array( 'body' => $body ), array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) );
		}
	}

	/** admin-ajax: the Send press. */
	public static function ajax_confirm(): void {
		check_ajax_referer( ZANA_NONCE, 'nonce' );
		$uid = (int) get_current_user_id();
		if ( ! class_exists( 'ZANA_REST' ) || ! ZANA_REST::can_access() ) {
			wp_send_json_error( array( 'message' => __( 'The chat is not available on this account.', 'zorderz' ) ), 403 );
		}
		$sid = absint( wp_unslash( $_POST['session_id'] ?? 0 ) );   // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id  = sanitize_key( wp_unslash( (string) ( $_POST['id'] ?? '' ) ) );   // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$r   = self::confirm( $uid, $sid, $id );
		if ( preg_match( '/^[a-f0-9]{32}$/', $id ) && ( ! empty( $r['success'] ) || 'already_sent' === ( $r['code'] ?? '' ) ) ) {
			self::settle( $sid, $uid, $id, ! empty( $r['success'] ) ? __( 'Message sent.', 'zorderz' ) : $r['message'] );
		}
		if ( empty( $r['success'] ) ) {
			wp_send_json_error( $r );
		}
		wp_send_json_success( $r );
	}
}
