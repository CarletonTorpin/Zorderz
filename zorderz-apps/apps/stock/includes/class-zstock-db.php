<?php
/**
 * Zorderz Stock — database schema.
 *
 * Five tables, all shipped EMPTY (schema only, never seeded):
 *   wp_zstock_ledger          immutable inventory event log (source of truth for current stock).
 *   wp_zstock_stock           per-item live state: denormalized current_stock + optional
 *                             par/reorder overrides. Keyed by the Item Engine item id (VARCHAR).
 *   wp_zstock_supplier_orders uploaded/parsed supplier invoices (draft → approved/rejected).
 *   wp_zstock_order_items     parsed line items from a supplier order.
 *   wp_zstock_sync_log        de-dupe log for the consumption sweep (the source's missing table).
 *
 * The catalog itself (items, SKUs, unit nouns, par/reorder defaults, and each item's BOM
 * `consumes[]`) lives in ZDZ_Item_Engine — this module stores no product taxonomy.
 *
 * ── Guarded migration ──────────────────────────────────────────────────────
 * In the legacy build the ledger/order-item item id was a BIGINT pointing at a LOCAL catalog row.
 * The catalog is now the Item Engine, whose ids are VARCHAR. When the platform migration renames
 * a legacy table into place, maybe_upgrade() detects an integer id column and widens it to
 * VARCHAR(80) (recorded as a schema migration). A fresh Zorderz install just creates the columns
 * as VARCHAR and this no-ops.
 *
 * @package Zorderz\Stock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSTOCK_DB {

	const DB_VERSION_OPTION = 'zstock_db_version';
	const DB_VERSION        = '1.0.0';

	public static function ledger_table() {
		global $wpdb;
		return $wpdb->prefix . 'zstock_ledger';
	}
	public static function stock_table() {
		global $wpdb;
		return $wpdb->prefix . 'zstock_stock';
	}
	public static function orders_table() {
		global $wpdb;
		return $wpdb->prefix . 'zstock_supplier_orders';
	}
	public static function order_items_table() {
		global $wpdb;
		return $wpdb->prefix . 'zstock_order_items';
	}
	public static function sync_log_table() {
		global $wpdb;
		return $wpdb->prefix . 'zstock_sync_log';
	}

	/** Create/upgrade all tables (dbDelta — safe to run repeatedly). Schema only. */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$ledger = self::ledger_table();
		dbDelta(
			"CREATE TABLE {$ledger} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				item_id VARCHAR(80) NOT NULL DEFAULT '',
				transaction_type VARCHAR(50) NOT NULL DEFAULT '',
				quantity_change DECIMAL(12,4) NOT NULL DEFAULT 0,
				reference_type VARCHAR(50) NOT NULL DEFAULT '',
				reference_id VARCHAR(191) NOT NULL DEFAULT '',
				reference_label VARCHAR(255) NOT NULL DEFAULT '',
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				notes TEXT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY idx_item_id (item_id),
				KEY idx_transaction_type (transaction_type),
				KEY idx_reference (reference_type, reference_id(100)),
				KEY idx_created_at (created_at)
			) {$charset};"
		);

		$stock = self::stock_table();
		dbDelta(
			"CREATE TABLE {$stock} (
				item_id VARCHAR(80) NOT NULL,
				current_stock DECIMAL(12,4) NOT NULL DEFAULT 0,
				par_level DECIMAL(12,4) NULL DEFAULT NULL,
				reorder_point DECIMAL(12,4) NULL DEFAULT NULL,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (item_id)
			) {$charset};"
		);

		$orders = self::orders_table();
		dbDelta(
			"CREATE TABLE {$orders} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				supplier_name VARCHAR(255) NOT NULL DEFAULT '',
				invoice_number VARCHAR(100) NOT NULL DEFAULT '',
				invoice_date DATE DEFAULT NULL,
				subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
				tax DECIMAL(12,2) NOT NULL DEFAULT 0,
				total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
				file_url TEXT NULL,
				ai_raw_json LONGTEXT NULL,
				status VARCHAR(50) NOT NULL DEFAULT 'draft',
				reject_reason VARCHAR(255) NOT NULL DEFAULT '',
				created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				approved_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				approved_at DATETIME DEFAULT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY idx_status (status),
				KEY idx_supplier (supplier_name(100)),
				KEY idx_created_at (created_at)
			) {$charset};"
		);

		$order_items = self::order_items_table();
		dbDelta(
			"CREATE TABLE {$order_items} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				item_id VARCHAR(80) NOT NULL DEFAULT '',
				sku VARCHAR(80) NOT NULL DEFAULT '',
				supplier_description VARCHAR(500) NULL,
				quantity DECIMAL(12,4) NOT NULL DEFAULT 0,
				unit VARCHAR(50) NOT NULL DEFAULT 'each',
				unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
				total DECIMAL(12,2) NOT NULL DEFAULT 0,
				confidence VARCHAR(20) NOT NULL DEFAULT 'LOW',
				matched TINYINT(1) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY idx_order_id (order_id),
				KEY idx_item_id (item_id)
			) {$charset};"
		);

		$sync = self::sync_log_table();
		dbDelta(
			"CREATE TABLE {$sync} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				source_invoice_id VARCHAR(191) NOT NULL DEFAULT '',
				invoice_date VARCHAR(40) NOT NULL DEFAULT '',
				items_count INT NOT NULL DEFAULT 0,
				synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY uk_source_invoice (source_invoice_id)
			) {$charset};"
		);

		self::migrate_item_id_columns();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Run install() if the stored version is behind OR a table is physically missing (covers
	 * zip-replace upgrades that skip activation, and a folder-copy first install). dbDelta is
	 * idempotent, so re-running is safe.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION || ! self::table_exists( self::ledger_table() ) ) {
			self::install();
		} else {
			// Even when the version matches, honour a table that arrived via the rename migration
			// with a legacy integer id column.
			self::migrate_item_id_columns();
		}
	}

	/** True if a table physically exists. */
	private static function table_exists( $table ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * GUARDED MIGRATION: widen a legacy integer `item_id` to VARCHAR(80).
	 *
	 * The catalog moved from a local BIGINT-keyed table to the Item Engine's VARCHAR ids. A table
	 * renamed into place by ZDZ_Rename_Migration may still carry an integer id column; widen it so
	 * legacy numeric ids survive as strings (they resolve once an Item Engine id remap is applied;
	 * an unresolved id simply reads as unmatched — a logged disposition, never a crash).
	 */
	private static function migrate_item_id_columns() {
		global $wpdb;
		$targets = array(
			array( self::ledger_table(), 'item_id' ),
			array( self::order_items_table(), 'item_id' ),
		);
		foreach ( $targets as $t ) {
			list( $table, $col ) = $t;
			if ( ! self::table_exists( $table ) ) {
				continue;
			}
			$type = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
					$table,
					$col
				)
			);
			if ( $type && false !== stripos( (string) $type, 'int' ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers only, from constants.
				$wpdb->query( "ALTER TABLE `{$table}` MODIFY `{$col}` VARCHAR(80) NOT NULL DEFAULT ''" );
				zstock_log( "migration: widened {$table}.{$col} {$type}->varchar(80)" );
			}
		}
	}
}
