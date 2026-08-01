<?php
/**
 * Routes for the invoicing module.
 *
 *   - The hosted pay page at /pay/<token> (a rewrite, not REST).
 *   - The Stripe webhook + the payment-intent starter, both registered under the
 *     theme's single ZDZ_REST_NS constant so the namespace is never typed twice.
 *
 * If the theme (which defines ZDZ_REST_NS) is absent, the REST routes decline to
 * register rather than fatal — consistent with the rest of the bundle.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIC_REST {

	/** Public route base within ZDZ_REST_NS. */
	const ROUTE_BASE = '/invoicing';

	public static function add_rewrites() {
		add_rewrite_rule( '^pay/([^/]+)/?$', 'index.php?zic_pay_token=$matches[1]', 'top' );
		add_filter(
			'query_vars',
			function ( $v ) {
				$v[] = 'zic_pay_token';
				return $v;
			}
		);
	}

	public static function register_routes() {
		if ( ! defined( 'ZDZ_REST_NS' ) ) {
			return;
		}
		register_rest_route(
			ZDZ_REST_NS,
			self::ROUTE_BASE . '/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			ZDZ_REST_NS,
			self::ROUTE_BASE . '/payment-intent',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'payment_intent' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/** URL a hosted pay page posts to when starting a payment. */
	public static function payment_intent_url() {
		if ( ! defined( 'ZDZ_REST_NS' ) ) {
			return '';
		}
		return esc_url_raw( rest_url( ZDZ_REST_NS . self::ROUTE_BASE . '/payment-intent' ) );
	}

	public static function maybe_render_payment_page() {
		$tok = get_query_var( 'zic_pay_token' );
		if ( ! $tok ) {
			return;
		}
		global $wpdb;
		$t_inv = $wpdb->prefix . 'zic_invoices';
		$inv   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t_inv} WHERE token = %s", $tok ), ARRAY_A );
		include ZIC_PLUGIN_DIR . 'templates/payment-page.php';
		exit;
	}

	public static function payment_intent( $req ) {
		$tok = $req->get_param( 'token' );
		global $wpdb;
		$t_inv = $wpdb->prefix . 'zic_invoices';
		$inv   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t_inv} WHERE token = %s", $tok ), ARRAY_A );
		if ( ! $inv ) {
			return new WP_Error( 'nf', 'not found', array( 'status' => 404 ) );
		}
		if ( in_array( $inv['status'], array( 'paid', 'refunded' ), true ) ) {
			return new WP_Error( 'ap', 'already paid', array( 'status' => 409 ) );
		}
		$r = ZIC_Stripe::create_payment_intent( (int) $inv['amount_cents'], $inv['currency'], array( 'invoice_id' => $inv['id'] ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return array( 'client_secret' => $r['client_secret'] );
	}

	public static function webhook( $req ) {
		$sig    = $req->get_header( 'stripe_signature' );
		$raw    = $req->get_body();
		$secret = get_option( 'zic_stripe_webhook_secret' );
		if ( ! $secret || ! self::verify_sig( $raw, $sig, $secret ) ) {
			return new WP_Error( 'bad_sig', 'bad signature', array( 'status' => 400 ) );
		}
		$evt = json_decode( $raw, true );
		if ( ! is_array( $evt ) ) {
			return new WP_Error( 'bad_json', 'bad', array( 'status' => 400 ) );
		}
		global $wpdb;
		$t_wh     = $wpdb->prefix . 'zic_webhook_events';
		$event_id = isset( $evt['id'] ) ? (string) $evt['id'] : '';
		if ( '' === $event_id ) {
			return new WP_Error( 'bad_json', 'bad', array( 'status' => 400 ) );
		}
		// Idempotency: Stripe delivers AT LEAST ONCE; the same event id can arrive
		// repeatedly. With UNIQUE(stripe_event_id), INSERT IGNORE inserts 0 rows for
		// a duplicate — process ONLY when this is a NEW row, so a redelivered
		// payment_intent.succeeded cannot re-fire receipts or flip a refunded
		// invoice back to paid.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$t_wh} (stripe_event_id, type, payload, created_at) VALUES (%s, %s, %s, %s)",
				$event_id,
				( $evt['type'] ?? '' ),
				$raw,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		if ( 0 === $inserted ) {
			return array(
				'received'  => true,
				'duplicate' => true,
			);
		}
		if ( ( $evt['type'] ?? '' ) === 'payment_intent.succeeded' ) {
			$pi         = $evt['data']['object'];
			$invoice_id = isset( $pi['metadata']['invoice_id'] ) ? (int) $pi['metadata']['invoice_id'] : 0;
			if ( $invoice_id ) {
				ZIC_Payment_Engine::record_payment_succeeded( $invoice_id, $pi );
			}
		}
		return array( 'received' => true );
	}

	protected static function verify_sig( $raw, $header, $secret ) {
		if ( ! $header ) {
			return false;
		}
		$parts = array();
		foreach ( explode( ',', $header ) as $p ) {
			$kv = explode( '=', $p, 2 );
			if ( count( $kv ) === 2 ) {
				$parts[ $kv[0] ] = $kv[1];
			}
		}
		if ( empty( $parts['t'] ) || empty( $parts['v1'] ) ) {
			return false;
		}
		$signed   = $parts['t'] . '.' . $raw;
		$expected = hash_hmac( 'sha256', $signed, $secret );
		return hash_equals( $expected, $parts['v1'] );
	}
}
