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

		$leads = $wpdb->get_results(
			"SELECT id, email, first_name, last_name, crm_lead_id
			 FROM {$t}
			 WHERE email_sent_at IS NOT NULL
			   AND ( review_left = 0 OR review_left IS NULL )
			   AND ( review_checked_at IS NULL OR review_checked_at < DATE_SUB(NOW(), INTERVAL 6 HOUR) )
			 ORDER BY email_sent_at DESC
			 LIMIT 200",
			ARRAY_A
		);
		if ( empty( $leads ) ) {
			return array( 'checked' => 0, 'found' => 0, 'errors' => 0 );
		}

		$stats = array( 'checked' => 0, 'found' => 0, 'errors' => 0 );
		$crm   = ZSV_Settings::crm();

		foreach ( $leads as $i => $lead ) {
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
				$date = $now;
				if ( ! empty( $result['date'] ) ) {
					$p = strtotime( (string) $result['date'] );
					if ( $p ) {
						$date = gmdate( 'Y-m-d H:i:s', $p );
					}
				}
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

				// A confirmed review closes the loop (best-effort CRM note).
				if ( $crm && ! empty( $lead['crm_lead_id'] ) ) {
					try {
						$body = '[Surveys] Review confirmed via bridge on ' . wp_date( 'M j, Y g:i A' );
						if ( $snippet ) {
							$body .= "\n\n\"" . $snippet . '"';
						}
						$crm->add_note( array( 'entity' => array( 'entityType' => 'leads', 'id' => (int) $lead['crm_lead_id'] ), 'note' => array( 'body' => $body ) ) );
					} catch ( \Throwable $e ) {
						ZSV_DB::disposition( 'review_note_failed', array( 'lead_id' => (int) $lead['id'], 'error' => $e->getMessage() ) );
					}
				}
			}

			if ( 0 === ( $i + 1 ) % 50 && ( $i + 1 ) < count( $leads ) ) {
				sleep( 2 );
			}
		}
		return $stats;
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
