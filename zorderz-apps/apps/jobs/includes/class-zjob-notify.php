<?php
/**
 * Zorderz Jobs — notifications ("a step just became yours").
 *
 * The moment a job becomes a person's to act on, they get a real message. We do NOT
 * invent a parallel notifier — we send through the platform's existing DM system
 * (the Messaging app, ZIM). One DM gives the message, the in-app unread badge and a
 * push notification at once, via ZIM's own machinery.
 *
 *   scheduled / reassigned -> the WORKER (assignee) hears "a job is now yours".
 *   worker marked complete  -> the ORIGINATOR (created_by) hears "ready to close".
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

	/** Is the Messaging DM path available? */
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

	/* =======================================================================
	 * INTERNALS
	 * ======================================================================= */

	/**
	 * Send a best-effort DM from the actor (or a filtered sender) to the target.
	 * Wrapped so nothing here can ever throw into the calling AJAX handler.
	 */
	private static function dm_from_actor( int $actor_id, int $to, string $body, string $context ): bool {
		try {
			if ( ! self::available() ) {
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

			$conv = (int) ZIM_DMs::get_or_create_conversation( $from, $to );
			if ( $conv <= 0 ) {
				return false;
			}
			$res = ZIM_Messages::post( $conv, $from, $body );
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
