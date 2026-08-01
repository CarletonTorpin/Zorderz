<?php
/**
 * Zorderz Jobs — Scheduler bridge.
 *
 * Links a job to a real Scheduler appointment (the Scheduler app is the scheduling
 * AUTHORITY). When someone dispatches a job's time inside Jobs, we create/update a
 * native appointment via ZSCH_Appointments so it lands on the team calendar. The job
 * row then caches the appointment's start/end (UTC) + tz for fast gating + display.
 *
 * Best-effort + fail-closed: every call is guarded by class_exists so a missing or
 * older Scheduler degrades gracefully (scheduling simply reports unavailable; the job
 * record is never harmed). The caller (ZJOB_Jobs) gates who may schedule; the plain
 * worker never does.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Scheduler {

	/** Default job window when a start is given but no explicit duration (filterable). */
	const DEFAULT_DURATION_MIN = 120;

	/** Calendar colour for job appointments (matches the Jobs app plate). */
	const JOB_COLOR = '#0E9F8E';

	/** Is the Scheduler app present (its appointments model loaded)? */
	public static function available(): bool {
		return class_exists( 'ZSCH_Appointments' );
	}

	/** Default appointment duration in minutes (per-work-type via the filter). */
	public static function default_duration_min( array $row = [] ): int {
		return max( 1, (int) apply_filters( 'zdz_job_appt_duration_min', self::DEFAULT_DURATION_MIN, $row ) );
	}

	/**
	 * The business time zone the Scheduler renders wall-clock in. Prefers the
	 * Scheduler's setting, then the site's own timezone — never a hardcoded place.
	 */
	public static function default_tz(): string {
		if ( class_exists( 'ZSCH_Settings' ) && method_exists( 'ZSCH_Settings', 'default_tz' ) ) {
			$tz = (string) ZSCH_Settings::default_tz();
			if ( $tz !== '' ) {
				return $tz;
			}
		}
		$site = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : '';
		return $site !== '' ? $site : 'UTC';
	}

	/**
	 * Schedule (or reschedule) a job. $start_local is a wall-clock string in the
	 * business tz. Creates a new appointment when the job has none, else updates the
	 * linked one.
	 *
	 * @return array{ok:bool,appt_id:int,start_utc:string,end_utc:string,tz:string,error:string}
	 */
	public static function schedule_job( array $row, string $start_local, int $duration_min, int $actor_id ): array {
		$out = array( 'ok' => false, 'appt_id' => 0, 'start_utc' => '', 'end_utc' => '', 'tz' => '', 'error' => '' );

		if ( ! self::available() ) {
			$out['error'] = 'scheduler_unavailable';
			return $out;
		}

		$tz       = self::default_tz();
		$duration = $duration_min > 0 ? $duration_min : self::default_duration_min( $row );
		$window   = self::local_window( $start_local, $duration, $tz );
		if ( null === $window ) {
			$out['error'] = 'bad_time';
			return $out;
		}
		list( $start_local_norm, $end_local ) = $window;

		$assignee = (int) ( $row['assigned_user_id'] ?? 0 );
		$fields   = array(
			'start_local' => $start_local_norm,
			'end_local'   => $end_local,
			'time_zone'   => $tz,
			'title'       => self::title_for( $row ),
			'location'    => (string) ( $row['customer_address'] ?? '' ),
			'body'        => self::body_for( $row ),
		);

		$existing = (int) ( $row['scheduled_appt_id'] ?? 0 );
		if ( $existing > 0 ) {
			$res = ZSCH_Appointments::update( $actor_id, $existing, $fields );
		} else {
			$res = ZSCH_Appointments::create( $actor_id, array_merge( $fields, array(
				'calendar_scope' => 'shared',   // dispatch info the whole team can see
				'owner_id'       => $assignee,  // honoured when the actor is a Scheduler admin
				'busy_status'    => 'busy',
				'color'          => self::JOB_COLOR,
			) ) );
		}

		if ( empty( $res['success'] ) ) {
			$out['error'] = ! empty( $res['error'] ) ? (string) $res['error'] : 'scheduler_error';
			return $out;
		}

		$appt_id = (int) ( $res['id'] ?? $existing );
		$raw = ZSCH_Appointments::get_raw( $appt_id );
		if ( is_array( $raw ) ) {
			$out['start_utc'] = (string) ( $raw['start_utc'] ?? '' );
			$out['end_utc']   = (string) ( $raw['end_utc'] ?? '' );
			$out['tz']        = (string) ( $raw['time_zone'] ?? $tz );
		} else {
			$out['tz'] = $tz;
		}
		$out['ok']      = true;
		$out['appt_id'] = $appt_id;
		return $out;
	}

	/**
	 * Cancel a job's schedule: delete the linked appointment. ok=true when there was
	 * nothing to do (no link) or the delete succeeded.
	 *
	 * @return array{ok:bool,error:string}
	 */
	public static function unschedule_job( array $row, int $actor_id ): array {
		$out  = array( 'ok' => false, 'error' => '' );
		$appt = (int) ( $row['scheduled_appt_id'] ?? 0 );
		if ( $appt <= 0 ) {
			$out['ok'] = true; // already unscheduled
			return $out;
		}
		if ( ! self::available() ) {
			$out['error'] = 'scheduler_unavailable';
			return $out;
		}
		$res = ZSCH_Appointments::delete( $actor_id, $appt );
		$out['ok'] = ! empty( $res['success'] );
		if ( ! $out['ok'] ) {
			$out['error'] = ! empty( $res['error'] ) ? (string) $res['error'] : 'scheduler_error';
		}
		return $out;
	}

	/* ---------------------------------------------------------------------- */

	/**
	 * Validate a wall-clock start and derive the end. Returns [start_norm, end] as
	 * "Y-m-d H:i:s" local strings, or null if the start won't parse.
	 */
	private static function local_window( string $start_local, int $duration_min, string $tz ): ?array {
		$start_local = trim( str_replace( 'T', ' ', $start_local ) );
		if ( $start_local === '' ) {
			return null;
		}
		try {
			$zone  = new DateTimeZone( $tz );
			$start = new DateTime( $start_local, $zone );
			$end   = clone $start;
			$end->modify( '+' . max( 1, $duration_min ) . ' minutes' );
			return array( $start->format( 'Y-m-d H:i:s' ), $end->format( 'Y-m-d H:i:s' ) );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/** Calendar title: "<component> - <customer>". */
	private static function title_for( array $row ): string {
		$comp = function_exists( 'zjob_component_label' )
			? zjob_component_label( (string) ( $row['component'] ?? '' ) )
			: ucfirst( (string) ( $row['component'] ?? 'Job' ) );
		$cust = trim( (string) ( $row['customer_name'] ?? '' ) );
		$title = $cust !== '' ? ( $comp . ' - ' . $cust ) : $comp;
		return mb_substr( $title, 0, 200 );
	}

	/** Appointment body: concise internal context (never a customer artifact). */
	private static function body_for( array $row ): string {
		$lines = array();
		$lines[] = 'Job #' . (int) ( $row['id'] ?? 0 ) . ' (internal - not a customer invoice line).';
		if ( ! empty( $row['customer_business'] ) ) { $lines[] = 'Business: ' . $row['customer_business']; }
		if ( ! empty( $row['customer_phone'] ) )    { $lines[] = 'Phone: ' . $row['customer_phone']; }
		if ( ! empty( $row['brand'] ) || ! empty( $row['qty'] ) ) {
			$lines[] = 'Item: ' . trim( (string) ( $row['brand'] ?? '' ) . ' x' . (int) ( $row['qty'] ?? 0 ) );
		}
		if ( ! empty( $row['access_notes'] ) ) { $lines[] = 'Access: ' . $row['access_notes']; }
		if ( ! empty( $row['notes'] ) )        { $lines[] = 'Notes: ' . $row['notes']; }
		return implode( "\n", $lines );
	}
}
