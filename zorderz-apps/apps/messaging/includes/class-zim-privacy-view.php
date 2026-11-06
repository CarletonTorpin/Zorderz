<?php
/**
 * ZIM_Privacy_View - Messages for an account in Privacy View (the theme's ZDZ_Privacy_View).
 *
 * An administrator may put an account in Privacy View (an assistant's account, a demonstration, an outside
 * contractor) and choose the ONE administrator it may message (Zorderz -> Privacy View, "Who it may message":
 * ZDZ_Privacy_View::dm_partner(), checked again every time it is used). The theme opens the direct-message actions to
 * such an account only when this class exists, so Messages enforces its own part here, whatever the theme's gate lets
 * through:
 *
 *   one thread   the only conversation it may load, poll or post in is its direct conversation with that
 *                administrator; the list holds that thread alone (no channels, no other direct messages)
 *   few actions  the list, older messages, new messages, mark-read and posting in that thread, plus the chat's own
 *                "send this direct message" confirmation; nothing else (search, people, previews, uploads, edits,
 *                deletes, channels, push)
 *   text only    a post carries no attachment or shared document; attachments in the thread are not sent to it
 *   one partner  zim_dm_send from such an account reaches only that administrator
 *   no notices   it is never pushed or e-mailed (ZIM_Notifications::queue, ZIM_Push::refused)
 *
 * With nobody chosen (dm_partner() is 0), every action is refused. Customer details in the messages are masked by the
 * theme on the way out, like everything else the account is sent.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIM_Privacy_View {

	/**
	 * The only actions the account may run: Messages' own five (each narrowed to the one thread by the handler) and
	 * the chat's confirmation of a direct message (which sends through zim_dm_send, narrowed to the one partner).
	 */
	const ACTIONS = array( 'zim_sidebar', 'zim_fetch_before', 'zim_poll', 'zim_mark_read', 'zim_post', 'zana_confirm_zim_dm' );

	/** Is this account (the signed-in one by default) in Privacy View? */
	public static function on( $user_id = null ) {
		return class_exists( 'ZDZ_Privacy_View' ) && method_exists( 'ZDZ_Privacy_View', 'is_on' ) && ZDZ_Privacy_View::is_on( $user_id );
	}

	/** The one administrator it may message, or 0. */
	public static function partner() {
		return class_exists( 'ZDZ_Privacy_View' ) && method_exists( 'ZDZ_Privacy_View', 'dm_partner' ) ? (int) ZDZ_Privacy_View::dm_partner() : 0;
	}

	/** What every message it sends starts with, so the administrator reading it knows where it came from. */
	public static function prefix() {
		$core = __( 'From a Privacy View account:', 'zorderz' );
		$p    = function_exists( 'apply_filters' ) ? apply_filters( 'zim_privacy_view_prefix', $core ) : $core;
		$p    = is_string( $p ) ? trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $p ) ) ) : '';
		return ( '' !== $p ? $p : $core ) . ' ';   // the label may change, never disappear
	}

	/** Its direct conversation with that administrator, if one exists (read only: never made here). */
	public static function conversation_id( $user_id = null ) {
		$user_id = null === $user_id ? (int) get_current_user_id() : (int) $user_id;
		$p       = self::partner();
		if ( $user_id <= 0 || $p <= 0 || $user_id === $p ) {
			return 0;
		}
		global $wpdb;
		$t = $wpdb->prefix . 'zim_conversations';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE kind = 'dm' AND user_a = %d AND user_b = %d", min( $user_id, $p ), max( $user_id, $p ) ) );
	}

	/** May the account run this action? Only a listed one, and only once an administrator has been chosen. */
	public static function action_ok( $action ) {
		return self::partner() > 0 && in_array( (string) $action, self::ACTIONS, true );
	}

	/** May the account open this conversation? Only its direct conversation with the partner. */
	public static function conversation_ok( $conversation_id, $user_id = null ) {
		$c = self::conversation_id( $user_id );
		return $c > 0 && (int) $conversation_id === $c;
	}

	/** The list: no channels; of the direct conversations, only the one with the partner. */
	public static function sidebar( array $data ) {
		$c                = self::conversation_id();
		$data['channels'] = array();
		if ( isset( $data['dms'] ) && is_array( $data['dms'] ) ) {
			$data['dms'] = array_values( array_filter( $data['dms'], function ( $d ) use ( $c ) {
				return $c > 0 && is_array( $d ) && (int) ( $d['id'] ?? 0 ) === $c;
			} ) );
		} else {
			$data['dms'] = array();
		}
		return $data;
	}

	/**
	 * The body it sends: plain text, entities decoded FIRST and tags stripped after (the other order would turn
	 * "&lt;img onerror&gt;" into a live tag), with lines kept (a list item, a table row or a heading is a line; a table
	 * cell " | "), no picture and no hidden link address (a markdown link keeps its text; a picture, inline or by
	 * reference, goes), and its prefix once at the start. ZIM_Messages::post() calls it, before its own wp_kses_post().
	 */
	public static function body( $body ) {
		$pre = self::prefix();
		$b   = html_entity_decode( (string) $body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$b   = (string) preg_replace( '/<br\s*\/?>|<\/(?:p|div|li|tr|h[1-6]|blockquote|pre)>/i', "\n", $b );
		$b   = (string) preg_replace( '/<\/t[dh]>/i', ' | ', $b );
		$b   = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $b, false ) : strip_tags( $b );
		$b   = (string) preg_replace( '/!\[[^\]]*\]\((?:[^)]*)\)|!\[[^\]]*\]\[[^\]]*\]/', '', $b );                // pictures
		$b   = (string) preg_replace( '/^[ \t]*\[[^\]]+\]:[ \t]*\S+.*$/m', '', $b );                                   // reference definitions
		$b   = (string) preg_replace( '/\[([^\]]*)\]\((?:https?:|\/\/|www\.|mailto:|\/)[^)]*\)/i', '$1', $b );      // a link: its text
		$b   = (string) preg_replace( '/[ \t]+$/m', '', $b );
		$b   = trim( (string) preg_replace( "/\n{3,}/", "\n\n", $b ) );
		return 0 === strpos( $b, $pre ) ? $b : $pre . $b;
	}
}
