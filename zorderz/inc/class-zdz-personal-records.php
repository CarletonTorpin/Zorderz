<?php
/**
 * Zorderz Theme — Personal Records (v2.13.0)
 *
 * Backend infrastructure for tracking all-time bests per user:
 *   most_jobs_in_week, most_estimates_in_month,
 *   most_new_clients_in_month, longest_activity_streak,
 *   most_surveys_in_month
 *
 * Updates happen incrementally via event hooks fired by other
 * plugins (`zdz_invoice_paid`, `zdz_install_marked_complete`). A daily
 * cron recomputes streaks. No page-load work, no frontend rendering.
 *
 * Race safety: `ON DUPLICATE KEY UPDATE … GREATEST(VALUES, current)`
 * so two concurrent webhooks produce exactly one row with the
 * larger value — no lock contention, no lost updates.
 *
 * @package Zorderz
 * @since   2.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Personal_Records {

	const RECORD_TYPES = array(
		'most_jobs_in_week',          // Most jobs completed in a single week
		'most_estimates_in_month',    // Most estimates created in one month
		'most_new_clients_in_month',  // Most new clients acquired in one month
		'longest_activity_streak',    // Longest consecutive days with activity
		'most_surveys_in_month',      // Most customer surveys completed in a month
	);

	public static function init(): void {
		// v2.20.0: Updated hooks for work-achievement records (not financial)
		add_action( 'zdz_estimate_created', array( __CLASS__, 'on_estimate_created' ), 10, 2 );
		add_action( 'zdz_job_completed', array( __CLASS__, 'on_job_completed' ), 10, 2 );
		add_action( 'zdz_client_created', array( __CLASS__, 'on_client_created' ), 10, 2 );
		add_action( 'zdz_survey_completed', array( __CLASS__, 'on_survey_completed' ), 10, 2 );
		add_action( 'zdz_personal_records_streak_tick', array( __CLASS__, 'recompute_all_streaks' ) );
		add_action( 'init', array( __CLASS__, 'schedule_streak_cron' ) );
	}

	public static function schedule_streak_cron(): void {
		if ( ! wp_next_scheduled( 'zdz_personal_records_streak_tick' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 3:00am' ), 'daily', 'zdz_personal_records_streak_tick' );
		}
	}

	/* ── Race-safe UPSERT ────────────────────────────────────── */

	public static function try_record( int $user_id, string $record_type, float $value, ?string $achieved_at = null, array $context = array() ): void {
		if ( $user_id <= 0 || '' === $record_type || ! in_array( $record_type, self::RECORD_TYPES, true ) ) {
			return;
		}
		global $wpdb;
		$t = $wpdb->prefix . 'zdz_personal_records';

		$achieved_at  = $achieved_at ?: current_time( 'mysql', true );
		$context_json = wp_json_encode( $context );

		$sql = $wpdb->prepare(
			"INSERT INTO {$t} (user_id, record_type, record_value, achieved_at, context_json)
			 VALUES (%d, %s, %f, %s, %s)
			 ON DUPLICATE KEY UPDATE
			   achieved_at = IF(VALUES(record_value) > record_value, VALUES(achieved_at), achieved_at),
			   context_json = IF(VALUES(record_value) > record_value, VALUES(context_json), context_json),
			   record_value = GREATEST(record_value, VALUES(record_value))",
			$user_id, $record_type, $value, $achieved_at, $context_json
		);
		$wpdb->query( $sql );
	}

	public static function get_records_for_user( int $user_id ): array {
		global $wpdb;
		$t = $wpdb->prefix . 'zdz_personal_records';
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d", $user_id ),
			ARRAY_A
		) ?: array();
		$by_type = array();
		foreach ( $rows as $r ) { $by_type[ $r['record_type'] ] = $r; }
		return $by_type;
	}

	/* ── Event listeners (v2.20.0 r4) ────────────────────────── */
	/* These methods are called when plugins fire the corresponding
	   do_action() hooks. Each counts the user's records for the
	   current period and calls try_record() if the count might be
	   a new personal best. */

	/**
	 * Fired by zdz-estimate-creator when an estimate is created.
	 * Counts estimates this calendar month for the user.
	 */
	public static function on_estimate_created( $user_id, $meta = array() ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) { return; }

		// Count estimates this month using the plugin's table if available,
		// otherwise fall back to the passed-in meta count.
		$count = isset( $meta['month_count'] ) ? (int) $meta['month_count'] : null;
		if ( null === $count ) {
			global $wpdb;
			$table = $wpdb->prefix . 'tsec_estimates';
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
				$count = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE created_by = %d AND created_at >= %s",
					$user_id,
					gmdate( 'Y-m-01 00:00:00' )
				) );
			}
		}
		if ( $count && $count > 0 ) {
			self::try_record( $user_id, 'most_estimates_in_month', (float) $count, current_time( 'mysql', true ), [
				'month' => gmdate( 'Y-m' ),
				'trigger' => 'estimate_created',
			] );
		}
	}

	/**
	 * Fired by zdz-invoice-creator or zdz-satisfaction-surveys when a job
	 * is completed (invoice paid or survey sent). Counts jobs this week.
	 */
	public static function on_job_completed( $user_id, $meta = array() ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) { return; }

		$count = isset( $meta['week_count'] ) ? (int) $meta['week_count'] : null;
		if ( null === $count ) {
			// Count jobs this ISO week from user activity log.
			// Plugins should pass week_count in $meta for accuracy.
			// Without it, we count do_action calls via a transient counter.
			$week_key = 'zdz_pr_jobs_' . $user_id . '_' . gmdate( 'oW' );
			$count = (int) get_transient( $week_key ) + 1;
			set_transient( $week_key, $count, 8 * DAY_IN_SECONDS );
		}
		if ( $count > 0 ) {
			self::try_record( $user_id, 'most_jobs_in_week', (float) $count, current_time( 'mysql', true ), [
				'week' => gmdate( 'oW' ),
				'trigger' => 'job_completed',
			] );
		}
	}

	/**
	 * Fired by zdz-estimate-creator when a NEW client is created in FreshBooks.
	 * Counts new clients this calendar month.
	 */
	public static function on_client_created( $user_id, $meta = array() ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) { return; }

		$count = isset( $meta['month_count'] ) ? (int) $meta['month_count'] : null;
		if ( null === $count ) {
			$month_key = 'zdz_pr_clients_' . $user_id . '_' . gmdate( 'Ym' );
			$count = (int) get_transient( $month_key ) + 1;
			set_transient( $month_key, $count, 32 * DAY_IN_SECONDS );
		}
		if ( $count > 0 ) {
			self::try_record( $user_id, 'most_new_clients_in_month', (float) $count, current_time( 'mysql', true ), [
				'month' => gmdate( 'Y-m' ),
				'trigger' => 'client_created',
			] );
		}
	}

	/**
	 * Fired by zdz-satisfaction-surveys when a survey response is received.
	 * Counts surveys completed this calendar month for the assigned user.
	 */
	public static function on_survey_completed( $user_id, $meta = array() ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) { return; }

		$count = isset( $meta['month_count'] ) ? (int) $meta['month_count'] : null;
		if ( null === $count ) {
			$month_key = 'zdz_pr_surveys_' . $user_id . '_' . gmdate( 'Ym' );
			$count = (int) get_transient( $month_key ) + 1;
			set_transient( $month_key, $count, 32 * DAY_IN_SECONDS );
		}
		if ( $count > 0 ) {
			self::try_record( $user_id, 'most_surveys_in_month', (float) $count, current_time( 'mysql', true ), [
				'month' => gmdate( 'Y-m' ),
				'trigger' => 'survey_completed',
			] );
		}
	}

	/* ── Streak cron (daily, not per-event) ──────────────────── */

	public static function recompute_all_streaks(): void {
		// v2.20.3: Paginate through all users instead of capping at 500.
		// Previous hard cap silently dropped users beyond the limit.
		$page = 1;
		do {
			$batch = get_users( array( 'fields' => 'ID', 'number' => 100, 'paged' => $page ) );
			foreach ( $batch as $uid ) {
				$days = apply_filters( 'zdz_user_active_dates', array(), (int) $uid );
				if ( empty( $days ) ) { continue; }
				$streak = self::longest_streak( $days );
				if ( $streak > 0 ) {
					self::try_record( (int) $uid, 'longest_activity_streak', (float) $streak );
				}
			}
			$page++;
		} while ( count( $batch ) === 100 );
	}

	public static function longest_streak( array $dates ): int {
		if ( empty( $dates ) ) { return 0; }
		sort( $dates );
		$longest = 1;
		$current = 1;
		$prev    = strtotime( $dates[0] );
		for ( $i = 1; $i < count( $dates ); $i++ ) {
			$cur = strtotime( $dates[ $i ] );
			if ( $cur === $prev + DAY_IN_SECONDS ) {
				$current++;
				if ( $current > $longest ) { $longest = $current; }
			} elseif ( $cur > $prev + DAY_IN_SECONDS ) {
				$current = 1;
			}
			$prev = $cur;
		}
		return $longest;
	}
}

add_action( 'plugins_loaded', array( 'ZDZ_Personal_Records', 'init' ) );
