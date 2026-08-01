<?php
/**
 * Schema + read helpers for the invoicing module.
 *
 * The three tables ship EMPTY — install() creates schema only and is never
 * seeded. Business rows arrive solely through an admin creating an invoice or a
 * verified Stripe webhook.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIC_DB {

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t_inv   = $wpdb->prefix . 'zic_invoices';
		$t_pay   = $wpdb->prefix . 'zic_payments';
		$t_wh    = $wpdb->prefix . 'zic_webhook_events';

		dbDelta(
			"CREATE TABLE {$t_inv} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token VARCHAR(64) NOT NULL,
			client_name VARCHAR(191) NOT NULL,
			client_email VARCHAR(191) NOT NULL,
			description TEXT NOT NULL,
			amount_cents BIGINT NOT NULL DEFAULT 0,
			refunded_cents BIGINT NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'usd',
			freshbooks_invoice_id VARCHAR(64) DEFAULT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			payment_intent_id VARCHAR(191) DEFAULT NULL,
			created_at DATETIME NOT NULL,
			paid_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY token (token)
		) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$t_pay} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			payment_intent_id VARCHAR(191) NOT NULL,
			amount_cents BIGINT NOT NULL,
			refunded_cents BIGINT NOT NULL DEFAULT 0,
			application_fee_cents BIGINT NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL,
			status VARCHAR(32) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pi (payment_intent_id)
		) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$t_wh} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			stripe_event_id VARCHAR(191) NOT NULL,
			type VARCHAR(128) NOT NULL,
			payload LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY evt (stripe_event_id)
		) {$charset};"
		);
	}

	/**
	 * Sum of retained platform fees on succeeded payments since $ts, net of any
	 * proportional refunds. Zero on a fresh install and whenever the platform
	 * fee is off (no application fee is ever recorded).
	 */
	public static function platform_fee_total_since( $ts ) {
		global $wpdb;
		$t_pay = $wpdb->prefix . 'zic_payments';
		$dt    = gmdate( 'Y-m-d H:i:s', (int) $ts );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT amount_cents, refunded_cents, application_fee_cents FROM {$t_pay} WHERE created_at >= %s AND status = 'succeeded'",
				$dt
			),
			ARRAY_A
		);
		$total = 0;
		foreach ( (array) $rows as $r ) {
			$amt     = max( 1, (int) $r['amount_cents'] );
			$net_amt = max( 0, $amt - (int) $r['refunded_cents'] );
			$fee     = (int) round( ( (int) $r['application_fee_cents'] ) * ( $net_amt / $amt ) );
			$total  += $fee;
		}
		return $total;
	}

	public static function get_payment_for_invoice( $invoice_id ) {
		global $wpdb;
		$t_pay = $wpdb->prefix . 'zic_payments';
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$t_pay} WHERE invoice_id = %d ORDER BY id DESC LIMIT 1", (int) $invoice_id ),
			ARRAY_A
		);
	}

	public static function all_invoices_for_export() {
		global $wpdb;
		$t_inv = $wpdb->prefix . 'zic_invoices';
		return $wpdb->get_results( "SELECT * FROM {$t_inv} ORDER BY id DESC", ARRAY_A );
	}

	public static function recent_webhook_events( $limit = 100 ) {
		global $wpdb;
		$t_wh = $wpdb->prefix . 'zic_webhook_events';
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t_wh} ORDER BY id DESC LIMIT %d", (int) $limit ),
			ARRAY_A
		);
	}
}
