<?php
/**
 * Transactional email for the invoicing module.
 *
 * Sender identity is left to WordPress / the Business Profile mail filters — no
 * from-name or address is hardcoded here. The merchant notification discloses
 * the retained platform fee only when one was actually charged.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIC_Mailer {

	public static function send_client_receipt( $invoice, $pi ) {
		if ( empty( $invoice['client_email'] ) ) {
			return;
		}
		$amt     = number_format( ( (int) $invoice['amount_cents'] ) / 100, 2 );
		$subject = 'Payment Received — Thank You';
		$body    = 'Hi ' . $invoice['client_name'] . ",\n\n";
		$body   .= "Thank you for your payment. We appreciate your business.\n\n";
		$body   .= 'Amount: $' . $amt . ' ' . strtoupper( $invoice['currency'] ) . "\n";
		$body   .= 'Invoice: #' . $invoice['id'] . "\n";
		wp_mail( $invoice['client_email'], $subject, $body );
	}

	public static function send_merchant_notification( $invoice, $pi ) {
		$to = get_option( 'zic_notify_email' ) ?: get_option( 'admin_email' );
		if ( ! $to ) {
			return;
		}
		$amt     = number_format( ( (int) $invoice['amount_cents'] ) / 100, 2 );
		$subject = 'Payment: $' . $amt . ' — Invoice #' . $invoice['id'];
		$body    = "A payment was received.\n\n";
		$body   .= 'Invoice: #' . $invoice['id'] . "\n";
		$body   .= 'Client: ' . $invoice['client_name'] . ' <' . $invoice['client_email'] . ">\n";
		$body   .= 'Amount: $' . $amt . "\n";

		// Disclose the retained platform fee only when it was actually charged
		// (a fee applies only with a connected account and a non-zero rate).
		$rate = zic_platform_fee_rate();
		if ( $rate > 0 && class_exists( 'ZIC_Stripe' ) && ZIC_Stripe::connected_account_id() ) {
			$fee     = number_format( ( (int) $invoice['amount_cents'] ) * $rate / 100, 2 );
			$percent = rtrim( rtrim( number_format( $rate * 100, 3 ), '0' ), '.' );
			$body   .= 'Platform fee (' . $percent . '%): $' . $fee . "\n";
		}
		wp_mail( $to, $subject, $body );
	}
}
