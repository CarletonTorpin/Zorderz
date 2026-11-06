<?php
/**
 * ZANA_Ajax — the chat's admin-ajax road, and the reply path that masks itself for a Privacy View account.
 *
 * The chat page talks to the REST routes (ZANA_REST). An account an administrator has put in Privacy View may not use
 * them (the kernel's gate lets such an account read only a few REST routes, and post none); it may use the chat through
 * the admin-ajax actions the kernel lists for it (ZDZ_Privacy_View::ALLOWED_AJAX): this class answers
 * zana_send_message, zana_load_session, zana_list_sessions, zana_cancel_pending_action and zana_refresh_nonce, with the
 * same checks as the REST routes (signed in, the chat app granted, the nonce) and the same functions behind them.
 *
 * Every reply goes through reply(). For a Privacy View account it masks the JSON itself
 * (ZDZ_Privacy_View::mask_json_text: customers, e-mails, phones, streets, invoice and estimate numbers and team members
 * as pseudonyms, unknown full names as [name]); if it cannot, the reply is a refusal, never the real one. The kernel's
 * own output filter passes the reply too (a second pass leaves pseudonyms as they are). The tag the reply was masked
 * under is kept for the chat it belongs to (zdz_privacy_view_shown), so a code that chat showed can be read back later
 * (ZANA_Pseudonym_Route::swap). The chat is the one this request checked and answered from, never an id the request
 * merely sent.
 *
 * Anyone else gets the plain JSON.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Ajax {

	/** The chat's own replies to a Privacy View account (their tags are kept for the chat). */
	const REPLY_ACTIONS = array( 'zana_send_message', 'zana_load_session' );

	/** The chat this request checked and answered from (0: none). */
	private static $session = 0;

	/** Tags waiting to be kept: tag => [ user id, chat id ]. */
	private static $tags   = array();
	private static $hooked = false;

	public static function init(): void {
		add_action( 'wp_ajax_zana_send_message', array( __CLASS__, 'ajax_send_message' ) );
		add_action( 'wp_ajax_zana_load_session', array( __CLASS__, 'ajax_load_session' ) );
		add_action( 'wp_ajax_zana_list_sessions', array( __CLASS__, 'ajax_list_sessions' ) );
		add_action( 'wp_ajax_zana_cancel_pending_action', array( __CLASS__, 'ajax_cancel_pending_action' ) );
		add_action( 'wp_ajax_zana_refresh_nonce', array( __CLASS__, 'ajax_refresh_nonce' ) );
		add_action( 'zdz_privacy_view_shown', array( __CLASS__, 'note_shown' ) );
	}

	/** The gate every action passes: the nonce, a person, the chat app. Returns the person's id. */
	private static function gate(): int {
		check_ajax_referer( ZANA_NONCE, 'nonce' );
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 || ! ZANA_REST::can_access() ) {
			self::reply( array( 'success' => false, 'data' => array( 'message' => __( 'That is not available on your account.', 'zorderz' ) ) ), 0, 403 );
		}
		return $uid;
	}

	private static function post_int( string $k ): int {
		$v = isset( $_POST[ $k ] ) && is_scalar( $_POST[ $k ] ) ? (string) wp_unslash( $_POST[ $k ] ) : '';   // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- gate() checked the nonce; the value is kept only when it matches the digits-only pattern below
		return preg_match( '/^[1-9][0-9]{0,18}$/', $v ) ? (int) $v : 0;
	}

	public static function ajax_send_message(): void {
		$uid     = self::gate();
		$message = isset( $_POST['message'] ) && is_scalar( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';   // phpcs:ignore WordPress.Security.NonceVerification
		$r       = ZANA_Chat::send( $uid, self::post_int( 'session_id' ), (string) $message );
		$sid     = (int) ( $r['session_id'] ?? 0 );
		self::reply( array( 'success' => ! empty( $r['ok'] ), 'data' => $r ), ZANA_Chat::owns_session( $uid, $sid ) ? $sid : 0 );
	}

	public static function ajax_load_session(): void {
		$uid = self::gate();
		$sid = self::post_int( 'session_id' );
		if ( ! ZANA_Chat::owns_session( $uid, $sid ) ) {
			self::reply( array( 'success' => false, 'data' => array( 'message' => __( 'No such conversation.', 'zorderz' ) ) ) );
		}
		self::reply( array( 'success' => true, 'data' => array( 'session_id' => $sid, 'messages' => ZANA_Chat::session_messages( $sid, $uid ) ) ), $sid );
	}

	public static function ajax_list_sessions(): void {
		$uid = self::gate();
		self::reply( array( 'success' => true, 'data' => array( 'sessions' => ZANA_Chat::sessions( $uid ) ) ) );
	}

	public static function ajax_cancel_pending_action(): void {
		$uid = self::gate();
		$id  = isset( $_POST['id'] ) && is_scalar( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';   // phpcs:ignore WordPress.Security.NonceVerification -- gate() checked it
		$ok  = ZANA_Actions::cancel( $uid, self::post_int( 'session_id' ), $id );
		self::reply( array( 'success' => $ok, 'data' => array( 'message' => $ok ? __( 'Cancelled. Nothing was changed.', 'zorderz' ) : __( 'That preview had already gone.', 'zorderz' ) ) ) );
	}

	public static function ajax_refresh_nonce(): void {
		if ( ! is_user_logged_in() ) {
			self::reply( array( 'success' => false ), 0, 403 );
		}
		self::reply( array( 'success' => true, 'data' => array( 'nonce' => wp_create_nonce( ZANA_NONCE ), 'rest' => wp_create_nonce( 'wp_rest' ) ) ) );
	}

	/* ── the reply ─────────────────────────────────────────────────────────────────────────────────── */

	/**
	 * What a Privacy View account may be sent: the JSON masked, or a refusal. Anyone else: the JSON as it is. $sid is
	 * the chat this reply belongs to, when the handler checked it.
	 */
	public static function masked_json( string $json, int $uid, int $sid = 0 ): string {
		if ( ! ZANA_Pseudonym_Route::is_on( $uid ) ) {
			return $json;
		}
		self::$session = $sid;
		try {
			$m = ZDZ_Privacy_View::mask_json_text( $json );
		} catch ( \Throwable $e ) {
			$m = null;
		} finally {
			self::$session = 0;
		}
		return is_string( $m ) ? $m : (string) wp_json_encode( array( 'success' => false, 'data' => array( 'message' => __( 'Privacy View: this could not be shown with customer details hidden, so it was not sent.', 'zorderz' ) ) ) );
	}

	private static function reply( array $data, int $sid = 0, int $status = 200 ): void {
		$uid  = (int) get_current_user_id();
		$json = self::masked_json( (string) wp_json_encode( $data ), $uid, $sid );
		if ( ! headers_sent() ) {
			status_header( $status );
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		}
		echo $json;   // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON, masked above for a Privacy View account
		wp_die( '', '', array( 'response' => null ) );
	}

	/** zdz_privacy_view_shown: a reply was masked under $tag. Kept for the chat this request answered from. */
	public static function note_shown( $tag ): void {
		$uid = (int) get_current_user_id();
		$act = isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';   // phpcs:ignore WordPress.Security.NonceVerification -- matched against REPLY_ACTIONS only; it acts only inside reply() (self::$session), after gate() checked the nonce
		$sid = self::$session;
		if ( $uid <= 0 || $sid <= 0 || ! in_array( $act, self::REPLY_ACTIONS, true ) || ! ZANA_Pseudonym_Route::is_on( $uid ) ) {
			return;
		}
		self::$tags[ (string) $tag ] = array( $uid, $sid );
		if ( ! self::$hooked && function_exists( 'add_action' ) ) {
			self::$hooked = true;   // written after the reply is out, never inside an output handler
			add_action( 'shutdown', array( __CLASS__, 'save_tags' ), 100 );
		}
	}

	public static function save_tags(): void {
		foreach ( self::$tags as $tag => $who ) {
			try {
				ZANA_Pseudonym_Route::remember_tag( (int) $who[0], (int) $who[1], (string) $tag );
			} catch ( \Throwable $e ) {
				unset( $e );   // a code just stays a code
			}
		}
		self::$tags = array();
	}
}
