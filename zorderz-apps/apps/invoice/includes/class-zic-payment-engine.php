<?php
/**
 * Invoice creation + payment-recording for the invoicing module.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIC_Payment_Engine {

	public static function create_and_send( $data ) {
		global $wpdb;
		$t_inv = $wpdb->prefix . 'zic_invoices';
		$token = wp_generate_password( 32, false, false );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$t_inv,
			array(
				'token'                 => $token,
				'client_name'           => sanitize_text_field( $data['client_name'] ?? '' ),
				'client_email'          => sanitize_email( $data['client_email'] ?? '' ),
				'description'           => wp_kses_post( $data['description'] ?? '' ),
				'amount_cents'          => (int) ( $data['amount_cents'] ?? 0 ),
				'currency'              => strtolower( $data['currency'] ?? 'usd' ),
				'freshbooks_invoice_id' => $data['freshbooks_invoice_id'] ?? null,
				'status'                => 'pending',
				'created_at'            => $now,
			)
		);
		$id      = (int) $wpdb->insert_id;
		$pay_url = home_url( '/pay/' . $token );
		if ( ! empty( $data['freshbooks_invoice_id'] ) && class_exists( 'ZIC_FreshBooks' ) ) {
			ZIC_FreshBooks::append_pay_link( $data['freshbooks_invoice_id'], $pay_url );
		}
		return array(
			'id'      => $id,
			'token'   => $token,
			'pay_url' => $pay_url,
		);
	}

	public static function record_payment_succeeded( $invoice_id, $pi ) {
		global $wpdb;
		$t_inv = $wpdb->prefix . 'zic_invoices';
		$t_pay = $wpdb->prefix . 'zic_payments';
		$now   = gmdate( 'Y-m-d H:i:s' );
		$inv   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t_inv} WHERE id = %d", (int) $invoice_id ), ARRAY_A );
		if ( ! $inv ) {
			return;
		}
		$wpdb->update(
			$t_inv,
			array(
				'status'            => 'paid',
				'paid_at'           => $now,
				'payment_intent_id' => $pi['id'],
			),
			array( 'id' => $invoice_id )
		);
		$wpdb->replace(
			$t_pay,
			array(
				'invoice_id'            => $invoice_id,
				'payment_intent_id'     => $pi['id'],
				'amount_cents'          => (int) ( $pi['amount_received'] ?? $pi['amount'] ?? 0 ),
				'application_fee_cents'  => (int) ( $pi['application_fee_amount'] ?? 0 ),
				'currency'              => $pi['currency'] ?? 'usd',
				'status'                => 'succeeded',
				'created_at'            => $now,
			)
		);
		do_action( 'zic_payment_succeeded', array_merge( $inv, array( 'status' => 'paid', 'paid_at' => $now ) ), $pi );
	}
}
