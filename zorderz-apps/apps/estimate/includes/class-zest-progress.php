<?php
/**
 * ZEST_Progress — lightweight, transient-backed progress for a background parse job.
 *
 * A long vision/parse call reports staged progress so the widget can show "Reading
 * note… / Pricing…" instead of a dead spinner. Nothing here is identity; it is pure
 * UX plumbing keyed by job id.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Progress {

	private static function key( $job_id ): string {
		return 'zest_job_' . (int) $job_id;
	}

	/** Set the current stage + percentage for a job (expires in 15 minutes). */
	public static function set( $job_id, string $stage, int $pct, array $extra = array() ): void {
		set_transient( self::key( $job_id ), array_merge( array(
			'stage'      => $stage,
			'pct'        => max( 0, min( 100, $pct ) ),
			'updated_at' => time(),
		), $extra ), 15 * MINUTE_IN_SECONDS );
	}

	/** Read a job's progress, or a neutral "queued" default. */
	public static function get( $job_id ): array {
		$p = get_transient( self::key( $job_id ) );
		return is_array( $p ) ? $p : array( 'stage' => 'queued', 'pct' => 0, 'updated_at' => 0 );
	}

	public static function clear( $job_id ): void {
		delete_transient( self::key( $job_id ) );
	}
}
