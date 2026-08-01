<?php
/**
 * FreshBooks REST client for the invoicing module (pay-link injection).
 *
 * This app keeps its OWN FreshBooks connection (credentials + token) and does
 * NOT read the theme's shared FreshBooks account — the two are separate provider
 * instances by design, so refreshing one never clobbers the other. The
 * FreshBooks API host is a provider endpoint, not business identity.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIC_FreshBooks {

	const API_BASE = 'https://api.freshbooks.com';

	public static function account_id() {
		return (string) get_option( 'zic_freshbooks_account_id', '' );
	}

	public static function token() {
		if ( class_exists( 'ZIC_FreshBooks_OAuth' ) ) {
			return ZIC_FreshBooks_OAuth::live_token();
		}
		return get_option( 'zic_freshbooks_token', '' );
	}

	public static function is_ready() {
		return self::account_id() && self::token();
	}

	/** Append a "pay here" line item pointing at the hosted pay page. */
	public static function append_pay_link( $fb_invoice_id, $pay_url ) {
		if ( ! self::is_ready() || ! $fb_invoice_id ) {
			return false;
		}
		$text    = 'Click Here to Pay: ' . $pay_url;
		$payload = array(
			'invoice' => array(
				'lines' => array(
					array(
						'type'        => 0,
						'description' => $text,
						'qty'         => '0',
						'unit_cost'   => array(
							'amount' => '0.00',
							'code'   => 'USD',
						),
						'taxName1'    => '',
						'taxAmount1'  => 0,
					),
				),
			),
		);
		$path = '/accounting/account/' . rawurlencode( self::account_id() ) . '/invoices/invoices/' . intval( $fb_invoice_id );
		return self::request( 'PUT', $path, $payload );
	}

	public static function request( $method, $path, $body = null, $retry = true ) {
		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array(
				'Authorization' => 'Bearer ' . self::token(),
				'Api-Version'   => 'alpha',
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$resp = wp_remote_request( self::API_BASE . $path, $args );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		if ( 401 === $code && $retry && class_exists( 'ZIC_FreshBooks_OAuth' ) ) {
			if ( ZIC_FreshBooks_OAuth::handle_unauthorized() ) {
				return self::request( $method, $path, $body, false );
			}
		}
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'fb_http', 'HTTP ' . $code . ' :: ' . substr( wp_remote_retrieve_body( $resp ), 0, 500 ) );
		}
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		return is_array( $json ) ? $json : array();
	}
}
