<?php
/**
 * Zorderz Jobs — notifications ("a step just became yours").
 *
 * The moment a job becomes a person's to act on, they get a real message. We do NOT
 * invent a parallel notifier — we send through the platform's existing DM system
 * (the Messaging app, ZIM), on its own seam `zim_dm_send`: Messages decides who may
 * send and receive and makes the post. One DM gives the message, the in-app unread
 * badge and a push notification at once, via ZIM's own machinery.
 *
 *   scheduled / reassigned -> the WORKER (assignee) hears "a job is now yours" (and a bell row
 *                             through the alert router, ZJOB_Accept_Notice::job_assigned).
 *   worker marked complete  -> the ORIGINATOR (created_by) hears "ready to close".
 *   a project message       -> each person on the project hears the start of it, with a link.
 * A notice never carries an access code: every body is cut through ZJOB_Sealed first.
 * The sender defaults to the actor who caused the transition, overridable via
 * `zdz_job_notify_sender` so a site can route through a dedicated bot user.
 *
 * Best-effort and non-blocking: every path is wrapped so a messaging hiccup can never
 * break or delay the job transition. No self-notify; kiosk never sends or receives.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Notify {

	/** Can a direct message be sent? Through the Messaging app's own seam (`zim_dm_send`) only. */
	public static function can_send(): bool {
		return function_exists( 'has_filter' ) && false !== has_filter( 'zim_dm_send' );
	}

	/** Can a direct conversation be opened (the People block's link)? */
	public static function available(): bool {
		return class_exists( 'ZIM_DMs' ) && class_exists( 'ZIM_Messages' )
			&& method_exists( 'ZIM_DMs', 'get_or_create_conversation' )
			&& method_exists( 'ZIM_Messages', 'post' );
	}

	/**
	 * A job is now the worker's to do (fired after it is scheduled, or reassigned).
	 * DMs the assignee. $actor_id is the dispatcher who scheduled/reassigned it.
	 */
	public static function job_assigned( array $row, int $actor_id ): bool {
		// A bell row beside the message. In-app only: the message already pushes.
		if ( class_exists( 'ZJOB_Accept_Notice' ) ) {
			ZJOB_Accept_Notice::job_assigned( $row, $actor_id );
		}
		$to        = (int) ( $row['assigned_user_id'] ?? 0 );
		$comp      = self::component_label( (string) ( $row['component'] ?? '' ) );
		$customer  = trim( (string) ( $row['customer_name'] ?? '' ) );
		$who       = '' !== $customer ? $customer : 'a customer';
		$scheduled = (int) ( $row['scheduled_appt_id'] ?? 0 ) > 0;

		if ( $scheduled ) {
			$when = self::when_label( $row );
			$body = sprintf(
				'New job for you: %s for %s%s. Open Jobs for the address and details.',
				$comp,
				$who,
				'' !== $when ? ' - ' . $when : ''
			);
		} else {
			$body = sprintf(
				'You have been assigned a job: %s for %s. It is in your Jobs (Future tab) - you will get the time once it is scheduled.',
				$comp,
				$who
			);
		}
		return self::dm_from_actor( $actor_id, $to, $body, 'assigned' );
	}

	/**
	 * The worker handed their part back (fired after worker_complete). DMs the
	 * originator that it is ready for their close-out. $worker_id did the work.
	 */
	public static function ready_to_close( array $row, int $worker_id ): bool {
		$to       = (int) ( $row['created_by'] ?? 0 );
		$comp     = self::component_label( (string) ( $row['component'] ?? '' ) );
		$customer = trim( (string) ( $row['customer_name'] ?? '' ) );
		$who      = '' !== $customer ? $customer : 'a customer';
		$worker   = self::user_label( $worker_id );

		$body = sprintf(
			'%s finished the %s for %s. It is ready for your close-out in Jobs.',
			$worker,
			$comp,
			$who
		);
		return self::dm_from_actor( $worker_id, $to, $body, 'ready_to_close' );
	}

	/**
	 * A new message in a project's conversation. Messages one recipient from the author, with the
	 * start of the message and a link that opens the project. Never self, never the shared device.
	 */
	public static function project_note( array $project, int $author_id, int $to, string $text ): bool {
		$customer = trim( (string) ( $project['customer_name'] ?? ( $project['customer']['name'] ?? '' ) ) );
		$code     = trim( (string) ( $project['human_code'] ?? ( $project['code'] ?? '' ) ) );
		$snippet  = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $snippet ) : strlen( $snippet ) ) > 240 ) {
			$snippet = ( function_exists( 'mb_substr' ) ? mb_substr( $snippet, 0, 237 ) : substr( $snippet, 0, 237 ) ) . '...';
		}
		$link = class_exists( 'ZJOB_Accept_Notice' ) ? ZJOB_Accept_Notice::link( (string) ( $project['id'] ?? '' ), '' ) : home_url( '/' );
		$body = sprintf(
			/* translators: 1: customer or "a project", 2: " (PRJ-…)" or "", 3: the start of the message, 4: link */
			__( 'On %1$s%2$s: "%3$s" Reply in Projects: %4$s', 'zorderz' ),
			'' !== $customer ? $customer : __( 'a project', 'zorderz' ),
			'' !== $code ? ' (' . $code . ')' : '',
			$snippet,
			$link
		);
		return self::dm_from_actor( $author_id, $to, $body, 'project_note' );
	}

	/**
	 * Can $viewer start a direct message with $to from a project's People block? The same questions
	 * the Messaging app asks before it makes one: both may use Messages, the viewer may write (never
	 * the shared device), and it is not themselves. Reads only.
	 */
	public static function dm_ready( int $viewer, int $to ): bool {
		if ( $viewer <= 0 || $to <= 0 || $viewer === $to || ! self::available() ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && ( ZDZ_Hierarchy::is_kiosk( $viewer ) || ZDZ_Hierarchy::is_kiosk( $to ) ) ) {
			return false;
		}
		if ( ! function_exists( 'zim_user_has_access' ) || ! function_exists( 'zim_user_can_write' ) ) {
			return false;
		}
		return zim_user_can_write( $viewer ) && zim_user_has_access( $viewer ) && zim_user_has_access( $to ) && false !== get_userdata( $to );
	}

	/**
	 * The address of the viewer's direct conversation with $to in Messages (made if it does not exist
	 * yet), on Messages' own deep link. Nothing is sent. '' when it cannot be opened.
	 */
	public static function dm_url( int $viewer, int $to ): string {
		try {
			if ( ! self::dm_ready( $viewer, $to ) ) {
				return '';
			}
			$conv = (int) ZIM_DMs::get_or_create_conversation( $viewer, $to );
			return $conv > 0 ? add_query_arg( array( 'zim_page' => 1, 'c' => $conv ), home_url( '/' ) ) : '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/* =======================================================================
	 * INTERNALS
	 * ======================================================================= */

	/**
	 * Send a best-effort DM from the actor (or a filtered sender) to the target.
	 * Wrapped so nothing here can ever throw into the calling AJAX handler.
	 */
	private static function dm_from_actor( int $actor_id, int $to, string $body, string $context ): bool {
		try {
			if ( ! self::can_send() ) {
				return false;
			}
			/**
			 * Who the notification is sent AS. Defaults to the transition actor.
			 *
			 * @param int    $actor_id The transition actor (dispatcher / worker).
			 * @param string $context  'assigned' | 'ready_to_close'.
			 * @param int    $to       The recipient user id.
			 */
			$from = (int) apply_filters( 'zdz_job_notify_sender', $actor_id, $context, $to );

			if ( $from <= 0 || $to <= 0 || $from === $to ) {
				return false; // never notify yourself about your own action
			}
			if ( class_exists( 'ZDZ_Hierarchy' ) && ( ZDZ_Hierarchy::is_kiosk( $to ) || ZDZ_Hierarchy::is_kiosk( $from ) ) ) {
				return false; // the shared device is not a person
			}

			// A notice never carries an access code.
			if ( class_exists( 'ZJOB_Sealed' ) ) {
				$body = (string) ZJOB_Sealed::cut( $body );
			}
			// Messages owns the write: who may send and receive, the conversation, the post.
			$res = apply_filters( 'zim_dm_send', null, $from, $to, $body, array() );
			return is_array( $res ) && ! empty( $res['message_id'] );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** Friendly component label for a message body (Item-Engine-bound; neutral fallback). */
	private static function component_label( string $component ): string {
		if ( function_exists( 'zjob_component_label' ) ) {
			return zjob_component_label( $component );
		}
		$c = strtolower( trim( $component ) );
		return '' !== $c ? $c : 'job';
	}

	/** Wall-clock label for a scheduled job, in the job's own timezone. '' if unknown. */
	private static function when_label( array $row ): string {
		$utc = (string) ( $row['scheduled_start_utc'] ?? '' );
		if ( '' === $utc ) {
			return '';
		}
		$ts = strtotime( $utc . ' UTC' );
		if ( ! $ts ) {
			return '';
		}
		$tz = (string) ( $row['scheduled_tz'] ?? '' );
		try {
			$dt = new DateTime( '@' . $ts );
			if ( '' !== $tz ) {
				$dt->setTimezone( new DateTimeZone( $tz ) );
			}
			return $dt->format( 'D M j, g:i A' );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/** A friendly display label for a WP user. */
	private static function user_label( int $uid ): string {
		$u = get_userdata( $uid );
		return $u ? $u->display_name : ( '#' . $uid );
	}
}
