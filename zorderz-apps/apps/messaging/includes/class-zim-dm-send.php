<?php
/**
 * ZIM_DM_Send - Messages' own seam for "send this direct message" from another app (the chat's Confirm card).
 *
 *   apply_filters( 'zim_dm_send', null, int $sender_id, int $recipient_id, string $body, array $context )
 *     -> array( 'conversation_id' => int, 'message_id' => int ) | WP_Error
 *
 * Messages owns the write: who may send (never a read-only account such as the shared device), the conversation, the
 * post itself (with its own sanitising, mentions, pushes and the action-only audit line), and the answer the caller
 * needs to show the thread docked beside its answer (conversation_id). The press stays in the calling app (writes are
 * never made through registry verbs); this is a PHP filter, never a REST route. Another app never writes into
 * ZIM_DMs / ZIM_Messages directly.
 *
 * $context (optional, all checked here):
 *   'source' => array( 'app' => 'chat', 'session_id' => int, 'message_id' => int )
 *       The chat turn the message came from. Kept on the SENDER's own record only (user meta zim_chat_sources, the
 *       newest 200), and shown back to the sender alone as "From your chat" on that message: the chat session is the
 *       sender's, so nobody else could open it. Nothing about it is sent to the recipient.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIM_DM_Send {

	const SOURCES_META = 'zim_chat_sources';
	const SOURCES_KEEP = 200;

	public static function init() {
		add_filter( 'zim_dm_send', array( __CLASS__, 'send' ), 10, 5 );
	}

	/**
	 * The filter. A non-null $result from an earlier callback is passed through untouched (it answered first).
	 *
	 * @return array|WP_Error
	 */
	public static function send( $result, $sender_id = 0, $recipient_id = 0, $body = '', $context = array() ) {
		if ( null !== $result ) {
			return $result;
		}
		$sender_id    = (int) $sender_id;
		$recipient_id = (int) $recipient_id;
		if ( $sender_id <= 0 || $recipient_id <= 0 ) {
			return new WP_Error( 'zim_dm_bad_args', 'A sender and a recipient are needed.' );
		}
		// The shared device and any read-only role never send. ZIM_Messages::post() refuses too; this answers first,
		// in words the caller can show.
		if ( function_exists( 'zim_user_can_write' ) && ! zim_user_can_write( $sender_id ) ) {
			return new WP_Error( 'zim_read_only', 'This account has read-only messaging access and cannot send messages.' );
		}
		// Whose access is missing is said plainly: the sender's own, or the teammate's.
		if ( function_exists( 'zim_user_has_access' ) && ! zim_user_has_access( $sender_id ) ) {
			return new WP_Error( 'zim_no_access', 'Your account does not have Messages.' );
		}
		if ( function_exists( 'zim_user_has_access' ) && ! zim_user_has_access( $recipient_id ) ) {
			return new WP_Error( 'zim_no_access', 'That teammate does not have Messages.' );
		}
		if ( '' === trim( (string) $body ) ) {
			return new WP_Error( 'zim_empty_message', 'Message is empty.' );
		}
		// An account in Privacy View sends only to the one administrator chosen for it. Its body is made plain text,
		// with its prefix, by ZIM_Messages::post() itself.
		if ( class_exists( 'ZIM_Privacy_View' ) && ZIM_Privacy_View::on( $sender_id ) ) {
			if ( ZIM_Privacy_View::partner() <= 0 || $recipient_id !== ZIM_Privacy_View::partner() ) {
				return new WP_Error( 'zim_privacy_view', 'This account may message only the administrator chosen for it (Zorderz -> Privacy View).' );
			}
		}
		$conversation_id = (int) ZIM_DMs::get_or_create_conversation( $sender_id, $recipient_id );
		if ( $conversation_id <= 0 ) {
			return new WP_Error( 'zim_dm_no_conversation', 'Could not open a conversation with that teammate.' );
		}
		$posted = ZIM_Messages::post( $conversation_id, $sender_id, (string) $body );
		if ( is_wp_error( $posted ) ) {
			return $posted;
		}
		$message_id = is_array( $posted ) ? (int) ( $posted['message_id'] ?? 0 ) : (int) $posted;
		if ( $message_id <= 0 ) {
			return new WP_Error( 'zim_insert_failed', 'Failed to store message.' );
		}
		$source = self::clean_source( is_array( $context ) ? ( $context['source'] ?? null ) : null );
		if ( $source ) {
			self::remember_source( $sender_id, $message_id, $source );
		}
		return array(
			'conversation_id' => $conversation_id,
			'message_id'      => $message_id,
		);
	}

	/** Only a chat turn, by number. Anything else is dropped. */
	public static function clean_source( $s ) {
		if ( ! is_array( $s ) || 'chat' !== ( $s['app'] ?? '' ) ) {
			return null;
		}
		$sid = (int) ( $s['session_id'] ?? 0 );
		$mid = (int) ( $s['message_id'] ?? 0 );
		return $sid > 0 ? array( 'app' => 'chat', 'session_id' => $sid, 'message_id' => max( 0, $mid ) ) : null;
	}

	/** The sender's own map: message id => the chat turn it came from (the newest SOURCES_KEEP). */
	public static function remember_source( $user_id, $message_id, array $source ) {
		$map = get_user_meta( (int) $user_id, self::SOURCES_META, true );
		$map = is_array( $map ) ? $map : array();
		$map[ (int) $message_id ] = array( 's' => (int) $source['session_id'], 'm' => (int) $source['message_id'] );
		if ( count( $map ) > self::SOURCES_KEEP ) {
			krsort( $map, SORT_NUMERIC );
			$map = array_slice( $map, 0, self::SOURCES_KEEP, true );
		}
		update_user_meta( (int) $user_id, self::SOURCES_META, $map );
	}

	/**
	 * For ZIM_Messages::hydrate(): the chat turn each of THESE messages came from, for the viewer's own messages only.
	 * array( message_id => array( session_id, message_id ) ).
	 */
	public static function sources_for_viewer( $viewer_id, array $rows ) {
		$viewer_id = (int) $viewer_id;
		if ( $viewer_id <= 0 ) {
			return array();
		}
		$mine = array();
		foreach ( $rows as $r ) {
			if ( (int) ( $r['author_user_id'] ?? 0 ) === $viewer_id && empty( $r['deleted_at'] ) ) {
				$mine[] = (int) $r['id'];
			}
		}
		if ( ! $mine ) {
			return array();
		}
		$map = get_user_meta( $viewer_id, self::SOURCES_META, true );
		if ( ! is_array( $map ) || ! $map ) {
			return array();
		}
		$out = array();
		foreach ( $mine as $id ) {
			if ( isset( $map[ $id ]['s'] ) && (int) $map[ $id ]['s'] > 0 ) {
				$out[ $id ] = array( 'session_id' => (int) $map[ $id ]['s'], 'message_id' => (int) ( $map[ $id ]['m'] ?? 0 ) );
			}
		}
		return $out;
	}
}
