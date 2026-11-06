<?php
/**
 * ZIM_Media_Cards - the direct-message side of "share media with a teammate".
 *
 * Mirrors ZIM_Vault_Cards: a message body carries a [ZDZ-MEDIA-SHARE-<group>] marker and the widget renders it as an
 * in-message gallery of the photos and videos the sender granted this viewer. The bytes come from the theme's
 * token-gated media proxy, viewable only because ZDZ_Media_Share recorded a per-recipient grant: this card never
 * widens access, it only surfaces it.
 *
 * Trust boundaries:
 *   - post_share_to_dm() is the published bridge the Media app calls. It resolves nothing from the request: the
 *     caller passes the signed-in sender. It opens the direct conversation (which itself refuses the shared device and
 *     anyone without Messages), records the grants via ZDZ_Media_Share::create() (which authorizes the sender against
 *     every media id and fails closed), then posts the marker.
 *   - ajax_get_share() authorizes the VIEWER (sender / recipient / admin, via ZDZ_Media_Share::get_share) AND, as
 *     defence in depth, requires the viewer to be a member of the share's conversation: a pasted marker renders
 *     nothing.
 *   - Everything sits behind the same nonce + capability gate the other Messages actions use (ZIM_Dashboard), and an
 *     account in Privacy View is refused outright (it is sent no attachment of any kind).
 *
 * @package Zorderz
 */

defined( 'ABSPATH' ) || exit;

class ZIM_Media_Cards {

	/** The marker a share posts: [ZDZ-MEDIA-SHARE-<group>], the group being 16-64 hex characters. */
	const MARKER_OPEN = '[ZDZ-MEDIA-SHARE-';

	public static function init() {
		add_action( 'wp_ajax_zim_media_share', array( __CLASS__, 'ajax_get_share' ) );
		add_action( 'wp_ajax_zim_media_unshare', array( __CLASS__, 'ajax_revoke' ) );
	}

	/** The marker for a share group. */
	public static function marker( $group ) {
		$group = strtolower( (string) preg_replace( '/[^a-f0-9]/i', '', (string) $group ) );
		return '' !== $group ? self::MARKER_OPEN . $group . ']' : '';
	}

	/**
	 * Bridge: the Media app calls this to share media with a teammate in a direct message. $sender must be the
	 * signed-in actor (resolved by the caller).
	 *
	 * @return array|WP_Error
	 */
	public static function post_share_to_dm( int $sender, int $recipient, array $media_ids, string $note = '' ) {
		if ( ! class_exists( 'ZDZ_Media_Share' ) ) {
			return new WP_Error( 'zim_ms_theme', __( 'Media sharing is unavailable on this site.', 'zorderz' ) );
		}
		if ( $sender <= 0 || $recipient <= 0 ) {
			return new WP_Error( 'zim_ms_bad', __( 'Invalid users.', 'zorderz' ) );
		}
		// An account in Privacy View never sends or receives media.
		if ( class_exists( 'ZIM_Privacy_View' ) && ( ZIM_Privacy_View::on( $sender ) || ZIM_Privacy_View::on( $recipient ) ) ) {
			return new WP_Error( 'zim_ms_privacy_view', __( 'Media cannot be shared with this account.', 'zorderz' ) );
		}

		// Open or find the direct conversation. get_or_create_conversation ALSO enforces that the sender may write
		// (the shared device cannot start one) and that both people have Messages.
		$conv = (int) ZIM_DMs::get_or_create_conversation( $sender, $recipient );
		if ( $conv <= 0 ) {
			return new WP_Error( 'zim_ms_conv', __( 'Could not open a conversation with that teammate.', 'zorderz' ) );
		}

		// Record the grants: this authorizes the sender against every media id and fails closed on any miss.
		$share = ZDZ_Media_Share::create( $sender, $recipient, $media_ids, $note, $conv );
		if ( is_wp_error( $share ) ) {
			return $share;
		}

		$group  = (string) $share['share_group'];
		$posted = ZIM_Messages::post( $conv, $sender, self::marker( $group ) );

		if ( is_wp_error( $posted ) ) {
			// The grants exist (the recipient sees the items under "Shared With Me"); only the card failed to post.
			return array(
				'success'         => true,
				'share_group'     => $group,
				'conversation_id' => $conv,
				'message_id'      => 0,
				'count'           => (int) $share['count'],
				'warning'         => 'shared_without_message',
			);
		}

		return array(
			'success'         => true,
			'share_group'     => $group,
			'conversation_id' => $conv,
			'message_id'      => is_array( $posted ) ? (int) ( $posted['message_id'] ?? 0 ) : (int) $posted,
			'count'           => (int) $share['count'],
		);
	}

