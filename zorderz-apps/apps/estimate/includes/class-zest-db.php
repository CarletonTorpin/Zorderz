<?php
/**
 * ZEST_DB — schema for the Estimates module.
 *
 * Two tables: the estimate mirror (a local index of what the app has drafted/sent, the
 * source of truth for ownership + history) and the background parse-job queue. SCHEMA
 * ONLY on activation — no estimate, customer, price or catalog row is ever seeded
 * (§C.3). Legacy tables (tsec_estimates / tsec_parse_jobs) are renamed in place by the
 * theme's ZDZ_Rename_Migration from the map app.php declares, so a private-lineage
 * install upgrades cleanly; a fresh Zorderz install simply creates these empty.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_DB {

	const DB_VERSION = '1.22.0';

	public static function estimates_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zest_estimates';
	}

	public static function jobs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zest_parse_jobs';
	}

	public static function invoices_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zest_invoices';
	}

	public static function payments_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zest_payments';
	}

	/** Create/upgrade the schema (idempotent via dbDelta). Never seeds data. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$estimates = self::estimates_table();
		dbDelta( "CREATE TABLE {$estimates} (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_name   VARCHAR(200) NOT NULL DEFAULT '',
			customer_email  VARCHAR(200) NOT NULL DEFAULT '',
			salesperson     VARCHAR(50)  NOT NULL DEFAULT '',
			item_count      INT          NOT NULL DEFAULT 0,
			rejected_count  INT          NOT NULL DEFAULT 0,
			billing_doc_id  VARCHAR(50)  NOT NULL DEFAULT '',
			billing_doc_num VARCHAR(50)  NOT NULL DEFAULT '',
			crm_lead_id     VARCHAR(50)  NOT NULL DEFAULT '',
			crm_contact_id  VARCHAR(50)  NOT NULL DEFAULT '',
			items_json      LONGTEXT     NOT NULL,
			rejected_json   LONGTEXT     NOT NULL,
			notes           TEXT         NOT NULL,
			image_url       VARCHAR(500) NOT NULL DEFAULT '',
			input_text      LONGTEXT     NOT NULL,
			status          VARCHAR(20)  NOT NULL DEFAULT 'created',
			billing_status  TINYINT      NOT NULL DEFAULT 1,
			crm_sync_status TINYINT      NOT NULL DEFAULT 0,
			created_by      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			billing_cust_id VARCHAR(50)  NOT NULL DEFAULT '',
			customer_phone  VARCHAR(50)  NOT NULL DEFAULT '',
			customer_street VARCHAR(200) NOT NULL DEFAULT '',
			customer_city   VARCHAR(100) NOT NULL DEFAULT '',
			customer_state  VARCHAR(50)  NOT NULL DEFAULT '',
			customer_zip    VARCHAR(20)  NOT NULL DEFAULT '',
			reference       VARCHAR(100) NOT NULL DEFAULT '',
			customer_org    VARCHAR(200) NOT NULL DEFAULT '',
			doc_number      VARCHAR(50)  NOT NULL DEFAULT '',
			doc_date        DATE         NULL DEFAULT NULL,
			discount_type   VARCHAR(10)  NOT NULL DEFAULT 'none',
			discount_value  DECIMAL(12,2) NOT NULL DEFAULT 0,
			tax_amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
			shipping_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			terms           TEXT         NULL,
			converted_invoice_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			sent_at         DATETIME     NULL DEFAULT NULL,
			accepted_at     DATETIME     NULL DEFAULT NULL,
			accepted_by     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			accepted_source VARCHAR(20)  NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY idx_created (created_at),
			KEY idx_status  (status),
			KEY idx_billing_status (billing_status),
			KEY idx_created_by (created_by)
		) {$charset};" );

		$jobs = self::jobs_table();
		dbDelta( "CREATE TABLE {$jobs} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id      BIGINT UNSIGNED NOT NULL,
			status       VARCHAR(20)  NOT NULL DEFAULT 'queued',
			input_text   LONGTEXT,
			image_urls   LONGTEXT,
			context_json LONGTEXT,
			result_json  LONGTEXT,
			error_msg    TEXT,
			created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_user (user_id),
			KEY idx_status (status),
			KEY idx_created (created_at)
		) {$charset};" );

		// ── Phase 2: the no-API invoice document (mirrors the estimate) ──
		$invoices = self::invoices_table();
		dbDelta( "CREATE TABLE {$invoices} (
			id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_number     VARCHAR(50)  NOT NULL DEFAULT '',
			source_estimate_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			customer_name      VARCHAR(200) NOT NULL DEFAULT '',
			customer_org       VARCHAR(200) NOT NULL DEFAULT '',
			customer_email     VARCHAR(200) NOT NULL DEFAULT '',
			customer_phone     VARCHAR(50)  NOT NULL DEFAULT '',
			customer_street    VARCHAR(200) NOT NULL DEFAULT '',
			customer_city      VARCHAR(100) NOT NULL DEFAULT '',
			customer_state     VARCHAR(50)  NOT NULL DEFAULT '',
			customer_zip       VARCHAR(20)  NOT NULL DEFAULT '',
			salesperson        VARCHAR(50)  NOT NULL DEFAULT '',
			reference          VARCHAR(100) NOT NULL DEFAULT '',
			items_json         LONGTEXT     NOT NULL,
			discount_type      VARCHAR(10)  NOT NULL DEFAULT 'none',
			discount_value     DECIMAL(12,2) NOT NULL DEFAULT 0,
			tax_amount         DECIMAL(12,2) NOT NULL DEFAULT 0,
			shipping_amount    DECIMAL(12,2) NOT NULL DEFAULT 0,
			notes              TEXT         NULL,
			terms              TEXT         NULL,
			total_amount       DECIMAL(14,2) NOT NULL DEFAULT 0,
			amount_paid        DECIMAL(14,2) NOT NULL DEFAULT 0,
			status             VARCHAR(20)  NOT NULL DEFAULT 'draft',
			doc_date           DATE         NULL DEFAULT NULL,
			due_date           DATE         NULL DEFAULT NULL,
			created_by         BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at         DATETIME     NULL DEFAULT NULL,
			sent_at            DATETIME     NULL DEFAULT NULL,
			paid_at            DATETIME     NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_number (invoice_number),
			KEY idx_status (status),
			KEY idx_source (source_estimate_id),
			KEY idx_created (created_at)
		) {$charset};" );

		$payments = self::payments_table();
		dbDelta( "CREATE TABLE {$payments} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			method      VARCHAR(20)  NOT NULL DEFAULT 'other',
			amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
			note        VARCHAR(255) NOT NULL DEFAULT '',
			received_at DATE         NULL DEFAULT NULL,
			created_by  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_invoice (invoice_id)
		) {$charset};" );

		// Failsafe: only stamp the version once the table really exists.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $estimates ) ) !== $estimates ) {
			error_log( 'Zorderz Estimates: schema install did not create the estimates table — not stamping version.' );
			return;
		}
		update_option( 'zest_db_version', self::DB_VERSION );
	}

	/** Self-heal on a file overwrite that skipped activation. */
	public static function maybe_upgrade(): void {
		if ( version_compare( (string) get_option( 'zest_db_version', '0' ), self::DB_VERSION, '>=' ) ) {
			return;
		}
		self::install();
	}

	/**
	 * Dates this user created an estimate in the past year — for the theme streak
	 * calculator. Returns the input unchanged when the table is absent.
	 */
	public static function active_dates( array $dates, int $user_id ): array {
		global $wpdb;
		$table = self::estimates_table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return $dates;
		}
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT DATE(created_at) FROM {$table} WHERE created_by = %d AND created_at > DATE_SUB(NOW(), INTERVAL 365 DAY)",
			$user_id
		) );
		return array_merge( $dates, $rows ?: array() );
	}
}
