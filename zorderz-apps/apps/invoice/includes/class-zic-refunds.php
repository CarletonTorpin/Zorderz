<?php
/**
 * Admin-initiated refunds for the invoicing module.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIC_Refunds {

	public static function handle_refund_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'nope' );
		}
		check_admin_referer( 'zic_refund' );
		global $wpdb;
		$t_inv        = $wpdb->prefix . 'zic_invoices';
		$t_pay        = $wpdb->prefix . 'zic_payments';
		$invoice_id   = (int) ( $_POST['invoice_id'] ?? 0 );
		$amount_usd   = (float) ( $_POST['amount_usd'] ?? 0 );
		$amount_cents = (int) round( $amount_usd * 100 );
		$inv          = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t_inv} WHERE id = %d", $invoice_id ), ARRAY_A );
		if ( ! $inv || ! $inv['payment_intent_id'] ) {
			wp_die( 'invoice not refundable' );
		}
		$max = max( 0, (int) $inv['amount_cents'] - (int) $inv['refunded_cents'] );
		if ( $amount_cents <= 0 || $amount_cents > $max ) {
			wp_die( 'invalid amount' );
		}
		$r = ZIC_Stripe::create_refund( $inv['payment_intent_id'], $amount_cents );
		if ( is_wp_error( $r ) ) {
			wp_die( 'refund error: ' . esc_html( $r->get_error_message() ) );
		}
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t_inv} SET refunded_cents = refunded_cents + %d, status = CASE WHEN refunded_cents + %d >= amount_cents THEN 'refunded' ELSE status END WHERE id = %d",
				$amount_cents,
				$amount_cents,
				$invoice_id
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t_pay} SET refunded_cents = refunded_cents + %d WHERE invoice_id = %d",
				$amount_cents,
				$invoice_id
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=zic_dashboard' ) );
		exit;
	}
}