	/** admin-ajax: the share payload for the current viewer (authorized). */
	public static function ajax_get_share() {
		self::gate();
		$gone  = array( 'unavailable' => true, 'message' => __( 'This shared media is no longer available.', 'zorderz' ) );
		$group = isset( $_GET['group'] ) ? preg_replace( '/[^a-f0-9]/i', '', (string) wp_unslash( $_GET['group'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reduced to hex digits; nonce verified in self::gate().
		if ( '' === $group || ! class_exists( 'ZDZ_Media_Share' ) ) {
			wp_send_json_success( $gone );
		}

		$viewer  = get_current_user_id();
		$payload = ZDZ_Media_Share::get_share( $group, $viewer );
		if ( is_wp_error( $payload ) ) {
			wp_send_json_success( $gone );
		}

		// Defence in depth: the viewer must be a member of the conversation the share was delivered to (admins
		// exempt). A marker pasted into an unrelated place therefore renders nothing.
		if ( ! self::viewer_in_conversation( $viewer, (int) ( $payload['conversation_id'] ?? 0 ) ) ) {
			wp_send_json_success( $gone );
		}

		wp_send_json_success( $payload );
	}

	/** Is the viewer a member of the share's conversation (or someone who may see everyone's media)? */
	public static function viewer_in_conversation( int $viewer, int $conv ): bool {
		if ( $conv <= 0 ) {
			return true;   // a share made outside Messages: ZDZ_Media_Share::get_share() already decided
		}
		if ( class_exists( 'ZDZ_User_Media' ) && method_exists( 'ZDZ_User_Media', 'viewer_can_view_others' ) && ZDZ_User_Media::viewer_can_view_others( $viewer ) ) {
			return true;
		}
		return class_exists( 'ZIM_Membership' ) && ZIM_Membership::is_member( $viewer, $conv );
	}

	/** admin-ajax: revoke a whole share (sender or admin only, decided by the theme). */
	public static function ajax_revoke() {
		self::gate();
		if ( function_exists( 'zim_user_can_write' ) && ! zim_user_can_write( get_current_user_id() ) ) {
			wp_send_json_error( array( 'message' => __( 'This account has read-only messaging access.', 'zorderz' ) ), 403 );
		}
		$group = isset( $_POST['group'] ) ? preg_replace( '/[^a-f0-9]/i', '', (string) wp_unslash( $_POST['group'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in self::gate(); reduced to hex digits.
		if ( '' === $group || ! class_exists( 'ZDZ_Media_Share' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to revoke.', 'zorderz' ) ), 400 );
		}
		$ok = ZDZ_Media_Share::revoke( get_current_user_id(), $group );
		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'You cannot stop this share.', 'zorderz' ) ), 403 );
		}
		wp_send_json_success( array( 'revoked' => true ) );
	}

	/** Nonce + login + capability + availability, as ZIM_Dashboard's gate; never an account in Privacy View. */
	private static function gate() {
		if ( ! check_ajax_referer( ZIM_NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid or expired nonce.', 'code' => 'zim_bad_nonce' ), 403 );
		}
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Not logged in.', 'code' => 'zim_not_logged_in' ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( array( 'message' => 'Missing capability.', 'code' => 'zim_no_capability' ), 403 );
		}
		if ( class_exists( 'ZIM_Widget' ) && ! ZIM_Widget::should_render() ) {
			wp_send_json_error( array( 'message' => 'Not available.', 'code' => 'zim_unavailable' ), 404 );
		}
		if ( class_exists( 'ZIM_Privacy_View' ) && ZIM_Privacy_View::on() ) {
			wp_send_json_error( array( 'message' => 'Not available.', 'code' => 'zim_unavailable' ), 403 );
		}
	}
}

ZIM_Media_Cards::init();
