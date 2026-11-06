<?php
/**
 * ZIM_Unread_Counts - "how many messages have I not read?" Published as the read verb `messaging.unread_counts` on the
 * platform's capability registry (ZDZ_Capabilities), for a home-screen tile or any app that shows the number. Messages
 * owns who has read what, so Messages answers; nobody else reads its tables.
 *
 * WHAT COUNTS
 * -----------
 * The same number Messages' own badge shows (GET zim/v1/unread-total, ZIM_REST::get_unread_total): for every
 * channel and direct conversation this person is a member of, the messages newer than their read cursor that are not
 * deleted (ZIM_Channels::list_for_user / ZIM_DMs::list_for_user). So a tile and the badge never disagree.
 *   unread         all of them
 *   direct         in direct conversations
 *   channels       in channels (announcements included)
 *   conversations  how many conversations hold at least one
 *
 * WHO ASKS, WHAT THEY GET
 * -----------------------
 *   anyone who holds Messages (tier viewer and up), their OWN unread only (the registry stamps who asks)
 *   the shared device, the signed-out, someone without Messages, a screen in customer-facing mode
 *   (ZIM_Widget::should_render): refused, as Messages' badge is
 *   anyone: counts only - never a message, a sender, a channel name or a conversation id
 *
 * A failed read is an error (success false), never 0. `read_at` is when the conversations were read.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIM_Unread_Counts {

	const VERB = 'messaging.unread_counts';

	/** Injected in tests: "now" (unix). */
	public static $now = null;

	public static function register_capabilities( $caps ) {
		$caps = is_array( $caps ) ? $caps : array();
		$caps[ self::VERB ] = array(
			'callable'   => array( __CLASS__, 'from_capability' ),
			'descriptor' => array(
				'verb'          => self::VERB,
				'source'        => 'zdz-internal-messaging',
				'app'           => 'internal-messaging',
				'min_tier'      => 'viewer',
				'kiosk'         => false,
				'kiosk_bounded' => false,
				'side_effect'   => false,
				'confirm'       => false,
				'needs_app'     => true,
				'summary'       => 'How many messages you have not read, as Messages\' own badge counts them: in direct conversations, in channels, and how many conversations. Counts only.',
			),
		);
		return $caps;
	}

	/** Sum list_for_user() rows. PURE. */
	public static function tally( array $channels, array $dms ): array {
		$out = array( 'unread' => 0, 'direct' => 0, 'channels' => 0, 'conversations' => 0 );
		foreach ( array( 'channels' => $channels, 'direct' => $dms ) as $k => $rows ) {
			foreach ( $rows as $r ) {
				$u = is_array( $r ) ? max( 0, (int) ( $r['unread'] ?? 0 ) ) : 0;
				$out[ $k ]     += $u;
				$out['unread'] += $u;
				if ( $u > 0 ) {
					$out['conversations']++;
				}
			}
		}
		return $out;
	}

	public static function definitions(): array {
		return array(
			'unread'        => __( 'Messages you have not read yet, in every channel and direct conversation you are in: the number on Messages\' own badge.', 'zorderz' ),
			'direct'        => __( 'Of those, in direct conversations.', 'zorderz' ),
			'channels'      => __( 'Of those, in channels.', 'zorderz' ),
			'conversations' => __( 'How many conversations hold an unread message.', 'zorderz' ),
		);
	}

	public static function from_capability( $args ) {
		$args = is_array( $args ) ? $args : array();
		$uid  = (int) ( $args['user_id'] ?? 0 );
		if ( $uid <= 0 ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'signed_out', 'verb' => self::VERB );
		}
		if ( ! empty( $args['is_kiosk'] ) || 'kiosk' === (string) ( $args['tier'] ?? '' )
			|| ( function_exists( 'zim_user_is_read_only' ) && zim_user_is_read_only( $uid ) )
			|| ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'kiosk', 'verb' => self::VERB );
		}
		if ( ! function_exists( 'zim_user_has_access' ) || ! zim_user_has_access( $uid ) ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'app', 'verb' => self::VERB );
		}
		if ( class_exists( 'ZIM_Widget' ) && ! ZIM_Widget::should_render() ) {
			// A screen in customer-facing mode shows no Messages badge; a tile shows no number either.
			return array( 'success' => false, 'denied' => true, 'reason' => 'customer_facing', 'message' => __( 'Not shown while the screen faces a customer.', 'zorderz' ), 'verb' => self::VERB );
		}
		if ( ! class_exists( 'ZIM_Channels' ) || ! class_exists( 'ZIM_DMs' ) ) {
			return array( 'success' => false, 'error' => __( 'Messages could not be read just now.', 'zorderz' ), 'verb' => self::VERB );
		}
		global $wpdb;
		$channels = null;
		$dms      = null;
		try {
			$errors   = 0;
			$channels = ZIM_Channels::list_for_user( $uid );
			$errors  += ( isset( $wpdb ) && is_object( $wpdb ) && '' !== (string) $wpdb->last_error ) ? 1 : 0;
			$dms      = ZIM_DMs::list_for_user( $uid );
			$errors  += ( isset( $wpdb ) && is_object( $wpdb ) && '' !== (string) $wpdb->last_error ) ? 1 : 0;
		} catch ( \Throwable $e ) {
			error_log( 'Messages ' . self::VERB . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			$errors = 1;
		}
		if ( $errors || ! is_array( $channels ) || ! is_array( $dms ) ) {
			return array( 'success' => false, 'error' => __( 'Messages could not be read just now.', 'zorderz' ), 'verb' => self::VERB );
		}
		// An account in Privacy View: its one direct conversation, as its list shows.
		if ( class_exists( 'ZIM_Privacy_View' ) && ZIM_Privacy_View::on( $uid ) ) {
			$only     = ZIM_Privacy_View::sidebar( array( 'channels' => $channels, 'dms' => $dms ) );
			$channels = $only['channels'];
			$dms      = $only['dms'];
		}
		return array(
			'success'     => true,
			'verb'        => self::VERB,
			'counts'      => self::tally( $channels, $dms ),
			'definitions' => self::definitions(),
			'read_at'     => null !== self::$now ? (int) self::$now : time(),
		);
	}
}
