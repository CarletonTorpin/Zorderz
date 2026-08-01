<?php
/**
 * Zorderz Jobs — database (the wp_zdz_jobs table).
 *
 * One row per job / handed-off job component. The app is the source of truth for
 * WHO does the work; the CRM (when configured) mirrors it as a child lead
 * (crm_child_lead_id).
 *
 * Legacy note: this table was `wp_ts_handoffs` in the legacy build. The
 * platform migration (ZDZ_Rename_Migration, driven by the `zdz_rename_map`
 * filter declared in app.php) renames it to `wp_zdz_jobs` in place, so an
 * existing install upgrades cleanly. A fresh Zorderz install just creates it.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_DB {

	const DB_VERSION_OPTION = 'zjob_db_version';
	// 1.7.0: + assurance_level / attestation columns (solo-operator single-party
	//         recorded attestation, safety floor). 1.6.0: worker inbox
	//         (started_at + ETA). 1.5.0: close-out deadline/extension.
	//         1.4.0: two-party completion. 1.3.0: estimate pointers.
	const DB_VERSION = '1.7.0';

	/** Fully-qualified table name. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zdz_jobs';
	}

	/**
	 * Create/upgrade the table (dbDelta — safe to run repeatedly).
	 */
	public static function install(): void {
		global $wpdb;
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		// Columns:
		//   component        - the job kind (an Item Engine kind key; ships neutral,
		//                      never a hardcoded product name — see zjob_default_component()).
		//   customer_name    - denormalized display label (from the estimate/lead).
		//   source_ref       - free text tying it to the origin (e.g. "estimate #1234").
		//   parent_lead_id   - the customer's CRM lead the component came from (0 if none).
		//   crm_child_lead_id - the SEPARATE CRM lead created for the specialist.
		//   crm_contact_id    - the CRM person/company the child lead is on.
		//   assigned_user_id - the specialist (resolved via ZDZ_Party).
		//   created_by       - the person who created / handed off the job.
		//   brand, qty, notes - job detail (internal only; never billed).
		//   customer_business/customer_address/customer_phone/access_notes - worker-card fields.
		//   scheduled_appt_id - the linked scheduler appointment row (0 = unscheduled).
		//   scheduled_start_utc / scheduled_end_utc / scheduled_tz - cached appt time.
		//   scheduled_by / scheduled_at - who set the time and when (provenance).
		//   estimate_id / estimate_line_index / estimate_line_sig - pointer back to the
		//     source estimate + line (traceability, dedup, orphan detection).
		//   worker_done_at / finish_media_ids / finish_gps_lat|lng|accuracy / finish_verified -
		//     the worker marks THEIR part complete (mandatory geo-tagged finish photos;
		//     finish_verified = the location gate was met).
		//   assurance_level  - HOW the job reached `done`: '' | two_party |
		//     single_party_attested | system_auto. Never laundered: a solo
		//     self-attested close is recorded distinctly so a downstream consumer
		//     (warranty, insurance, commission release) can require two_party.
		//   attestation_reason / attested_by / attested_at - the recorded single-party
		//     attestation a solo operator gives in place of a second signer (safety floor).
		//   closed_at / closed_by - the originator's official close-out.
		//   close_deadline / close_extended_* / close_extend_reason - a job may sit in
		//     pending_close at most the configured window (default 60 days) before it
		//     auto-closes; the deadline is extendable with a written reason.
		//   started_at - when the worker started (auto-captured on the first "On my way").
		//   eta_status / eta_at - the worker's last ETA signal and when it was tapped.
		//   status           - open | in_progress | pending_close | done | cancelled.
		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			component VARCHAR(64) NOT NULL DEFAULT 'other',
			customer_name VARCHAR(191) NOT NULL DEFAULT '',
			source_ref VARCHAR(191) NOT NULL DEFAULT '',
			parent_lead_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			crm_child_lead_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			crm_contact_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			assigned_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			brand VARCHAR(96) NOT NULL DEFAULT '',
			qty SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			notes TEXT NULL,
			customer_business VARCHAR(191) NOT NULL DEFAULT '',
			customer_address VARCHAR(255) NOT NULL DEFAULT '',
			customer_phone VARCHAR(48) NOT NULL DEFAULT '',
			access_notes TEXT NULL,
			scheduled_appt_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			scheduled_start_utc DATETIME NULL DEFAULT NULL,
			scheduled_end_utc DATETIME NULL DEFAULT NULL,
			scheduled_tz VARCHAR(64) NOT NULL DEFAULT '',
			scheduled_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			scheduled_at DATETIME NULL DEFAULT NULL,
			estimate_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			estimate_line_index INT NOT NULL DEFAULT -1,
			estimate_line_sig VARCHAR(191) NOT NULL DEFAULT '',
			worker_done_at DATETIME NULL DEFAULT NULL,
			finish_media_ids TEXT NULL,
			finish_gps_lat DECIMAL(10,7) NULL DEFAULT NULL,
			finish_gps_lng DECIMAL(10,7) NULL DEFAULT NULL,
			finish_gps_accuracy INT NULL DEFAULT NULL,
			finish_verified TINYINT(1) NOT NULL DEFAULT 0,
			assurance_level VARCHAR(32) NOT NULL DEFAULT '',
			attestation_reason TEXT NULL,
			attested_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			attested_at DATETIME NULL DEFAULT NULL,
			closed_at DATETIME NULL DEFAULT NULL,
			closed_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			close_deadline DATETIME NULL DEFAULT NULL,
			close_extended_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			close_extended_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			close_extended_at DATETIME NULL DEFAULT NULL,
			close_extend_reason TEXT NULL,
			started_at DATETIME NULL DEFAULT NULL,
			eta_status VARCHAR(20) NOT NULL DEFAULT '',
			eta_at DATETIME NULL DEFAULT NULL,
			status VARCHAR(24) NOT NULL DEFAULT 'open',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY assigned_user_id (assigned_user_id),
			KEY created_by (created_by),
			KEY status (status),
			KEY parent_lead_id (parent_lead_id),
			KEY scheduled_start (scheduled_start_utc),
			KEY scheduled_appt (scheduled_appt_id),
			KEY estimate_id (estimate_id),
			KEY close_deadline (close_deadline)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Run install() if the stored DB version is behind, OR the table is physically
	 * missing (covers zip-replace upgrades that skip the activation hook, and a
	 * folder-copy first install). dbDelta is idempotent, so re-running is safe.
	 */
	public static function maybe_upgrade(): void {
		global $wpdb;
		$table   = self::table();
		$present = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		if ( ! $present || get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}
}
