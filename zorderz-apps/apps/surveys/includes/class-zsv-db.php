<?php
/**
 * Zorderz Surveys — schema install + migrations + the disposition helper.
 *
 * Ships EMPTY: install() creates tables only, never seeds a row. The one migration
 * that matters is the SURVEY-OPERATOR column rename — the generalized replacement for
 * the baked-in operator's first name that used to name two DB columns. It is a real
 * ALTER guarded by a version option, with a data copy, and is recorded in
 * schema_migrations.
 *
 * @package Zorderz\Surveys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSV_DB {

	/** Schema version. Bump to trigger maybe_upgrade(). */
	const DB_VERSION = '2.12.0';

	/** Table basenames (without $wpdb->prefix). Zorderz-neutral, no legacy names. */
	const BATCHES_TABLE  = 'zdz_survey_batches';
	const LEADS_TABLE    = 'zdz_survey_leads';
	const MEMORY_TABLE   = 'zdz_survey_invoice_memory';

	/** @return string Fully-prefixed table name. */
	public static function leads_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::LEADS_TABLE;
	}

	/** @return string Fully-prefixed table name. */
	public static function batches_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::BATCHES_TABLE;
	}

	/** @return string Fully-prefixed table name. */
	public static function memory_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::MEMORY_TABLE;
	}

	/**
	 * Create the tables (idempotent via dbDelta). No data is ever seeded.
	 *
	 * The leads table ships with SCHEMA-NEUTRAL operator columns —
	 * `operator_notes` / `operator_status` — instead of a column named after one
	 * person. A survey operator is now a configurable Party user (see ZSV_Settings).
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset  = $wpdb->get_charset_collate();
		$batches  = self::batches_table();
		$leads    = self::leads_table();
		$memory   = self::memory_table();

		dbDelta(
			"CREATE TABLE {$batches} (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				batch_tag varchar(255) NOT NULL,
				created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
				total_invoices int(11) DEFAULT 0 NOT NULL,
				zero_amount int(11) DEFAULT 0 NOT NULL,
				no_email int(11) DEFAULT 0 NOT NULL,
				duplicate_emails int(11) DEFAULT 0 NOT NULL,
				excluded_companies int(11) DEFAULT 0 NOT NULL,
				already_surveyed int(11) DEFAULT 0 NOT NULL,
				do_not_survey int(11) DEFAULT 0 NOT NULL,
				previously_seen int(11) DEFAULT 0 NOT NULL,
				ai_flagged int(11) DEFAULT 0 NOT NULL,
				eligible int(11) DEFAULT 0 NOT NULL,
				leads_created int(11) DEFAULT 0 NOT NULL,
				errors int(11) DEFAULT 0 NOT NULL,
				ai_review_notes text,
				ai_summary text,
				status varchar(50) DEFAULT 'completed' NOT NULL,
				PRIMARY KEY  (id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$leads} (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				batch_id bigint(20) NOT NULL,
				first_name varchar(255) NOT NULL DEFAULT '',
				last_name varchar(255) NOT NULL DEFAULT '',
				email varchar(255) NOT NULL DEFAULT '',
				city varchar(255) DEFAULT '',
				salesperson_name varchar(255) DEFAULT '',
				salesperson_code varchar(16) DEFAULT '',
				total_amount decimal(10,2) DEFAULT 0,
				pipeline varchar(255) DEFAULT '',
				lead_name varchar(500) DEFAULT '',
				work_description text,
				status varchar(255) DEFAULT 'pending' NOT NULL,
				email_type varchar(50) DEFAULT '',
				crm_lead_id varchar(100) DEFAULT NULL,
				crm_contact_id varchar(100) DEFAULT NULL,
				crm_status varchar(100) DEFAULT NULL,
				crm_synced_at datetime DEFAULT NULL,
				email_sent_at datetime DEFAULT NULL,
				email_send_log text DEFAULT NULL,
				operator_notes text DEFAULT NULL,
				operator_status varchar(100) DEFAULT NULL,
				review_left tinyint(1) DEFAULT 0,
				review_source varchar(40) DEFAULT NULL,
				review_date datetime DEFAULT NULL,
				review_snippet text DEFAULT NULL,
				review_checked_at datetime DEFAULT NULL,
				created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
				PRIMARY KEY  (id),
				KEY batch_id (batch_id),
				KEY crm_lead_id (crm_lead_id),
				KEY operator_status (operator_status)
			) {$charset};"
		);

		// Invoice memory: FreshBooks-neutral. Stores ONLY billing invoice IDs +
		// disposition codes. NO customer PII.
		dbDelta(
			"CREATE TABLE {$memory} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				invoice_id bigint(20) unsigned NOT NULL,
				disposition varchar(50) NOT NULL,
				batch_tag varchar(255) DEFAULT '' NOT NULL,
				created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY invoice_id (invoice_id),
				KEY disposition (disposition),
				KEY created_at (created_at)
			) {$charset};"
		);

		self::migrate_operator_columns();
		update_option( 'zsv_db_version', self::DB_VERSION, false );
	}

	/**
	 * Self-heal: run install()/migrations when the stored version is behind.
	 * Called on plugins_loaded so a folder-overwrite upgrade (which never fires
	 * activation) still lands the schema.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'zsv_db_version' ) === self::DB_VERSION ) {
			// Even on a matching version, the operator migration is cheap + idempotent
			// and guards the upgrade-from-legacy case where the table was renamed by
			// the platform but columns kept their legacy names.
			self::migrate_operator_columns();
			return;
		}
		self::install();
	}

	/**
	 * SURVEY-OPERATOR SCHEMA MIGRATION (the required real migration).
	 *
	 * A legacy install upgraded to Zorderz has its `ts_survey_leads` table renamed to
	 * `zdz_survey_leads` by the platform ZDZ_Rename_Migration, but the COLUMNS keep
	 * their legacy names — two of them named after the single baked-in survey operator
	 * (`kathie_notes` / `kathie_status`, the deprecated aliases). This renames them to
	 * the schema-neutral `operator_notes` / `operator_status`, preserving data, and
	 * also folds the even-older provider-named `nutshell_*` columns to `crm_*`.
	 *
	 * Guarded by the `zsv_operator_cols_migrated` option so it runs once. On a fresh
	 * Zorderz install install() already created neutral columns, so every branch
	 * no-ops. On an upgrade it ALTERs in place (CHANGE COLUMN preserves data); where
	 * both legacy and neutral columns somehow coexist it COPIES then DROPs the legacy
	 * one, so no data is lost either way.
	 */
	public static function migrate_operator_columns(): void {
		if ( get_option( 'zsv_operator_cols_migrated' ) === 'yes' ) {
			return;
		}
		global $wpdb;
		$leads = self::leads_table();

		// Table not present yet (fresh install mid-boot) — nothing to migrate.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $leads ) ) !== $leads ) {
			return;
		}

		// Map of legacy column => neutral column + its definition.
		$renames = array(
			'kathie_notes'    => array( 'to' => 'operator_notes',  'def' => 'TEXT DEFAULT NULL' ),
			'kathie_status'   => array( 'to' => 'operator_status', 'def' => "VARCHAR(100) DEFAULT NULL" ),
			'nutshell_lead_id'    => array( 'to' => 'crm_lead_id',    'def' => "VARCHAR(100) DEFAULT NULL" ),
			'nutshell_contact_id' => array( 'to' => 'crm_contact_id', 'def' => "VARCHAR(100) DEFAULT NULL" ),
			'nutshell_status'     => array( 'to' => 'crm_status',     'def' => "VARCHAR(100) DEFAULT NULL" ),
			'nutshell_synced_at'  => array( 'to' => 'crm_synced_at',  'def' => 'DATETIME DEFAULT NULL' ),
			// even-older single-column artifact from an early beta.
			'salesperson_initials' => array( 'to' => 'salesperson_code', 'def' => "VARCHAR(16) DEFAULT NULL" ),
		);

		foreach ( $renames as $old => $spec ) {
			$new     = $spec['to'];
			$has_old = ! empty( $wpdb->get_results( "SHOW COLUMNS FROM {$leads} LIKE '{$old}'" ) );
			$has_new = ! empty( $wpdb->get_results( "SHOW COLUMNS FROM {$leads} LIKE '{$new}'" ) );

			if ( $has_old && ! $has_new ) {
				// Clean rename, preserves data in place.
				$wpdb->query( "ALTER TABLE {$leads} CHANGE COLUMN `{$old}` `{$new}` {$spec['def']}" );
				error_log( "Zorderz Surveys: migrated column {$old} -> {$new}." );
			} elseif ( $has_old && $has_new ) {
				// Both exist — copy legacy values into the neutral column where the
				// neutral one is empty, then drop the legacy column.
				$wpdb->query( "UPDATE {$leads} SET `{$new}` = `{$old}` WHERE (`{$new}` IS NULL OR `{$new}` = '') AND `{$old}` IS NOT NULL" );
				$wpdb->query( "ALTER TABLE {$leads} DROP COLUMN `{$old}`" );
				error_log( "Zorderz Surveys: copied {$old} into {$new} and dropped legacy column." );
			}
			// has_new only, or neither: nothing to do.
		}

		update_option( 'zsv_operator_cols_migrated', 'yes', false );
	}

	/**
	 * Fire a disposition. NOTHING is silent: every drop/skip/close/refusal in this
	 * module routes through here so the Core Flow service can consume the same event
	 * (`zdz_flow_disposition`). Also persisted to invoice memory when an invoice id
	 * is present, so "which customers did we decline to survey, and why" is a query.
	 *
	 * @param string $code    Disposition code (e.g. excluded_company, source_unavailable).
	 * @param array  $context Structured evidence (invoice_id, reason, ...).
	 */
	public static function disposition( string $code, array $context = array() ): void {
		$context = array_merge( array( 'code' => $code, 'app' => 'surveys' ), $context );

		/**
		 * Fires for every Surveys disposition. The future Core Flow service subscribes
		 * here to write the disposition ledger.
		 */
		do_action( 'zdz_flow_disposition', $code, $context, get_current_user_id() );

		if ( ! empty( $context['invoice_id'] ) ) {
			self::remember_invoice( (int) $context['invoice_id'], $code, (string) ( $context['batch_tag'] ?? '' ) );
		}
	}

	/**
	 * Record (or update) an invoice's disposition in the memory table so a later run
	 * does not pay the API cost to re-screen it. Retryable codes are re-writable.
	 */
	public static function remember_invoice( int $invoice_id, string $disposition, string $batch_tag = '' ): void {
		if ( $invoice_id < 1 ) {
			return;
		}
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO " . self::memory_table() . " (invoice_id, disposition, batch_tag, created_at, updated_at)
				 VALUES (%d, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE disposition = VALUES(disposition), batch_tag = VALUES(batch_tag), updated_at = VALUES(updated_at)",
				$invoice_id,
				$disposition,
				$batch_tag,
				$now,
				$now
			)
		);
	}
}
