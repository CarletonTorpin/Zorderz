<?php
/**
 * ZJOB_Handoff — the published face of a job (a handed-off piece of work) for other apps.
 *
 * Prep, Receipts and the chat read and record jobs through this class, behind class_exists( 'ZJOB_Handoff' ), so they
 * never hold a copy of the jobs schema or re-derive a permission. Every method forwards to the jobs model (ZJOB_Jobs)
 * or the job history (ZJOB_Events); none adds a rule of its own.
 *
 *   get( $id )                                   one job row, or null.
 *   create( $data, $actor_id )                   a new job (codes sealed, salesperson code stamped); its id or 0.
 *   record_event( $id, $type, $actor, $words, $context )  one line of the job's history, then the audit seam
 *                                                (`zjob_job_audited`) so the Project re-derives its status.
 *   actor_can_manage / actor_can_schedule / actor_can_close   the jobs model's own gates.
 *   user_can_hand_off( $uid )                    may this person hand work off at all.
 *   set_status / change_status( ..., $expected ) a status change that is refused when the job moved on.
 *   clear_schedule( $id, $actor )                take a job off the calendar (the jobs model's own path).
 *   is_scheduled( $row ), list_for( $actor, $status ), is_test_name, cleared_ids, clear_test_jobs.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Handoff {

	const STATUSES = array( 'open', 'in_progress', 'pending_close', 'done', 'cancelled' );

	private static function ready(): bool {
		return class_exists( 'ZJOB_Jobs' );
	}

	public static function get( int $id ): ?array {
		return self::ready() ? ZJOB_Jobs::get( $id ) : null;
	}

	public static function create( array $data, int $actor_id ): int {
		return self::ready() ? ZJOB_Jobs::create( $data, $actor_id ) : 0;
	}

	/**
	 * One line of a job's history, written as the jobs app writes its own (best-effort: a history line never breaks
	 * the work), then the audit seam so a Project holding the job refreshes. $words is the sentence a person reads.
	 */
	public static function record_event( int $job_id, string $type, int $actor_id, string $words, array $context = array() ): void {
		if ( $job_id <= 0 ) {
			return;
		}
		$context['job_id'] = $job_id;
		try {
			if ( class_exists( 'ZJOB_Events' ) ) {
				$summary = class_exists( 'ZJOB_Sealed' ) ? (string) ZJOB_Sealed::cut( $words ) : $words;
				ZJOB_Events::record( $job_id, $type, array(
					'summary'  => $summary,
					'actor_id' => max( 0, $actor_id ),
					'context'  => $context,
				) );
			}
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Jobs: a history line for job ' . $job_id . ' was not written.' );
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( 'zjob_job_audited', $context );
		}
	}

	public static function actor_can_manage( int $actor_id, array $row ): bool {
		return self::ready() && ZJOB_Jobs::actor_can_manage( $actor_id, $row );
	}

	public static function actor_can_schedule( int $actor_id, array $row ): bool {
		return self::ready() && ZJOB_Jobs::actor_can_schedule( $actor_id, $row );
	}

	public static function actor_can_close( int $actor_id, array $row ): bool {
		return self::ready() && ZJOB_Jobs::actor_can_close( $actor_id, $row );
	}

	public static function user_can_hand_off( int $user_id ): bool {
		return self::ready() && ZJOB_Jobs::user_can_hand_off( $user_id );
	}

	public static function set_status( int $id, string $status, int $actor_id, string $expected = '' ): bool {
		return self::ready() && ZJOB_Jobs::set_status( $id, $status, $actor_id, $expected );
	}

	public static function change_status( int $id, string $status, int $actor_id, string $expected = '' ): array {
		return self::ready() ? ZJOB_Jobs::change_status( $id, $status, $actor_id, $expected ) : array( 'ok' => false, 'error' => 'status_failed', 'current' => '' );
	}

	public static function clear_schedule( int $id, int $actor_id ): array {
		return self::ready() ? ZJOB_Jobs::clear_schedule( $id, $actor_id ) : array( 'ok' => false, 'error' => 'unavailable' );
	}

	public static function is_scheduled( array $row ): bool {
		return self::ready() && ZJOB_Jobs::is_scheduled( $row );
	}

	public static function list_for( int $actor_id, string $status_filter = '' ): array {
		return self::ready() ? ZJOB_Jobs::list_for( $actor_id, $status_filter ) : array();
	}

	public static function is_test_name( string $name ): bool {
		return 1 === preg_match( '/(?<![A-Za-z0-9])test(?![A-Za-z0-9])/i', $name );
	}

	public static function cleared_ids(): array {
		return self::ready() ? ZJOB_Jobs::cleared_ids() : array();
	}

	public static function clear_test_jobs( int $actor_id ): array {
		return self::ready() ? ZJOB_Jobs::clear_test_jobs( $actor_id ) : array( 'ok' => false, 'error' => 'unavailable', 'count' => 0 );
	}
}
