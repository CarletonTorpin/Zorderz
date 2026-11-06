<?php
/**
 * Zorderz Surveys — review-bridge poller.
 *
 * Checks the Core review bridge (ZDZ_Core_ReviewBridge) for customers who received an
 * invite but have not been confirmed as having left a review. A confirmed review is a
 * signal that closes the follow-up. The review "source" is a neutral marker
 * ('review_bridge'), not a hostname; the bridge endpoint itself lives in Core config.
 *
 * @package Zorderz\Surveys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSV_Review_Checker {

	const CRON_HOOK = 'zsv_review_check';

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'wp_ajax_zsv_check_reviews', array( __CLASS__, 'ajax_check' ) );
		self::schedule();
	}

	/** Schedule the daily poll if not already scheduled. */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow midnight' ), 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * Check pending leads (invite sent, review not yet confirmed, not checked in 6h)
	 * against the Core review bridge.
	 *
	 * @param bool $bypass_cache
	 * @return array{checked:int,found:int,errors:int,error?:string}
	 */
	public static function check_pending_leads( bool $bypass_cache = false ): array {
		$bridge = ZSV_Settings::review_bridge();
		if ( ! $bridge ) {
			// Fail loudly rather than pretend a clean run.
			ZSV_DB::disposition( 'review_bridge_unavailable', array() );
			return array( 'checked' => 0, 'found' => 0, 'errors' => 0, 'error' => 'review bridge not configured' );
		}

		global $wpdb;
		$t   = ZSV_DB::leads_table();
		$now = current_time( 'mysql' );

		// review_checked_at is written in the site's time, so the "not in the last 6 hours"
		// cutoff is computed in the same frame (comparing it with the database's UTC NOW()
		// moved the window by the site's offset, and every run re-checked every lead).
		$leads = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, email, first_name, last_name, crm_lead_id
				 FROM {$t}
				 WHERE email_sent_at IS NOT NULL
				   AND ( review_left = 0 OR review_left IS NULL )
				   AND ( review_checked_at IS NULL OR review_checked_at < %s )
				 ORDER BY email_sent_at DESC
				 LIMIT 200",
				self::recheck_cutoff()
			),
			ARRAY_A
		);
		if ( empty( $leads ) ) {
			return array( 'checked' => 0, 'found' => 0, 'errors' => 0 );
		}

		$stats   = array( 'checked' => 0, 'found' => 0, 'errors' => 0, 'deferred' => 0 );
		$manager = new ZSV_Survey_Manager();
		$opened  = ZSV_Budget::open( 'review_check', (float) ZSV_Survey_Manager::SWEEP_BUDGET_S );
		try {
			$manager->drain_owed_notes();
			self::check_leads( $leads, $bridge, $manager, $bypass_cache, $now, $stats );
		} finally {
			if ( $opened ) {
				ZSV_Budget::log_run( 'review_check', $stats['checked'], count( $leads ), $stats['deferred'] );
				ZSV_Budget::close();
			}
		}
		return $stats;
	}

	/** The site-local "Y-m-d H:i:s" six hours ago: a lead checked since then is not checked again. Pure given now. */
	public static function recheck_cutoff( ?int $now = null, ?DateTimeZone $tz = null ): string {
		$tz = $tz ?? ( function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ) );
		return ( new DateTimeImmutable( '@' . ( null === $now ? time() : $now ) ) )->setTimezone( $tz )->modify( '-6 hours' )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * A review date from the bridge, in the site's time like every other date in the table.
	 * The bridge sends ISO 8601 with an offset; a date with no zone is kept as given. Pure.
	 */
	public static function review_date_from_bridge( string $raw, string $fallback, ?DateTimeZone $tz = null ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return $fallback;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $raw ) ) {
			return strlen( $raw ) === 10 ? $raw . ' 00:00:00' : ( strlen( $raw ) === 16 ? $raw . ':00' : $raw );
		}
		try {
			$tz = $tz ?? ( function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ) );
			return ( new DateTimeImmutable( $raw ) )->setTimezone( $tz )->format( 'Y-m-d H:i:s' );
		} catch ( \Throwable $e ) {
			return $fallback;
		}
	}

	/** One lead at a time while the budget lets the next start; the rest wait for the next run. */
	private static function check_leads( array $leads, $bridge, ZSV_Survey_Manager $manager, bool $bypass_cache, string $now, array &$stats ): void {
		global $wpdb;
		$t = ZSV_DB::leads_table();
		foreach ( $leads as $i => $lead ) {
			if ( ! ZSV_Budget::can_start( 2 ) ) {
				$stats['deferred']++;
				continue;
			}
			$stats['checked']++;
			$name = trim( ( $lead['first_name'] ?? '' ) . ' ' . ( $lead['last_name'] ?? '' ) );

			$result = $bridge->check_review( (string) $lead['email'], $name, $bypass_cache );
			if ( null === $result ) {
				$stats['errors']++;
				ZSV_DB::disposition( 'source_unavailable', array( 'stage' => 'review_check', 'lead_id' => (int) $lead['id'] ) );
				continue;
			}

			$wpdb->update( $t, array( 'review_checked_at' => $now ), array( 'id' => (int) $lead['id'] ), array( '%s' ), array( '%d' ) );

			if ( ! empty( $result['found'] ) ) {
				$stats['found']++;
				$date = self::review_date_from_bridge( (string) ( $result['date'] ?? '' ), $now );
				$snippet = ! empty( $result['snippet'] ) ? sanitize_text_field( (string) $result['snippet'] ) : null;
				$wpdb->update(
					$t,
					array(
						'review_left'       => 1,
						'review_source'     => 'review_bridge', // neutral marker, not a hostname.
						'review_date'       => $date,
						'review_snippet'    => $snippet,
						'review_checked_at' => $now,
					),
					array( 'id' => (int) $lead['id'] ),
					array( '%d', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);

				// A confirmed review closes the loop with a CRM note — durable: one not posted
				// now is posted by the next sweep (the lead has left the set this check reads).
				if ( ! empty( $lead['crm_lead_id'] ) ) {
					$body = '[Surveys] Review confirmed via bridge on ' . wp_date( 'M j, Y g:i A' );
					if ( $snippet ) {
						$body .= "\n\n\"" . $snippet . '"';
					}
					$state = $manager->post_note_durably( 'reviewed', (int) $lead['crm_lead_id'], (int) $lead['id'], $body );
					if ( 'posted' !== $state ) {
						ZSV_DB::disposition( 'review_note_owed', array( 'lead_id' => (int) $lead['id'], 'state' => $state ) );
					}
				}
			}
		}
	}

	public static function run(): void {
		self::check_pending_leads( false );
	}

	public static function ajax_check(): void {
		check_ajax_referer( ZSV_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		wp_send_json_success( self::check_pending_leads( true ) );
	}
}
