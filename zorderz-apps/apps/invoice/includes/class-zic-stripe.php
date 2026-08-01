<?php
/**
 * Thin Stripe REST client for the invoicing module.
 *
 * PLATFORM FEE (generalized): the old baked 0.5% constant is gone. The fee comes
 * from zic_platform_fee_rate() — the disclosed admin option, default 0 / off. A
 * fee is only ever charged as a Stripe Connect application fee, and only when a
 * connected (merchant) account is configured; with no connected account the
 * module makes a plain charge on the site's own Stripe account and no fee
 * applies. Stripe's own API host is a provider endpoint, not business identity.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIC_Stripe {

	const API = 'https://api.stripe.com/v1';

	public static function secret() {
		return get_option( 'zic_stripe_secret', '' );
	}

	public static function connected_account_id() {
		return get_option( 'zic_stripe_connected_account', '' );
	}

	/**
	 * The application (platform) fee in cents for a given charge amount.
	 *
	 * Clamped to never meet or exceed the charge (Stripe rejects a fee >= amount
	 * on a destination charge). Zero whenever the rate is 0 or amount is 0.
	 */
	public static function platform_fee_cents( $amount_cents ) {
		$amount_cents = (int) $amount_cents;
		$fee          = (int) round( $amount_cents * zic_platform_fee_rate() );
		if ( $fee < 0 ) {
			$fee = 0;
		}
		if ( $amount_cents > 0 && $fee >= $amount_cents ) {
			$fee = $amount_cents - 1;
		}
		return $fee;
	}

	public static function create_payment_intent( $amount_cents, $currency, $metadata = array() ) {
		$body = array(
			'amount'                              => (int) $amount_cents,
			'currency'                            => $currency ?: 'usd',
			'automatic_payment_methods[enabled]'  => 'true',
		);

		// Stripe Connect is optional. When a connected account is set, route the
		// funds to it (destination charge) and retain the disclosed platform fee
		// on this site's account. When it is not set, this is a plain charge and
		// no application fee is possible.
		$dest = self::connected_account_id();
		if ( $dest ) {
			$body['transfer_data[destination]'] = $dest;
			$fee = self::platform_fee_cents( (int) $amount_cents );
			if ( $fee > 0 ) {
				$body['application_fee_amount'] = $fee;
			}
		}

		foreach ( (array) $metadata as $k => $v ) {
			$body[ "metadata[$k]" ] = $v;
		}
		return self::request( 'POST', '/payment_intents', $body );
	}

	public static function create_refund( $payment_intent_id, $amount_cents ) {
		$body = array(
			'payment_intent' => $payment_intent_id,
			'amount'         => (int) $amount_cents,
		);
		// The transfer-reversal / fee-refund flags are only valid for a Connect
		// destination charge. Send them only when a connected account is in play.
		if ( self::connected_account_id() ) {
			$body['refund_application_fee'] = 'true';
			$body['reverse_transfer']       = 'true';
		}
		return self::request( 'POST', '/refunds', $body );
	}

	public static function request( $method, $path, $body = null ) {
		$sec = self::secret();
		if ( ! $sec ) {
			return new WP_Error( 'no_secret', 'Stripe secret not configured' );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array(
				'Authorization' => 'Bearer ' . $sec,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
		);
		if ( null !== $body ) {
			$args['body'] = http_build_query( $body, '', '&' );
		}
		$r = wp_remote_request( self::API . $path, $args );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$code = wp_remote_retrieve_response_code( $r );
		$json = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'stripe_http', 'HTTP ' . $code . ' :: ' . substr( wp_remote_retrieve_body( $r ), 0, 400 ) );
		}
		return is_array( $json ) ? $json : array();
	}
}
