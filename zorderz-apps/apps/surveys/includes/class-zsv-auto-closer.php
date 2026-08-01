<?php
/**
 * Zorderz Surveys — grace-window auto-close sweep.
 *
 * Structural twin of the review checker: a self-scheduling daily cron plus a manual
 * AJAX escape hatch. The actual decisioning (and the SAFETY FLOOR — never system-Won
 * without human review) lives in ZSV_Survey_Manager::auto_close_stale_leads(). This
 * class only wires the trigger and builds a CRM-only manager.
 *
 * @package Zorderz\Surveys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSV_Auto_Closer {

	const CRON_HOOK = 'zsv_auto_close';

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'wp_ajax_zsv_auto_close', array( __CLASS__, 'ajax_auto_close' ) );
		self::schedule();
	}

	/** Schedule ~00:30 daily so it does not race the review poll at midnight. */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow midnight' ) + 30 * MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * A manager whose handler cannot boot must not silently do nothing forever — if
	 * the CRM is not configured we log and skip visibly.
	 *
	 * @return ZSV_Survey_Manager|null
	 */
	private static function build_manager(): ?ZSV_Survey_Manager {
		$crm = ZSV_Settings::crm();
		if ( ! $crm ) {
			error_log( 'Zorderz Surveys Auto-Closer: CRM not configured — sweep skipped.' );
			ZSV_DB::disposition( 'source_unavailable', array( 'stage' => 'auto_close_boot' ) );
			return null;
		}
		return new ZSV_Survey_Manager( null, $crm );
	}

	public static function run(): void {
		$m = self::build_manager();
		if ( $m ) {
			$m->auto_close_stale_leads( false );
		}
	}

	public static function ajax_auto_close(): void {
		check_ajax_referer( ZSV_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$m = self::build_manager();
		if ( ! $m ) {
			wp_send_json_error( 'CRM not configured — cannot sweep.' );
		}
		$dry = ! empty( $_POST['dry_run'] );
		wp_send_json_success( $m->auto_close_stale_leads( (bool) $dry ) );
	}
}
