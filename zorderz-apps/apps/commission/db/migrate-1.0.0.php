<?php
/**
 * ZCC Migration 1.0.0 — SCHEMA ONLY.
 *
 * Creates the three plugin-owned tables:
 *   wp_zcc_commission_ledger  — the record of record (draft → finalized)
 *   wp_zcc_audit_log          — the calculation audit trail (the migration gate)
 *   wp_zcc_rep_overrides      — historical rep back-assignment
 *
 * DATA DISCIPLINE (Playbook §5): NOTHING is seeded. The old plugin seeded ~49
 * real supplier COGS rows and a classification cache on activation; both are
 * gone — the catalog now lives in the Item Engine (ships empty) and the
 * classification cache is a request-level, version-keyed memo that needs no
 * table. Schema migration ≠ data seeding.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'zcc_migrate_1_0_0' ) ) {

	function zcc_migrate_1_0_0(): array {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// ── Ledger ──
		$ledger = $wpdb->prefix . 'zcc_commission_ledger';
		dbDelta( "CREATE TABLE {$ledger} (
			id                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id            BIGINT(20) UNSIGNED NOT NULL,
			period_key         VARCHAR(7) NOT NULL DEFAULT '',
			invoice_id         BIGINT(20) UNSIGNED NOT NULL,
			invoice_number     VARCHAR(64) NOT NULL DEFAULT '',
			customer_name      VARCHAR(200) NOT NULL DEFAULT '',
			date_completed     VARCHAR(32) NOT NULL DEFAULT '',
			gross_billed       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			total_cogs         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			net_commissionable DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			net_attributed     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			commission_amount  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			status             VARCHAR(20) NOT NULL DEFAULT 'draft',
			detail_json        LONGTEXT NULL,
			finalized_at       DATETIME NULL DEFAULT NULL,
			finalized_by       BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_user_invoice (user_id, invoice_id),
			KEY idx_period (user_id, period_key),
			KEY idx_status (status)
		) {$charset};" );

		// ── Audit log (the migration gate) ──
		$audit = $wpdb->prefix . 'zcc_audit_log';
		dbDelta( "CREATE TABLE {$audit} (
			id                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id            BIGINT(20) UNSIGNED NOT NULL,
			target_user_id     BIGINT(20) UNSIGNED NOT NULL,
			date_range_start   VARCHAR(32) NOT NULL DEFAULT '',
			date_range_end     VARCHAR(32) NOT NULL DEFAULT '',
			invoice_count      INT NOT NULL DEFAULT 0,
			gross_billed       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			total_cogs         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			total_cc_fees      DECIMAL(12,2) DEFAULT 0.00,
			total_discounts    DECIMAL(12,2) DEFAULT 0.00,
			net_commissionable DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			commission_rate    VARCHAR(100) NOT NULL DEFAULT '',
			total_commission   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			detail_json        LONGTEXT NOT NULL,
			flags_json         TEXT DEFAULT NULL,
			computed_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_target_user (target_user_id),
			KEY idx_computed (computed_at)
		) {$charset};" );

		// ── Rep overrides ──
		$overrides = $wpdb->prefix . 'zcc_rep_overrides';
		dbDelta( "CREATE TABLE {$overrides} (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id  BIGINT(20) UNSIGNED NOT NULL,
			rep_code    VARCHAR(8) NOT NULL DEFAULT '',
			note        VARCHAR(255) NOT NULL DEFAULT '',
			assigned_by BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_invoice (invoice_id)
		) {$charset};" );

		return [ 'tables' => [ $ledger, $audit, $overrides ], 'seeded' => 0 ];
	}
}
