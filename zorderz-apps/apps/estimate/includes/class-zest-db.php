<?php
/**
 * ZEST_DB — schema for the Estimates module.
 *
 * Two tables: the estimate mirror (a local index of what the app has drafted/sent, the
 * source of truth for ownership + history) and the background parse-job queue. SCHEMA
 * ONLY on activation — no estimate, customer, price or catalog row is ever seeded.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_DB {

	const DB_VERSION = '1.27.0';

	/** doc_type discriminator values — this row models an estimate or an invoice document. */
	const DOC_TYPE_ESTIMATE = 'estimate';
	const DOC_TYPE_INVOICE  = 'invoice';

	/**
	 * The estimate document lifecycle, expressed as a documented Flow-shaped state set so the
	 * later migration onto the Zorderz Flow substrate is a mechanical rename, not a redesign.
	 *
	 * DOCUMENTATION ONLY THIS RELEASE — nothing reads or writes against these keys yet; the
	 * live `status` column keeps its current handling (the create/convert paths are unchanged).
	 * The three later stages are deliberately DISTINCT: `accepted` (the customer said yes) is
	 * not `invoiced` (a billing document exists — resolved by ZEST_Billing) is not `paid`
	 * (money has been collected). Current column values map forward as 'created'→draft,
	 * 'sent'→sent, 'accepted'→accepted, 'converted'→invoiced; `paid` is a fact about the linked
	 * invoice, never a value the estimate row sets on itself.
	 *
	 * @var array<string,string>
	 */
	const ESTIMATE_LIFECYCLE = array(
		'draft'    => 'Drafted, not yet sent to the customer.',
		'sent'     => 'Delivered to the customer, awaiting a decision.',
		'accepted' => 'Customer accepted — distinct from being billed.',
		'invoiced' => 'A billing document exists for it (converted_invoice_id resolves live).',
		'paid'     => 'The linked invoice has been paid in full.',
	);

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

	public static function line_changes_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zest_line_changes';
	}

	public static function reassign_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zest_reassign';
	}

	/**
	 * Resolve a raw document reference to an explicit ( doc_type, id ) pair — NEVER a bare
	 * number. estimate #5982 and invoice #5982 are different documents; a lone integer is
	 * ambiguous and is refused. Accepts an array ( { doc_type|type, id } ) or a "type:id"
	 * string; returns { doc_type: 'estimate'|'invoice', id: int } on success, or
	 * { doc_type: '', id: 0 } when the type is unknown / the id is missing / a bare number
	 * was passed. This is the keying discipline the Projects ref map joins on.
	 *
	 * @param mixed $raw
	 * @return array{doc_type:string,id:int}
	 */
	public static function extract_doc_ident( $raw ): array {
		$fail = array( 'doc_type' => '', 'id' => 0 );
		$type = '';
		$id   = 0;
		if ( is_array( $raw ) ) {
			$type = strtolower( trim( (string) ( $raw['doc_type'] ?? ( $raw['type'] ?? '' ) ) ) );
			$id   = (int) ( $raw['id'] ?? 0 );
		} elseif ( is_string( $raw ) && false !== strpos( $raw, ':' ) ) {
			list( $t, $i ) = array_pad( explode( ':', $raw, 2 ), 2, '' );
			$type = strtolower( trim( $t ) );
			$i    = trim( $i );
			$id   = ctype_digit( $i ) ? (int) $i : 0;
		} else {
			// A bare number (or anything with no explicit doc_type) is refused on purpose.
			return $fail;
		}
		if ( ! in_array( $type, array( self::DOC_TYPE_ESTIMATE, self::DOC_TYPE_INVOICE ), true ) || $id <= 0 ) {
			return $fail;
		}
		return array( 'doc_type' => $type, 'id' => $id );
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
			doc_type        VARCHAR(20)  NOT NULL DEFAULT 'estimate',
			invoice_checked_at DATETIME  NULL DEFAULT NULL,
			updated_at      DATETIME     NULL DEFAULT NULL,
			sent_at         DATETIME     NULL DEFAULT NULL,
			accepted_at     DATETIME     NULL DEFAULT NULL,
			accepted_by     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			accepted_source VARCHAR(20)  NOT NULL DEFAULT '',
			approved_at     DATETIME     NULL DEFAULT NULL,
			approved_by     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			approved_fp     VARCHAR(64)  NOT NULL DEFAULT '',
			seal_hold       VARCHAR(40)  NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY idx_created (created_at),
			KEY idx_status  (status),
			KEY idx_billing_status (billing_status),
			KEY idx_created_by (created_by),
			KEY idx_seal_hold (seal_hold),
			KEY idx_billing_doc (billing_doc_id)
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

		// ── The no-API invoice document (mirrors the estimate) ──
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

		// Every change made to a billing document from the billing view or a re-assign: who, when, which line, before and
		// after. Written BEFORE the billing system is (state 'pending'), then marked saved or failed.
		$changes = self::line_changes_table();
		dbDelta( "CREATE TABLE {$changes} (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch         VARCHAR(64)  NOT NULL DEFAULT '',
			created_at    DATETIME     NOT NULL,
			user_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
			initials      VARCHAR(8)   NOT NULL DEFAULT '',
			doc_kind      VARCHAR(10)  NOT NULL DEFAULT 'estimate',
			billing_id    VARCHAR(50)  NOT NULL DEFAULT '',
			doc_number    VARCHAR(40)  NOT NULL DEFAULT '',
			line_no       INT          NOT NULL DEFAULT 0,
			change_type   VARCHAR(10)  NOT NULL DEFAULT '',
			field         VARCHAR(20)  NOT NULL DEFAULT '',
			slot          VARCHAR(40)  NOT NULL DEFAULT '',
			option_id     VARCHAR(40)  NOT NULL DEFAULT '',
			before_text   TEXT         NULL,
			after_text    TEXT         NULL,
			before_amount DECIMAL(12,2) NULL DEFAULT NULL,
			after_amount  DECIMAL(12,2) NULL DEFAULT NULL,
			reason        VARCHAR(300) NOT NULL DEFAULT '',
			verified      TINYINT      NOT NULL DEFAULT 0,
			state         VARCHAR(10)  NOT NULL DEFAULT 'pending',
			PRIMARY KEY  (id),
			KEY idx_doc (doc_kind, billing_id),
			KEY idx_batch (batch)
		) {$charset};" );

		// Re-assign batches: what was looked at, proposed, written and undone, with what each estimate was before.
		$reassign = self::reassign_table();
		dbDelta( "CREATE TABLE {$reassign} (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at    DATETIME     NOT NULL,
			created_by    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			state         VARCHAR(20)  NOT NULL DEFAULT 'scanning',
			generation    BIGINT       NOT NULL DEFAULT 0,
			params        LONGTEXT     NULL,
			items         LONGTEXT     NULL,
			notices       LONGTEXT     NULL,
			applied_at    DATETIME     NULL DEFAULT NULL,
			applied_count INT          NOT NULL DEFAULT 0,
			undone_at     DATETIME     NULL DEFAULT NULL,
			undone_by     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY idx_state (state)
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
